<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

api_start();
$db = db();

/** Shift length in hours; an end time at or before the start time means the shift ends the next day. */
function shift_length_hours(string $start, string $end): float
{
    $toMinutes = static function (string $time): int {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    };
    $minutes = $toMinutes($end) - $toMinutes($start);
    if ($minutes <= 0) {
        $minutes += 24 * 60;
    }
    return $minutes / 60;
}

function shift_values(array $data): array
{
    $time = static function (string $field, string $label) use ($data): string {
        $value = trim((string) ($data[$field] ?? ''));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value)) {
            throw new ApiException("$label must be a time (HH:MM).", 422);
        }
        return substr($value, 0, 5);
    };
    $values = [
        'name' => require_text($data, 'name', 'Shift name', 100),
        'start_time' => $time('start_time', 'Start time'),
        'end_time' => $time('end_time', 'End time'),
        'active_days' => (int) optional_number($data, 'active_days', 'Active days per week', 1, 7),
        'effective_hours' => optional_number($data, 'effective_hours', 'Effective hours', 0.5, 24),
        'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
    ];
    if ($values['active_days'] < 1) {
        throw new ApiException('Active days per week is required (1-7).', 422);
    }
    $length = shift_length_hours($values['start_time'], $values['end_time']);
    if ($values['effective_hours'] > $length) {
        throw new ApiException(sprintf('Effective hours cannot exceed the shift length (%s h).', round($length, 2)), 422);
    }
    return $values;
}

switch (request_method()) {
    case 'GET':
        $rows = $db->query(
            "SELECT s.id, s.name, TIME_FORMAT(s.start_time, '%H:%i') AS start_time, TIME_FORMAT(s.end_time, '%H:%i') AS end_time,
                    s.active_days, s.effective_hours, s.notes,
                    (SELECT COUNT(*) FROM positions p WHERE p.shift_id = s.id) AS position_count
             FROM shifts s
             ORDER BY s.start_time, s.name"
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['effective_hours'] = (float) $row['effective_hours'];
            $row['length_hours'] = shift_length_hours($row['start_time'], $row['end_time']);
        }
        unset($row);
        json_response($rows);

    case 'POST':
        $values = shift_values(request_json());
        $db->prepare(
            'INSERT INTO shifts (name, start_time, end_time, active_days, effective_hours, notes) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(array_values($values));
        json_response(['id' => (int) $db->lastInsertId()], 201);

    case 'PATCH':
        $id = query_id('id', true);
        assert_exists('shifts', $id, 'Shift');
        $values = shift_values(request_json());
        $db->prepare(
            'UPDATE shifts SET name = ?, start_time = ?, end_time = ?, active_days = ?, effective_hours = ?, notes = ? WHERE id = ?'
        )->execute([...array_values($values), $id]);
        json_response(['id' => $id]);

    case 'DELETE':
        $id = query_id('id', true);
        assert_exists('shifts', $id, 'Shift');
        $stmt = $db->prepare('SELECT COUNT(*) FROM positions WHERE shift_id = ?');
        $stmt->execute([$id]);
        $used = (int) $stmt->fetchColumn();
        if ($used > 0) {
            json_error("This shift is assigned to $used position(s). Move them to another shift first.", 409);
        }
        $db->prepare('DELETE FROM shifts WHERE id = ?')->execute([$id]);
        json_response(['deleted' => $id]);

    default:
        json_error('Method not allowed.', 405);
}
