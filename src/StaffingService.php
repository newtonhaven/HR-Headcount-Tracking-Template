<?php
declare(strict_types=1);

/** Loads positions with their tasks and headcount, and computes norm-vs-actual staffing figures. */
final class StaffingService
{
    public const ACTIVE_EMPLOYEE_SQL = '(e.termination_date IS NULL OR e.termination_date > CURDATE())';

    public const GAP_TOLERANCE = 0.5;

    /**
     * @param array $filters group_id, unit_id, position_id (int) and shift_ids (int[])
     * @return array[] one row per position, including its tasks and computed metrics
     */
    public static function positions(PDO $db, array $filters = []): array
    {
        $where = [];
        $params = [];
        foreach (['group_id' => 'u.group_id', 'unit_id' => 'p.unit_id', 'position_id' => 'p.id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "$column = ?";
                $params[] = (int) $filters[$key];
            }
        }
        if (!empty($filters['shift_ids'])) {
            $where[] = 'p.shift_id IN (' . implode(',', array_fill(0, count($filters['shift_ids']), '?')) . ')';
            array_push($params, ...array_map('intval', $filters['shift_ids']));
        }

        $sql = 'SELECT p.id, p.title, p.unit_id, u.name AS unit_name, u.group_id, g.name AS group_name,
                       p.shift_id, s.name AS shift_name, s.active_days, s.effective_hours,
                       (SELECT COUNT(*) FROM employees e WHERE e.position_id = p.id AND ' . self::ACTIVE_EMPLOYEE_SQL . ') AS headcount
                FROM positions p
                JOIN units u ON u.id = p.unit_id
                JOIN unit_groups g ON g.id = u.group_id
                LEFT JOIN shifts s ON s.id = p.shift_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY g.name, u.name, p.title, s.start_time';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $positions = $stmt->fetchAll();
        if (!$positions) {
            return [];
        }

        $tasksByPosition = [];
        $ids = array_column($positions, 'id');
        $stmt = $db->prepare('SELECT * FROM tasks WHERE position_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY name');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $task) {
            $tasksByPosition[$task['position_id']][] = $task;
        }

        foreach ($positions as &$position) {
            $tasks = $tasksByPosition[$position['id']] ?? [];
            $shift = $position['shift_id'] ? [
                'active_days' => (int) $position['active_days'],
                'effective_hours' => (float) $position['effective_hours'],
            ] : null;
            $position['tasks'] = $tasks;
            $position['task_count'] = count($tasks);
            $position += Workload::position($tasks, $shift, (int) $position['headcount']);
        }
        unset($position);

        return $positions;
    }
    public static function status(float $gap): string
    {
        if ($gap <= -self::GAP_TOLERANCE) {
            return 'understaffed';
        }
        return $gap >= self::GAP_TOLERANCE ? 'overstaffed' : 'balanced';
    }

    public static function aggregate(array $positions, string $by): array
    {
        $idKey = $by === 'unit' ? 'unit_id' : 'group_id';
        $nameKey = $by === 'unit' ? 'unit_name' : 'group_name';
        $rows = [];
        foreach ($positions as $p) {
            $id = $p[$idKey];
            $rows[$id] ??= [
                'id' => $id,
                'name' => $p[$nameKey],
                'group_name' => $p['group_name'],
                'position_count' => 0,
                'task_count' => 0,
                'daily_workload_minutes' => 0.0,
                'required_fte' => 0.0,
                'headcount' => 0,
                'understaffed_positions' => 0,
                'overstaffed_positions' => 0,
            ];
            $row = &$rows[$id];
            $row['position_count']++;
            $row['task_count'] += $p['task_count'];
            $row['daily_workload_minutes'] += $p['daily_workload_minutes'];
            $row['required_fte'] += $p['required_fte'];
            $row['headcount'] += $p['headcount'];
            $status = self::status($p['gap']);
            if ($status === 'understaffed') {
                $row['understaffed_positions']++;
            } elseif ($status === 'overstaffed') {
                $row['overstaffed_positions']++;
            }
            unset($row);
        }
        foreach ($rows as &$row) {
            $row += self::totals($row['required_fte'], $row['headcount']);
        }
        unset($row);
        return array_values($rows);
    }

    public static function totals(float $required, int $headcount): array
    {
        return [
            'gap' => $headcount - $required,
            'utilization_pct' => $headcount > 0 ? $required / $headcount * 100 : null,
        ];
    }

    public static function present(array $position): array
    {
        unset($position['tasks'], $position['active_days'], $position['effective_hours']);
        $position['status'] = self::status($position['gap']);
        return self::round($position);}

    public static function round(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_float($value)) {
                $row[$key] = round($value, 2);
            }
        }
        return $row;}}
