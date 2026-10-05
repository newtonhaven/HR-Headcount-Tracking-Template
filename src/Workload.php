<?php
declare(strict_types=1);
final class Workload
{
    public const WORKING_DAYS_PER_MONTH = 21;
    public const SAFETY_Z = 1.5;

    /** Days per week one full-time employee works. A shift that runs 7 days needs 7/5 people per daily slot. */
    public const WORK_DAYS_PER_EMPLOYEE = 5;

    /** Used when a position has no shift assigned. */
    public const DEFAULT_SHIFT = ['active_days' => 5, 'effective_hours' => 7.5];

    /** @return array{mean_minutes: float, std_dev: float, safety_minutes: float, standard_minutes: float} */
    public static function task(array $task, int $activeDays): array
    {
        $n = static fn(string $key): float => (float) ($task[$key] ?? 0);
        $activeDays = max(1, $activeDays);

        $sMin = $n('duration_min');
        $sMax = $n('duration_max');
        $dMin = $n('daily_min');
        $dMax = $n('daily_max');
        $wMin = $n('weekly_min');
        $wMax = $n('weekly_max');
        $mMin = $n('monthly_min');
        $mMax = $n('monthly_max');

        $mean = ($sMin + $sMax) / 4.0
            * (($dMin + $dMax) + ($wMin + $wMax) / $activeDays + ($mMin + $mMax) / self::WORKING_DAYS_PER_MONTH);

        // Variance of one frequency band: E[N]·Var(D) + Var(N)·E[D]², with uniform N and D.
        $variance = static fn(float $fMin, float $fMax): float =>
            ($fMin + $fMax) / 2.0 * (($sMax - $sMin) ** 2) / 12.0
            + (($fMax - $fMin) ** 2) / 12.0 * (($sMin + $sMax) / 2.0) ** 2;
        $stdDev = sqrt(
            $variance($dMin, $dMax)
            + $variance($wMin, $wMax) / $activeDays ** 2
            + $variance($mMin, $mMax) / self::WORKING_DAYS_PER_MONTH ** 2
        );

        $safety = $mean + self::SAFETY_Z * $stdDev;
        $allowance = ($n('personal_allowance_pct') + $n('rest_allowance_pct')) / 100.0;

        return [
            'mean_minutes' => $mean,
            'std_dev' => $stdDev,
            'safety_minutes' => $safety,
            'standard_minutes' => $mean * $allowance + $safety,
        ];
    }

    /**
     * Staffing need of one position.
     *
     * @param array      $tasks     
     * @param array|null $shift     
     * @param int        $headcount
     */
    public static function position(array $tasks, ?array $shift, int $headcount): array
    {
        $shift = $shift ?: self::DEFAULT_SHIFT;
        $activeDays = max(1, (int) $shift['active_days']);
        $effectiveMinutes = max(1.0, (float) $shift['effective_hours'] * 60);
        $coverage = max(1.0, $activeDays / self::WORK_DAYS_PER_EMPLOYEE);

        $daily = 0.0;
        foreach ($tasks as $task) {
            $daily += self::task($task, $activeDays)['standard_minutes'];
        }

        $required = $daily / $effectiveMinutes * $coverage;

        return [
            'daily_workload_minutes' => $daily,
            'effective_minutes' => $effectiveMinutes,
            'coverage_factor' => $coverage,
            'required_fte' => $required,
            'headcount' => $headcount,
            'gap' => $headcount - $required,
            'utilization_pct' => $headcount > 0 ? $required / $headcount * 100 : null,
        ];
    }
}
