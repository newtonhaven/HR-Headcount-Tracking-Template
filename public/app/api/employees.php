<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

api_start();
$db = db();

/** Viewers see where people work, not their personal details (analytics only use them in aggregate). */
function for_role(array $employee): array
{
    if (!is_admin()) {
        unset($employee['birth_date'], $employee['gender']);
    }
    return $employee;
}

const EMPLOYEE_SELECT = 'SELECT e.id, e.employee_no, e.first_name, e.last_name, e.gender, e.birth_date, e.position_id,
        e.employment_type, e.hire_date, e.termination_date, e.termination_reason,
        p.title AS position_title, s.name AS shift_name, u.id AS unit_id, u.name AS unit_name,
        g.id AS group_id, g.name AS group_name
    FROM employees e
    LEFT JOIN positions p ON p.id = e.position_id
    LEFT JOIN shifts s ON s.id = p.shift_id
    LEFT JOIN units u ON u.id = p.unit_id
    LEFT JOIN unit_groups g ON g.id = u.group_id';

const EMPLOYMENT_TYPES = ['permanent', 'contract', 'temporary', 'intern'];
const GENDERS = ['female', 'male', 'other'];

function find_employee(PDO $db, int $id): array
{
    $stmt = $db->prepare(EMPLOYEE_SELECT . ' WHERE e.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new ApiException('Employee not found.', 404);
    }
    return $row;
}

function employee_values(array $data): array
{
    $values = [
        'first_name' => require_text($data, 'first_name', 'First name', 80),
        'last_name' => require_text($data, 'last_name', 'Last name', 80),
        'gender' => ($data['gender'] ?? '') ?: null,
        'birth_date' => optional_date($data, 'birth_date', 'Birth date'),
        'position_id' => ($data['position_id'] ?? '') === '' || ($data['position_id'] ?? null) === null ? null : (int) $data['position_id'],
        'employment_type' => $data['employment_type'] ?? 'permanent',
        'hire_date' => optional_date($data, 'hire_date', 'Hire date'),
        'termination_date' => optional_date($data, 'termination_date', 'Termination date'),
        'termination_reason' => trim((string) ($data['termination_reason'] ?? '')) ?: null,
    ];
    if ($values['gender'] !== null && !in_array($values['gender'], GENDERS, true)) {
        throw new ApiException('Unknown gender value.', 422);
    }
    if (!in_array($values['employment_type'], EMPLOYMENT_TYPES, true)) {
        throw new ApiException('Unknown employment type.', 422);
    }
    if ($values['position_id'] !== null) {
        assert_exists('positions', $values['position_id'], 'Position');
    }
    if ($values['hire_date'] === null) {
        throw new ApiException('Hire date is required.', 422);
    }
    if ($values['birth_date'] !== null && $values['birth_date'] >= $values['hire_date']) {
        throw new ApiException('Birth date must be before the hire date.', 422);
    }
    if ($values['termination_date'] !== null && $values['termination_date'] < $values['hire_date']) {
        throw new ApiException('Termination date cannot be before the hire date.', 422);
    }
    if ($values['termination_date'] === null) {
        $values['termination_reason'] = null;
    }
    if ($values['termination_reason'] !== null && mb_strlen($values['termination_reason']) > 100) {
        throw new ApiException('Termination reason must be at most 100 characters.', 422);
    }
    return $values;
}

switch (request_method()) {
    case 'GET':
        if ($id = query_id('id')) {
            json_response(for_role(find_employee($db, $id)));
        }

        $where = [];
        $params = [];
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR e.employee_no LIKE ? OR p.title LIKE ?)";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        foreach (['group_id' => 'g.id', 'unit_id' => 'u.id', 'position_id' => 'e.position_id'] as $key => $column) {
            if ($value = query_id($key)) {
                $where[] = "$column = ?";
                $params[] = $value;
            }
        }
        if (($_GET['unassigned'] ?? '') === '1') {
            $where[] = 'e.position_id IS NULL';
        }
        $type = $_GET['type'] ?? '';
        if (in_array($type, EMPLOYMENT_TYPES, true)) {
            $where[] = 'e.employment_type = ?';
            $params[] = $type;
        }
        $status = $_GET['status'] ?? 'active';
        if ($status === 'active') {
            $where[] = StaffingService::ACTIVE_EMPLOYEE_SQL;
        } elseif ($status === 'terminated') {
            $where[] = 'NOT ' . StaffingService::ACTIVE_EMPLOYEE_SQL;
        }

        $sorts = [
            'name' => 'e.last_name %1$s, e.first_name %1$s',
            'employee_no' => 'e.employee_no %s',
            'position' => 'p.title %1$s, e.last_name',
            'unit' => 'g.name %1$s, u.name %1$s, e.last_name',
            'hire_date' => 'e.hire_date %s',
            'termination_date' => 'e.termination_date %s',
        ];
        $sort = $sorts[$_GET['sort'] ?? ''] ?? $sorts['name'];
        $dir = strtolower($_GET['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC';
        $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $count = $db->prepare('SELECT COUNT(*) FROM (' . EMPLOYEE_SELECT . $whereSql . ') x');
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $db->prepare(EMPLOYEE_SELECT . $whereSql . ' ORDER BY ' . sprintf($sort, $dir)
            . sprintf(' LIMIT %d OFFSET %d', $perPage, ($page - 1) * $perPage));
        $stmt->execute($params);
        json_response(['rows' => array_map('for_role', $stmt->fetchAll()), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);

    case 'POST':
        $data = request_json();
        $values = employee_values($data);
        $employeeNo = trim((string) ($data['employee_no'] ?? ''));
        if ($employeeNo === '') {
            $next = (int) $db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(employee_no, 2) AS UNSIGNED)), 10000) + 1 FROM employees WHERE employee_no REGEXP '^E[0-9]+$'")->fetchColumn();
            $employeeNo = sprintf('E%05d', $next);
        } elseif (!preg_match('/^[A-Za-z0-9-]{1,20}$/', $employeeNo)) {
            json_error('Employee number may contain letters, digits and dashes (max 20).', 422);
        }
        $values = ['employee_no' => $employeeNo] + $values;
        $columns = array_keys($values);
        $db->prepare('INSERT INTO employees (' . implode(', ', $columns) . ') VALUES (?' . str_repeat(', ?', count($columns) - 1) . ')')
            ->execute(array_values($values));
        json_response(find_employee($db, (int) $db->lastInsertId()), 201);

    case 'PATCH':
        // Accepts the full form or a partial payload (e.g. only termination_date/termination_reason).
        $id = query_id('id', true);
        $current = find_employee($db, $id);
        $values = employee_values(array_merge($current, request_json()));
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($values)));
        $db->prepare("UPDATE employees SET $set WHERE id = ?")->execute([...array_values($values), $id]);
        json_response(find_employee($db, $id));

    case 'DELETE':
        $id = query_id('id', true);
        find_employee($db, $id);
        $db->prepare('DELETE FROM employees WHERE id = ?')->execute([$id]);
        json_response(['deleted' => $id]);

    default:
        json_error('Method not allowed.', 405);
}
