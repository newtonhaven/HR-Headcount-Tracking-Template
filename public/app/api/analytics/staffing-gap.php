<?php
declare(strict_types=1);

require __DIR__ . '/../../../../src/bootstrap.php';

// Staffing gap analysis: required FTE (from task workload) vs. actual headcount.
// Query: optional group_id (breaks the result down by unit instead of group) and shift_id.

api_start();

$groupId = query_id('group_id');
$shiftId = query_id('shift_id');
$positions = StaffingService::positions(db(), [
    'group_id' => $groupId,
    'shift_ids' => $shiftId ? [$shiftId] : [],
]);

$required = (float) array_sum(array_column($positions, 'required_fte'));
$headcount = (int) array_sum(array_column($positions, 'headcount'));
$statusCounts = ['understaffed' => 0, 'balanced' => 0, 'overstaffed' => 0];
$shortage = 0.0;
$surplus = 0.0;
foreach ($positions as $p) {
    $statusCounts[StaffingService::status($p['gap'])]++;
    if ($p['gap'] < 0) {
        $shortage += -$p['gap'];
    } else {
        $surplus += $p['gap'];
    }
}

// Utilization buckets (required FTE as % of current headcount).
$buckets = ['< 70%' => 0, '70–90%' => 0, '90–110%' => 0, '110–130%' => 0, '> 130%' => 0, 'No staff' => 0];
foreach ($positions as $p) {
    $u = $p['utilization_pct'];
    $key = $u === null ? 'No staff' : ($u < 70 ? '< 70%' : ($u < 90 ? '70–90%' : ($u <= 110 ? '90–110%' : ($u <= 130 ? '110–130%' : '> 130%'))));
    $buckets[$key]++;
}

$breakdown = StaffingService::aggregate($positions, $groupId ? 'unit' : 'group');
usort($breakdown, fn($a, $b) => $a['gap'] <=> $b['gap']);

$presented = array_map([StaffingService::class, 'present'], $positions);
usort($presented, fn($a, $b) => $a['gap'] <=> $b['gap']);

json_response([
    'kpis' => StaffingService::round([
        'required_fte' => $required,
        'headcount' => $headcount,
        'shortage_fte' => $shortage,
        'surplus_fte' => $surplus,
        'position_count' => count($positions),
    ] + StaffingService::totals($required, $headcount) + $statusCounts),
    'breakdown_by' => $groupId ? 'unit' : 'group',
    'breakdown' => array_map([StaffingService::class, 'round'], $breakdown),
    'utilization_buckets' => array_map(fn($k, $v) => ['label' => $k, 'count' => $v], array_keys($buckets), $buckets),
    'positions' => $presented,
    'tolerance' => StaffingService::GAP_TOLERANCE,
]);
