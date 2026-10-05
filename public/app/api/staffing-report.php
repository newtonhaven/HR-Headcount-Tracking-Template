<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

// Norm vs. actual staffing, drilled down organization -> group -> unit -> position.
// Query: one of group_id / unit_id / position_id (most specific wins), optional shift_ids=1,2

api_start();
$db = db();

$filters = [
    'group_id' => query_id('group_id'),
    'unit_id' => query_id('unit_id'),
    'position_id' => query_id('position_id'),
    'shift_ids' => query_id_list('shift_ids'),
];
$level = $filters['position_id'] ? 'position' : ($filters['unit_id'] ? 'unit' : ($filters['group_id'] ? 'group' : 'organization'));
if ($level === 'position') {
    // The shift filter does not apply to a single, explicitly chosen position.
    $filters['shift_ids'] = [];
}

$positions = StaffingService::positions($db, $filters);

// Breadcrumb names come from the hierarchy itself so they exist even when the filter matches nothing.
$breadcrumb = [['level' => 'organization', 'id' => null, 'name' => config('org_name')]];
if ($level !== 'organization') {
    $sql = [
        'group' => 'SELECT g.id AS group_id, g.name AS group_name FROM unit_groups g WHERE g.id = ?',
        'unit' => 'SELECT g.id AS group_id, g.name AS group_name, u.id AS unit_id, u.name AS unit_name
                   FROM units u JOIN unit_groups g ON g.id = u.group_id WHERE u.id = ?',
        'position' => 'SELECT g.id AS group_id, g.name AS group_name, u.id AS unit_id, u.name AS unit_name, p.id AS position_id, p.title
                       FROM positions p JOIN units u ON u.id = p.unit_id JOIN unit_groups g ON g.id = u.group_id WHERE p.id = ?',
    ][$level];
    $stmt = $db->prepare($sql);
    $stmt->execute([$filters[$level . '_id']]);
    $context = $stmt->fetch();
    if (!$context) {
        json_error('The selected item no longer exists.', 404);
    }
    $breadcrumb[] = ['level' => 'group', 'id' => $context['group_id'], 'name' => $context['group_name']];
    if (isset($context['unit_id'])) {
        $breadcrumb[] = ['level' => 'unit', 'id' => $context['unit_id'], 'name' => $context['unit_name']];
    }
    if (isset($context['position_id'])) {
        $breadcrumb[] = ['level' => 'position', 'id' => $context['position_id'], 'name' => $context['title']];
    }
}

$required = array_sum(array_column($positions, 'required_fte'));
$headcount = array_sum(array_column($positions, 'headcount'));
$totals = StaffingService::round([
    'position_count' => count($positions),
    'task_count' => array_sum(array_column($positions, 'task_count')),
    'daily_workload_minutes' => (float) array_sum(array_column($positions, 'daily_workload_minutes')),
    'required_fte' => (float) $required,
    'headcount' => $headcount,
] + StaffingService::totals((float) $required, $headcount));

$response = ['level' => $level, 'breadcrumb' => $breadcrumb, 'totals' => $totals];

switch ($level) {
    case 'organization':
        $response['rows'] = array_map([StaffingService::class, 'round'], StaffingService::aggregate($positions, 'group'));
        break;
    case 'group':
        $response['rows'] = array_map([StaffingService::class, 'round'], StaffingService::aggregate($positions, 'unit'));
        break;
    case 'unit':
        $response['rows'] = array_map([StaffingService::class, 'present'], $positions);
        break;
    case 'position':
        $position = $positions[0];
        $activeDays = $position['shift_id'] ? (int) $position['active_days'] : Workload::DEFAULT_SHIFT['active_days'];
        $response['position'] = StaffingService::present($position);
        $response['tasks'] = array_map(
            fn(array $task) => StaffingService::round(array_map(
                fn($v) => is_numeric($v) && !is_int($v) ? (float) $v : $v,
                $task
            ) + Workload::task($task, $activeDays)),
            $position['tasks']
        );
        break;
}

json_response($response);
