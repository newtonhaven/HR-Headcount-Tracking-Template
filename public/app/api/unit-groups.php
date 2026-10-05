<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

api_start();
$db = db();

switch (request_method()) {
    case 'GET':
        $rows = $db->query(
            'SELECT g.id, g.name,
                    COUNT(DISTINCT u.id) AS unit_count,
                    COUNT(DISTINCT p.id) AS position_count
             FROM unit_groups g
             LEFT JOIN units u ON u.group_id = g.id
             LEFT JOIN positions p ON p.unit_id = u.id
             GROUP BY g.id, g.name
             ORDER BY g.name'
        )->fetchAll();
        json_response($rows);

    case 'POST':
        $name = require_text(request_json(), 'name', 'Group name');
        $db->prepare('INSERT INTO unit_groups (name) VALUES (?)')->execute([$name]);
        json_response(['id' => (int) $db->lastInsertId(), 'name' => $name], 201);

    case 'PATCH':
        $id = query_id('id', true);
        assert_exists('unit_groups', $id, 'Unit group');
        $name = require_text(request_json(), 'name', 'Group name');
        $db->prepare('UPDATE unit_groups SET name = ? WHERE id = ?')->execute([$name, $id]);
        json_response(['id' => $id, 'name' => $name]);

    case 'DELETE':
        $id = query_id('id', true);
        assert_exists('unit_groups', $id, 'Unit group');
        // Units, positions and tasks cascade; employees in those positions become unassigned.
        $db->prepare('DELETE FROM unit_groups WHERE id = ?')->execute([$id]);
        json_response(['deleted' => $id]);

    default:
        json_error('Method not allowed.', 405);
}
