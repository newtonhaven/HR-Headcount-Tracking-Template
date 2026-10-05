<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/layout.php';

require_page_access();

page_start('Staffing Gap', 'staffing-gap', [
    'subtitle' => 'Where is the organization short of staff, and where does it have more people than the workload needs? Required FTE comes from the task workload; headcount from the employee list.',
]);
?>
<form class="panel filter-bar" id="gap-filters" onsubmit="return false" aria-label="Filters">
    <div class="filter">
        <label class="form-label" for="g-group">Unit group</label>
        <select id="g-group" name="group_id" class="form-select"><option value="">All groups</option></select>
    </div>
    <div class="filter">
        <label class="form-label" for="g-shift">Shift</label>
        <select id="g-shift" name="shift_id" class="form-select"><option value="">All shifts</option></select>
    </div>
    <div class="filter-actions">
        <button type="button" class="btn btn-ghost" id="g-download"><i class="fa fa-download me-1" aria-hidden="true"></i>Download CSV</button>
    </div>
</form>

<div class="kpi-grid section-gap" id="gap-kpis" aria-live="polite"></div>

<div class="chart-grid">
    <?php chart_panel('chart-headcount', 'Required vs. current headcount', 'FTE per unit group', 'span-2', true); ?>
    <?php chart_panel('chart-gap', 'Net gap', 'Current headcount minus required FTE'); ?>
    <?php chart_panel('chart-utilization', 'Workload vs. staff per position', 'Positions by required FTE as a % of their headcount'); ?>

    <section class="panel span-2" aria-labelledby="gap-table-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="gap-table-title">Positions furthest from their norm</h2>
                <p class="panel-subtitle">Click a row to open the position in the staffing report.</p>
            </div>
            <div class="btn-group" role="group" aria-label="Which positions">
                <button type="button" class="btn btn-sm btn-ghost active" data-view="understaffed" aria-pressed="true">Most understaffed</button>
                <button type="button" class="btn btn-sm btn-ghost" data-view="overstaffed" aria-pressed="false">Most overstaffed</button>
            </div>
        </div>
        <div id="gap-table"></div>
    </section>
</div>
<?php
page_end(['/assets/vendor/chartjs/chart.min.js', '/assets/js/charts.js', '/assets/js/staffing-gap.js']);
