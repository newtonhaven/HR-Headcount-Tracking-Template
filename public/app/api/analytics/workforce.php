<?php
declare(strict_types=1);

require __DIR__ . '/../../../../src/bootstrap.php';

// Workforce analytics: headcount trend, hires/exits, turnover, tenure, age and contract mix.
// Query: optional group_id (breaks down by unit instead of group), months = 12 | 24 | 36.

api_start();

$groupId = query_id('group_id');
$months = in_array((int) ($_GET['months'] ?? 24), [12, 24, 36], true) ? (int) $_GET['months'] : 24;

$sql = "SELECT e.gender, e.birth_date, e.employment_type, e.hire_date, e.termination_date, e.termination_reason,
               COALESCE(g.name, 'Unassigned') AS group_name, COALESCE(u.name, 'Unassigned') AS unit_name
        FROM employees e
        LEFT JOIN positions p ON p.id = e.position_id
        LEFT JOIN units u ON u.id = p.unit_id
        LEFT JOIN unit_groups g ON g.id = u.group_id"
    . ($groupId ? ' WHERE g.id = ?' : '');
$stmt = db()->prepare($sql);
$stmt->execute($groupId ? [$groupId] : []);
$employees = $stmt->fetchAll();

$today = new DateTimeImmutable('today');
$todayStr = $today->format('Y-m-d');
$periodStart = $today->modify('first day of this month')->modify('-' . ($months - 1) . ' months');
$periodStartStr = $periodStart->format('Y-m-d');
$breakdownKey = $groupId ? 'unit_name' : 'group_name';

$isEmployedOn = static fn(array $e, string $date): bool =>
    $e['hire_date'] <= $date && ($e['termination_date'] === null || $e['termination_date'] > $date);
$yearsBetween = static fn(string $from, DateTimeImmutable $to): float =>
    (new DateTimeImmutable($from))->diff($to)->days / 365.25;

// Monthly series ---------------------------------------------------------------------------
$series = [];
for ($m = $periodStart; $m <= $today; $m = $m->modify('+1 month')) {
    $first = $m->format('Y-m-01');
    $lastDay = $m->format('Y-m-t');
    $end = min($lastDay, $todayStr);
    $row = ['month' => $m->format('Y-m'), 'label' => $m->format('M Y'), 'headcount' => 0, 'hires' => 0, 'exits' => 0];
    foreach ($employees as $e) {
        if ($isEmployedOn($e, $end)) {
            $row['headcount']++;
        }
        if ($e['hire_date'] >= $first && $e['hire_date'] <= $end) {
            $row['hires']++;
        }
        if ($e['termination_date'] !== null && $e['termination_date'] >= $first && $e['termination_date'] <= $end) {
            $row['exits']++;
        }
    }
    $series[] = $row;
}

// Current workforce ----------------------------------------------------------------------------
$active = array_values(array_filter($employees, fn($e) => $isEmployedOn($e, $todayStr)));
$exitsInPeriod = array_values(array_filter(
    $employees,
    fn($e) => $e['termination_date'] !== null && $e['termination_date'] >= $periodStartStr && $e['termination_date'] <= $todayStr
));
$hiresInPeriod = count(array_filter($employees, fn($e) => $e['hire_date'] >= $periodStartStr && $e['hire_date'] <= $todayStr));
$avgHeadcount = $series ? array_sum(array_column($series, 'headcount')) / count($series) : 0;
$annualize = 12 / $months;

$tenureBuckets = ['< 1 yr' => 0, '1–3 yrs' => 0, '3–5 yrs' => 0, '5–10 yrs' => 0, '10+ yrs' => 0];
$ageBands = ['< 25' => [0, 0], '25–34' => [0, 0], '35–44' => [0, 0], '45–54' => [0, 0], '55+' => [0, 0]];
$types = ['permanent' => 0, 'contract' => 0, 'temporary' => 0, 'intern' => 0];
$tenureSum = 0.0;
$ageSum = 0.0;
$ageCount = 0;
foreach ($active as $e) {
    $tenure = $yearsBetween($e['hire_date'], $today);
    $tenureSum += $tenure;
    $tenureBuckets[$tenure < 1 ? '< 1 yr' : ($tenure < 3 ? '1–3 yrs' : ($tenure < 5 ? '3–5 yrs' : ($tenure < 10 ? '5–10 yrs' : '10+ yrs')))]++;
    $types[$e['employment_type']]++;
    if ($e['birth_date']) {
        $age = $yearsBetween($e['birth_date'], $today);
        $ageSum += $age;
        $ageCount++;
        $band = $age < 25 ? '< 25' : ($age < 35 ? '25–34' : ($age < 45 ? '35–44' : ($age < 55 ? '45–54' : '55+')));
        $ageBands[$band][$e['gender'] === 'female' ? 0 : 1]++;
    }
}

// Turnover by group (or unit) ------------------------------------------------------------------
$byGroup = [];
foreach ($employees as $e) {
    $key = $e[$breakdownKey];
    $byGroup[$key] ??= ['name' => $key, 'headcount' => 0, 'start_headcount' => 0, 'hires' => 0, 'exits' => 0];
    if ($isEmployedOn($e, $todayStr)) {
        $byGroup[$key]['headcount']++;
    }
    if ($isEmployedOn($e, $periodStartStr)) {
        $byGroup[$key]['start_headcount']++;
    }
    if ($e['hire_date'] >= $periodStartStr && $e['hire_date'] <= $todayStr) {
        $byGroup[$key]['hires']++;
    }
    if ($e['termination_date'] !== null && $e['termination_date'] >= $periodStartStr && $e['termination_date'] <= $todayStr) {
        $byGroup[$key]['exits']++;
    }
}
foreach ($byGroup as &$g) {
    $avg = ($g['headcount'] + $g['start_headcount']) / 2;
    $g['turnover_pct'] = $avg > 0 ? round($g['exits'] / $avg * $annualize * 100, 1) : null;
    $g['net_change'] = $g['headcount'] - $g['start_headcount'];
}
unset($g);
$byGroup = array_values($byGroup);
usort($byGroup, fn($a, $b) => $b['headcount'] <=> $a['headcount']);

$reasons = array_count_values(array_map(fn($e) => $e['termination_reason'] ?: 'Not recorded', $exitsInPeriod));
arsort($reasons);

json_response([
    'months' => $months,
    'period_start' => $periodStartStr,
    'breakdown_by' => $groupId ? 'unit' : 'group',
    'kpis' => [
        'headcount' => count($active),
        'hires' => $hiresInPeriod,
        'exits' => count($exitsInPeriod),
        'net_change' => count($active) - ($series ? $series[0]['headcount'] - $series[0]['hires'] + $series[0]['exits'] : 0),
        'turnover_pct' => $avgHeadcount > 0 ? round(count($exitsInPeriod) / $avgHeadcount * $annualize * 100, 1) : null,
        'avg_tenure_years' => $active ? round($tenureSum / count($active), 1) : null,
        'avg_age' => $ageCount ? round($ageSum / $ageCount, 1) : null,
        'female_pct' => $active ? round(count(array_filter($active, fn($e) => $e['gender'] === 'female')) / count($active) * 100, 1) : null,
    ],
    'series' => $series,
    'by_group' => $byGroup,
    'employment_types' => $types,
    'tenure' => array_map(fn($k, $v) => ['label' => $k, 'count' => $v], array_keys($tenureBuckets), $tenureBuckets),
    'age_bands' => array_map(fn($k, $v) => ['label' => $k, 'female' => $v[0], 'male' => $v[1]], array_keys($ageBands), $ageBands),
    'exit_reasons' => array_map(fn($k, $v) => ['label' => $k, 'count' => $v], array_keys($reasons), $reasons),
]);
