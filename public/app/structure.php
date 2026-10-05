<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/layout.php';

require_page_access(ROLE_ADMIN);

page_start('Organization', 'structure', [
    'subtitle' => 'Unit groups contain units, units contain positions, and every position has the tasks its staffing need is calculated from. Select an item to drill down.',
]);

/** One column of the browser. */
function miller_column(string $id, string $title, string $filterLabel, string $emptyHint): void
{
    ?>
    <section class="panel miller-col" id="<?= e($id) ?>" aria-labelledby="<?= e($id) ?>-title">
        <div class="miller-head">
            <div class="panel-header">
                <h2 class="panel-title" id="<?= e($id) ?>-title"><?= e($title) ?><span class="miller-count" data-count>0</span></h2>
                <button type="button" class="btn btn-sm btn-primary" data-add disabled>
                    <i class="fa fa-plus me-1" aria-hidden="true"></i>Add
                </button>
            </div>
            <div class="miller-search">
                <i class="fa fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" class="form-control form-control-sm" placeholder="<?= e($filterLabel) ?>" aria-label="<?= e($filterLabel) ?>" data-filter>
            </div>
        </div>
        <ul class="miller-list" data-list aria-label="<?= e($title) ?>">
            <li class="empty-state"><?= e($emptyHint) ?></li>
        </ul>
    </section>
    <?php
}
?>
<div class="miller">
    <?php
    miller_column('col-groups', 'Unit groups', 'Filter groups', 'Loading…');
    miller_column('col-units', 'Units', 'Filter units', 'Select a unit group');
    miller_column('col-positions', 'Positions', 'Filter positions', 'Select a unit');
    ?>
</div>

<section class="panel section-gap" id="tasks-panel" aria-labelledby="tasks-title">
    <div class="panel-header">
        <div>
            <h2 class="panel-title" id="tasks-title">Tasks</h2>
            <p class="panel-subtitle" id="tasks-subtitle">Select a position to see and edit its task definitions.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-ghost" id="copy-tasks" disabled>
                <i class="fa fa-copy me-1" aria-hidden="true"></i>Copy from another position
            </button>
            <button type="button" class="btn btn-sm btn-primary" id="add-task" disabled>
                <i class="fa fa-plus me-1" aria-hidden="true"></i>Add task
            </button>
        </div>
    </div>
    <div id="tasks-summary"></div>
    <div id="tasks-table"></div>
    <p class="info-note mt-3 mb-0">
        <i class="fa fa-circle-info me-1" aria-hidden="true"></i>
        Daily load = average duration × average daily frequency (weekly counts are spread over the shift's active days,
        monthly counts over <?= Workload::WORKING_DAYS_PER_MONTH ?> working days), plus a safety margin of
        <?= Workload::SAFETY_Z ?> standard deviations and the personal and rest allowances.
    </p>
</section>
<?php
page_end(['/assets/js/structure.js']);
