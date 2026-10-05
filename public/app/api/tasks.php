<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

api_start();
$db = db();

const TASK_NUMBER_FIELDS = [
    'duration_min', 'duration_max', 'daily_min', 'daily_max', 'weekly_min', 'weekly_max',
    'monthly_min', 'monthly_max', 'personal_allowance_pct', 'rest_allowance_pct',
];

/** Validate a task payload and return the column values. */
function task_values(array $data): array
{
    $values = [
        'name' => require_text($data, 'name', 'Task name', 255),
        'unit_of_measure' => trim((string) ($data['unit_of_measure'] ?? '')) ?: null,
    ];
    if ($values['unit_of_measure'] !== null && mb_strlen($values['unit_of_measure']) > 50) {
        throw new ApiException('Unit of measure must be at most 50 characters.', 422);
    }

    $labels = ['duration' => 'Duration', 'daily' => 'Daily frequency', 'weekly' => 'Weekly frequency', 'monthly' => 'Monthly frequency'];
    foreach ($labels as $prefix => $label) {
        $min = optional_number($data, "{$prefix}_min", "$label (min)", 0, 100000);
        $max = optional_number($data, "{$prefix}_max", "$label (max)", 0, 100000);
        if ($max === 0.0 && $min > 0) {
            $max = $min; // a single value means a fixed amount
        }
        if ($max < $min) {
            throw new ApiException("$label: max must be greater than or equal to min.", 422);
        }
        $values["{$prefix}_min"] = $min;
        $values["{$prefix}_max"] = $max;
    }
    if ($values['duration_max'] <= 0) {
        throw new ApiException('Duration per item is required.', 422);
    }
    if ($values['daily_max'] + $values['weekly_max'] + $values['monthly_max'] <= 0) {
        throw new ApiException('Enter at least one frequency (daily, weekly or monthly).', 422);
    }
    $values['personal_allowance_pct'] = optional_number($data, 'personal_allowance_pct', 'Personal allowance', 0, 100);
    $values['rest_allowance_pct'] = optional_number($data, 'rest_allowance_pct', 'Rest allowance', 0, 100);
    return $values;
}

switch (request_method()) {
    case 'GET':
        $positionId = query_id('position_id', true);
        $positions = StaffingService::positions($db, ['position_id' => $positionId]);
        if (!$positions) {
            json_error('Position not found.', 404);
        }
        $position = $positions[0];
        $activeDays = $position['shift_id'] ? (int) $position['active_days'] : Workload::DEFAULT_SHIFT['active_days'];
        $tasks = array_map(function (array $task) use ($activeDays) {
            foreach (TASK_NUMBER_FIELDS as $field) {
                $task[$field] = (float) $task[$field];
            }
            return StaffingService::round($task + Workload::task($task, $activeDays));
        }, $position['tasks']);
        json_response(['position' => StaffingService::present($position), 'tasks' => $tasks]);

    case 'POST':
        $data = request_json();
        if (($_GET['action'] ?? '') === 'copy') {
            // Copy every task of one position to another, optionally replacing the target's tasks.
            $from = require_int($data, 'from_position_id', 'Source position');
            $to = require_int($data, 'to_position_id', 'Target position');
            assert_exists('positions', $from, 'Source position');
            assert_exists('positions', $to, 'Target position');
            if ($from === $to) {
                json_error('Choose a different position to copy from.', 422);
            }
            $columns = 'name, unit_of_measure, ' . implode(', ', TASK_NUMBER_FIELDS);
            $db->beginTransaction();
            if (!empty($data['replace'])) {
                $db->prepare('DELETE FROM tasks WHERE position_id = ?')->execute([$to]);
            }
            $stmt = $db->prepare("INSERT INTO tasks (position_id, $columns) SELECT ?, $columns FROM tasks WHERE position_id = ?");
            $stmt->execute([$to, $from]);
            $db->commit();
            json_response(['copied' => $stmt->rowCount()], 201);
        }

        $positionId = require_int($data, 'position_id', 'Position');
        assert_exists('positions', $positionId, 'Position');
        $values = task_values($data);
        $columns = array_keys($values);
        $db->prepare(
            'INSERT INTO tasks (position_id, ' . implode(', ', $columns) . ') VALUES (?' . str_repeat(', ?', count($columns)) . ')'
        )->execute([$positionId, ...array_values($values)]);
        json_response(['id' => (int) $db->lastInsertId()], 201);

    case 'PATCH':
        $id = query_id('id', true);
        assert_exists('tasks', $id, 'Task');
        $values = task_values(request_json());
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($values)));
        $db->prepare("UPDATE tasks SET $set WHERE id = ?")->execute([...array_values($values), $id]);
        json_response(['id' => $id]);

    case 'DELETE':
        $id = query_id('id', true);
        assert_exists('tasks', $id, 'Task');
        $db->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
        json_response(['deleted' => $id]);

    default:
        json_error('Method not allowed.', 405);
}
