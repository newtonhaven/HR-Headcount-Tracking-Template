<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

api_start();
$db = db();

switch (request_method()) {
    case 'GET':
        // ?group_id=... lists one group's units; without it every unit is returned (for filters).
        $groupId = query_id('group_id');
        $sql = 'SELECT u.id, u.group_id, u.name, g.name AS group_name,
                       (SELECT COUNT(*) FROM positions p WHERE p.unit_id = u.id) AS position_count,
                       (SELECT COUNT(*) FROM employees e JOIN positions p ON p.id = e.position_id
                         WHERE p.unit_id = u.id AND ' . StaffingService::ACTIVE_EMPLOYEE_SQL . ') AS headcount
                FROM units u
                JOIN unit_groups g ON g.id = u.group_id'
            . ($groupId ? ' WHERE u.group_id = ?' : '')
            . ' ORDER BY g.name, u.name';
        $stmt = $db->prepare($sql);
        $stmt->execute($groupId ? [$groupId] : []);
        json_response($stmt->fetchAll());

    case 'POST':
        $data = request_json();
        $groupId = require_int($data, 'group_id', 'Unit group');
        assert_exists('unit_groups', $groupId, 'Unit group');
        $name = require_text($data, 'name', 'Unit name');
        $db->prepare('INSERT INTO units (group_id, name) VALUES (?, ?)')->execute([$groupId, $name]);
        json_response(['id' => (int) $db->lastInsertId(), 'group_id' => $groupId, 'name' => $name], 201);

    case 'PATCH':
        // Rename ({name}) and/or move to another group ({group_id}).
        $id = query_id('id', true);
        assert_exists('units', $id, 'Unit');
        $data = request_json();
        $fields = [];
        $params = [];
        if (array_key_exists('name', $data)) {
            $fields[] = 'name = ?';
            $params[] = require_text($data, 'name', 'Unit name');
        }
        if (array_key_exists('group_id', $data)) {
            $groupId = require_int($data, 'group_id', 'Target group');
            assert_exists('unit_groups', $groupId, 'Target group');
            $fields[] = 'group_id = ?';
            $params[] = $groupId;
        }
        if (!$fields) {
            json_error('Nothing to update.', 422);
        }
        $params[] = $id;
        $db->prepare('UPDATE units SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        json_response(['id' => $id]);

    case 'DELETE':
        $id = query_id('id', true);
        assert_exists('units', $id, 'Unit');
        $db->prepare('DELETE FROM units WHERE id = ?')->execute([$id]);
        json_response(['deleted' => $id]);

    default:
        json_error('Method not allowed.', 405);
}
