<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/layout.php';

require_page_access();

page_start('Workforce Analytics', 'workforce', [
    'subtitle' => 'Headcount over time, hiring and leaving, turnover, tenure and age structure, calculated from employee hire and departure dates.',
]);
?>
<form class="panel filter-bar" id="wf-filters" onsubmit="return false" aria-label="Filters">
    <div class="filter">
        <label class="form-label" for="w-group">Unit group</label>
        <select id="w-group" name="group_id" class="form-select"><option value="">All groups</option></select>
    </div>
    <div class="filter">
        <label class="form-label" for="w-months">Period</label>
        <select id="w-months" name="months" class="form-select">
            <option value="12">Last 12 months</option>
            <option value="24" selected>Last 24 months</option>
            <option value="36">Last 36 months</option>
        </select>
    </div>
</form>

<div class="kpi-grid section-gap" id="wf-kpis" aria-live="polite"></div>

<div class="chart-grid">
    <?php chart_panel('chart-trend', 'Headcount trend', 'Active employees at the end of each month', 'span-2'); ?>
    <?php chart_panel('chart-flow', 'Hires and exits', 'Employees joining and leaving per month', 'span-2'); ?>
    <?php chart_panel('chart-tenure', 'Tenure', 'Active employees by years of service'); ?>
    <?php chart_panel('chart-age', 'Age structure', 'Active employees by age band and gender'); ?>
    <?php chart_panel('chart-reasons', 'Why people left', 'Exits in the selected period by reason'); ?>
    <?php chart_panel('chart-types', 'Employment types', 'Active employees by contract type'); ?>

    <section class="panel span-2" aria-labelledby="wf-table-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="wf-table-title">Turnover by unit group</h2>
                <p class="panel-subtitle">Annualized turnover = exits ÷ average headcount, scaled to 12 months.</p>
            </div>
        </div>
        <div id="wf-table"></div>
    </section>
</div>
<?php
page_end(['/assets/vendor/chartjs/chart.min.js', '/assets/js/charts.js', '/assets/js/workforce-analytics.js']);
