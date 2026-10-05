<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/layout.php';

require_page_access();

page_start('Staffing Report', 'staffing-report', [
    'subtitle' => 'Norm staffing calculated from task workload, compared with the current headcount. Pick a level or click a row to drill down.',
    'actions' => '<button type="button" class="btn btn-ghost no-print" id="print-report"><i class="fa fa-print me-1" aria-hidden="true"></i>Print</button>',
]);
?>
<section class="panel no-print" aria-label="Report filters">
    <form class="filter-bar" id="report-filters" onsubmit="return false">
        <div class="filter">
            <label class="form-label" for="r-group">Unit group</label>
            <select id="r-group" name="group_id" class="form-select"><option value="">All groups</option></select>
        </div>
        <div class="filter">
            <label class="form-label" for="r-unit">Unit</label>
            <select id="r-unit" name="unit_id" class="form-select" disabled><option value="">All units</option></select>
        </div>
        <div class="filter">
            <label class="form-label" for="r-position">Position</label>
            <select id="r-position" name="position_id" class="form-select" disabled><option value="">All positions</option></select>
        </div>
        <div class="filter">
            <span class="form-label d-block" id="r-shifts-label">Shifts</span>
            <div class="dropdown">
                <button type="button" class="form-select text-start" id="r-shifts" data-bs-toggle="dropdown" data-bs-auto-close="outside"
                        aria-expanded="false" aria-labelledby="r-shifts-label r-shifts">All shifts</button>
                <div class="dropdown-menu p-2" id="r-shift-options" aria-labelledby="r-shifts-label"></div>
            </div>
        </div>
    </form>
</section>

<section class="panel section-gap" aria-labelledby="report-title">
    <h2 class="visually-hidden" id="report-title">Report</h2>
    <ol class="crumbs" id="report-crumbs" aria-label="Report level"></ol>
    <div id="report-summary"></div>
    <div id="report-body" aria-live="polite"></div>
    <p class="info-note mt-3 mb-0">
        <i class="fa fa-circle-info me-1" aria-hidden="true"></i>
        Required FTE = daily workload ÷ effective minutes per person × coverage factor (shift days per week ÷
        <?= Workload::WORK_DAYS_PER_EMPLOYEE ?> working days per employee). Positions within ±<?= StaffingService::GAP_TOLERANCE ?>
        FTE of their norm count as balanced.
    </p>
</section>
<?php
page_end(['/assets/js/staffing-report.js']);
