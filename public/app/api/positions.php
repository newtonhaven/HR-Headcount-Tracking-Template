<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

api_start();
$db = db();

/** Validate the shift_id field: null/empty means "no shift". */
function shift_from(array $data): ?int
{
    $value = $data['shift_id'] ?? null;
    if ($value === null || $value === '') {
        return null;
    }
    $id = require_int($data, 'shift_id', 'Shift');
    assert_exists('shifts', $id, 'Shift');
    return $id;
}

switch (request_method()) {
    case 'GET':
        // ?unit_id=... (required) and optional ?shift_ids=1,2
        $unitId = query_id('unit_id', true);
        $shiftIds = query_id_list('shift_ids');
        $sql = 'SELECT p.id, p.unit_id, p.title, p.shift_id, s.name AS shift_name, s.start_time, s.end_time,
                       (SELECT COUNT(*) FROM tasks t WHERE t.position_id = p.id) AS task_count,
                       (SELECT COUNT(*) FROM employees e WHERE e.position_id = p.id AND ' . StaffingService::ACTIVE_EMPLOYEE_SQL . ') AS headcount
                FROM positions p
                LEFT JOIN shifts s ON s.id = p.shift_id
                WHERE p.unit_id = ?';
        $params = [$unitId];
        if ($shiftIds) {
            $sql .= ' AND p.shift_id IN (' . implode(',', array_fill(0, count($shiftIds), '?')) . ')';
            array_push($params, ...$shiftIds);
        }
        $stmt = $db->prepare($sql . ' ORDER BY p.title, s.start_time');
        $stmt->execute($params);
        json_response($stmt->fetchAll());

    case 'POST':
        $data = request_json();
        $unitId = require_int($data, 'unit_id', 'Unit');
        assert_exists('units', $unitId, 'Unit');
        $title = require_text($data, 'title', 'Position title');
        $shiftId = shift_from($data);
        $db->prepare('INSERT INTO positions (unit_id, title, shift_id) VALUES (?, ?, ?)')->execute([$unitId, $title, $shiftId]);
        json_response(['id' => (int) $db->lastInsertId()], 201);

    case 'PATCH':
        // Rename ({title}) and/or change shift ({shift_id}).
        $id = query_id('id', true);
        assert_exists('positions', $id, 'Position');
        $data = request_json();
        $fields = [];
        $params = [];
        if (array_key_exists('title', $data)) {
            $fields[] = 'title = ?';
            $params[] = require_text($data, 'title', 'Position title');
        }
        if (array_key_exists('shift_id', $data)) {
            $fields[] = 'shift_id = ?';
            $params[] = shift_from($data);
        }
        if (!$fields) {
            json_error('Nothing to update.', 422);
        }
        $params[] = $id;
        $db->prepare('UPDATE positions SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        json_response(['id' => $id]);

    case 'DELETE':
        $id = query_id('id', true);
        assert_exists('positions', $id, 'Position');
        $db->prepare('DELETE FROM positions WHERE id = ?')->execute([$id]);
        json_response(['deleted' => $id]);

    default:
        json_error('Method not allowed.', 405);
}
