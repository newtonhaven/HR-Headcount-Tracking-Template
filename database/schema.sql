-- Norm Staffing database schema (MariaDB 10.6+ / MySQL 8+)
--
-- Hierarchy:  unit_groups -> units -> positions -> tasks
--             shifts are assigned to positions, employees are assigned to positions.
-- users are the people who sign in to the app (create the first one with bin/create-user.php).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS login_attempts, users, employees, tasks, positions, shifts, units, unit_groups;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE unit_groups (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_unit_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE units (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id    INT UNSIGNED NOT NULL,
    name        VARCHAR(150) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_units_group_name (group_id, name),
    CONSTRAINT fk_units_group FOREIGN KEY (group_id) REFERENCES unit_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shifts (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name             VARCHAR(100) NOT NULL,
    start_time       TIME NOT NULL,
    end_time         TIME NOT NULL,
    active_days      TINYINT UNSIGNED NOT NULL DEFAULT 5,      -- days per week the shift runs
    effective_hours  DECIMAL(4,2) NOT NULL DEFAULT 7.50,       -- productive hours per person per shift
    notes            TEXT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shifts_name (name),
    CONSTRAINT chk_shifts_active_days CHECK (active_days BETWEEN 1 AND 7),
    CONSTRAINT chk_shifts_effective_hours CHECK (effective_hours > 0 AND effective_hours <= 24)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE positions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    unit_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(150) NOT NULL,
    shift_id    INT UNSIGNED NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- the same title may exist once per shift (e.g. "Ward Nurse" on day and night shift)
    UNIQUE KEY uq_positions_unit_title_shift (unit_id, title, shift_id),
    KEY idx_positions_shift (shift_id),
    CONSTRAINT fk_positions_unit FOREIGN KEY (unit_id) REFERENCES units (id) ON DELETE CASCADE,
    CONSTRAINT fk_positions_shift FOREIGN KEY (shift_id) REFERENCES shifts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tasks (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    position_id             INT UNSIGNED NOT NULL,
    name                    VARCHAR(255) NOT NULL,
    unit_of_measure         VARCHAR(50) NULL,                 -- what is counted: patient, report, call ...
    duration_min            DECIMAL(7,2) NOT NULL DEFAULT 0,  -- minutes per item
    duration_max            DECIMAL(7,2) NOT NULL DEFAULT 0,
    daily_min               DECIMAL(7,2) NOT NULL DEFAULT 0,  -- items per day
    daily_max               DECIMAL(7,2) NOT NULL DEFAULT 0,
    weekly_min              DECIMAL(7,2) NOT NULL DEFAULT 0,  -- items per week
    weekly_max              DECIMAL(7,2) NOT NULL DEFAULT 0,
    monthly_min             DECIMAL(7,2) NOT NULL DEFAULT 0,  -- items per month
    monthly_max             DECIMAL(7,2) NOT NULL DEFAULT 0,
    personal_allowance_pct  DECIMAL(5,2) NOT NULL DEFAULT 0,  -- % added for personal needs
    rest_allowance_pct      DECIMAL(5,2) NOT NULL DEFAULT 0,  -- % added for fatigue / rest
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tasks_position (position_id),
    CONSTRAINT fk_tasks_position FOREIGN KEY (position_id) REFERENCES positions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employees (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_no         VARCHAR(20) NOT NULL,
    first_name          VARCHAR(80) NOT NULL,
    last_name           VARCHAR(80) NOT NULL,
    gender              ENUM('female', 'male', 'other') NULL,
    birth_date          DATE NULL,
    position_id         INT UNSIGNED NULL,
    employment_type     ENUM('permanent', 'contract', 'temporary', 'intern') NOT NULL DEFAULT 'permanent',
    hire_date           DATE NOT NULL,
    termination_date    DATE NULL,
    termination_reason  VARCHAR(100) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_employees_no (employee_no),
    KEY idx_employees_position (position_id),
    KEY idx_employees_hire (hire_date),
    KEY idx_employees_termination (termination_date),
    KEY idx_employees_name (last_name, first_name),
    CONSTRAINT fk_employees_position FOREIGN KEY (position_id) REFERENCES positions (id) ON DELETE SET NULL,
    CONSTRAINT chk_employees_dates CHECK (termination_date IS NULL OR termination_date >= hire_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username         VARCHAR(50) NOT NULL,
    full_name        VARCHAR(100) NOT NULL,
    role             ENUM('admin', 'viewer') NOT NULL DEFAULT 'viewer',
    password_hash    VARCHAR(255) NOT NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    session_version  INT UNSIGNED NOT NULL DEFAULT 1,    -- bumped on password change to end other sessions
    last_login_at    TIMESTAMP NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed sign-ins, used to slow down password guessing (see attempt_login() in src/auth.php).
CREATE TABLE login_attempts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(50) NOT NULL,
    ip_address    VARCHAR(45) NOT NULL,
    attempted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_user (username, attempted_at),
    KEY idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
