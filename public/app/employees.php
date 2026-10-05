<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/layout.php';

require_page_access();

page_start('Employees', 'employees', [
    'subtitle' => 'Everyone on the payroll, where they work and when they joined or left. Headcount on every report is counted from this list.',
    'actions' => is_admin()
        ? '<button type="button" class="btn btn-primary" id="add-employee"><i class="fa fa-user-plus me-1" aria-hidden="true"></i>Add employee</button>'
        : '',
]);
?>
<section class="panel" aria-label="Employee list">
    <form class="filter-bar mb-3" id="employee-filters" role="search" onsubmit="return false">
        <div class="filter" style="flex-basis: 240px;">
            <label class="form-label" for="f-q">Search</label>
            <input type="search" id="f-q" name="q" class="form-control" placeholder="Name, employee no. or position">
        </div>
        <div class="filter">
            <label class="form-label" for="f-group">Unit group</label>
            <select id="f-group" name="group_id" class="form-select"><option value="">All groups</option></select>
        </div>
        <div class="filter">
            <label class="form-label" for="f-unit">Unit</label>
            <select id="f-unit" name="unit_id" class="form-select"><option value="">All units</option></select>
        </div>
        <div class="filter" style="flex-basis: 140px;">
            <label class="form-label" for="f-status">Status</label>
            <select id="f-status" name="status" class="form-select">
                <option value="active">Active</option>
                <option value="terminated">Left</option>
                <option value="all">All</option>
            </select>
        </div>
        <div class="filter" style="flex-basis: 150px;">
            <label class="form-label" for="f-type">Employment</label>
            <select id="f-type" name="type" class="form-select">
                <option value="">All types</option>
                <option value="permanent">Permanent</option>
                <option value="contract">Contract</option>
                <option value="temporary">Temporary</option>
                <option value="intern">Intern</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="button" class="btn btn-ghost" id="f-reset"><i class="fa fa-rotate-left me-1" aria-hidden="true"></i>Reset</button>
        </div>
    </form>
    <div id="employee-table" aria-live="polite"></div>
    <div class="pager" id="employee-pager"></div>
</section>
<?php
page_end(['/assets/js/employees.js']);
