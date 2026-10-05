<?php
declare(strict_types=1);

//tests for the norm calculation to run: php tests/run.php


require __DIR__ . '/../src/Workload.php';
require __DIR__ . '/../src/StaffingService.php';

$failures = 0;
$count = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : " ($detail)") . PHP_EOL;
}

function near(string $name, float $actual, float $expected, float $tolerance = 0.01): void
{
    check($name, abs($actual - $expected) <= $tolerance, "got $actual, expected $expected");
}

function task(array $fields): array
{
    return $fields + [
        'duration_min' => 0, 'duration_max' => 0, 'daily_min' => 0, 'daily_max' => 0,
        'weekly_min' => 0, 'weekly_max' => 0, 'monthly_min' => 0, 'monthly_max' => 0,
        'personal_allowance_pct' => 0, 'rest_allowance_pct' => 0,
    ];
}

echo "Workload::task\n";
$fixed = Workload::task(task(['duration_min' => 2, 'duration_max' => 4, 'daily_min' => 10, 'daily_max' => 10]), 5);
near('mean = average duration x average frequency', $fixed['mean_minutes'], 30.0);
near('fixed frequency: variance comes from duration only', $fixed['std_dev'], sqrt(10 * 4 / 12));
near('safety time = mean + 1.5 sd', $fixed['safety_minutes'], 30 + 1.5 * sqrt(10 * 4 / 12));
near('no allowance: standard = safety time', $fixed['standard_minutes'], $fixed['safety_minutes']);

$allowance = Workload::task(task(['duration_min' => 2, 'duration_max' => 4, 'daily_min' => 10, 'daily_max' => 10,
    'personal_allowance_pct' => 5, 'rest_allowance_pct' => 10]), 5);
near('allowances add 15% of the mean', $allowance['standard_minutes'], $fixed['safety_minutes'] + 30 * 0.15);

$random = Workload::task(task(['duration_min' => 2, 'duration_max' => 4, 'daily_min' => 40, 'daily_max' => 70]), 5);
near('random sum sd matches simulation', $random['std_dev'], 26.33, 0.05);

$weekly = Workload::task(task(['duration_min' => 2, 'duration_max' => 4, 'weekly_min' => 5, 'weekly_max' => 5]), 5);
near('weekly counts are spread over the active days', $weekly['mean_minutes'], 3.0);
$monthly = Workload::task(task(['duration_min' => 2, 'duration_max' => 4, 'monthly_min' => 21, 'monthly_max' => 21]), 5);
near('monthly counts are spread over 21 working days', $monthly['mean_minutes'], 3.0);
$none = Workload::task(task(['duration_min' => 2, 'duration_max' => 4]), 0);
near('no frequency means no load (and no division by zero)', $none['standard_minutes'], 0.0);

echo "Workload::position\n";
$tasks = [task(['duration_min' => 2, 'duration_max' => 4, 'daily_min' => 10, 'daily_max' => 10])];
$sevenDay = Workload::position($tasks, ['active_days' => 7, 'effective_hours' => 7], 3);
near('effective minutes from the shift', $sevenDay['effective_minutes'], 420.0);
near('7-day shift coverage factor = 7 / 5', $sevenDay['coverage_factor'], 1.4);
near('required FTE = load / capacity x coverage', $sevenDay['required_fte'], $fixed['standard_minutes'] / 420 * 1.4, 0.0001);
near('gap = headcount - required', $sevenDay['gap'], 3 - $sevenDay['required_fte'], 0.0001);
$office = Workload::position($tasks, ['active_days' => 5, 'effective_hours' => 8], 1);
near('5-day shift has no coverage uplift', $office['coverage_factor'], 1.0);
$default = Workload::position($tasks, null, 0);
near('no shift falls back to 7.5 h', $default['effective_minutes'], 450.0);
check('zero headcount has no utilization', $default['utilization_pct'] === null);

echo "StaffingService\n";
check('gap -0.5 is understaffed', StaffingService::status(-0.5) === 'understaffed');
check('gap -0.49 is balanced', StaffingService::status(-0.49) === 'balanced');
check('gap +0.5 is overstaffed', StaffingService::status(0.5) === 'overstaffed');
$rows = StaffingService::aggregate([
    ['group_id' => 1, 'group_name' => 'A', 'unit_id' => 1, 'unit_name' => 'U1', 'task_count' => 2, 'daily_workload_minutes' => 100.0, 'required_fte' => 2.0, 'headcount' => 1, 'gap' => -1.0],
    ['group_id' => 1, 'group_name' => 'A', 'unit_id' => 2, 'unit_name' => 'U2', 'task_count' => 1, 'daily_workload_minutes' => 50.0, 'required_fte' => 1.0, 'headcount' => 2, 'gap' => 1.0],
], 'group');
check('aggregate sums positions into one group', count($rows) === 1 && $rows[0]['position_count'] === 2);
near('aggregate gap uses summed totals', $rows[0]['gap'], 0.0);
check('aggregate counts under- and overstaffed positions', $rows[0]['understaffed_positions'] === 1 && $rows[0]['overstaffed_positions'] === 1);

echo PHP_EOL . ($failures ? "$failures of $count checks FAILED" : "All $count checks passed") . PHP_EOL;
exit($failures ? 1 : 0);
