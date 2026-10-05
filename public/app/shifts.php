<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/layout.php';

require_page_access(ROLE_ADMIN);

page_start('Shifts', 'shifts', [
    'subtitle' => 'Shifts define when positions work and how many productive minutes one person contributes per shift.',
]);
?>
<div class="row g-4">
    <div class="col-xl-4">
        <section class="panel" aria-labelledby="shift-form-title">
            <div class="panel-header">
                <h2 class="panel-title" id="shift-form-title">Add shift</h2>
            </div>
            <form id="shift-form" novalidate>
                <input type="hidden" name="id">
                <div class="mb-3">
                    <label class="form-label" for="shift-name">Name</label>
                    <input id="shift-name" name="name" class="form-control" maxlength="100" required placeholder="e.g. Day Shift">
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="shift-start">Starts</label>
                        <input id="shift-start" name="start_time" type="time" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="shift-end">Ends</label>
                        <input id="shift-end" name="end_time" type="time" class="form-control" required>
                    </div>
                    <div class="col-12 form-text mt-1" id="shift-length">An end time before the start time means the shift ends the next day.</div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="shift-days">Days per week</label>
                        <select id="shift-days" name="active_days" class="form-select" required>
                            <?php for ($d = 1; $d <= 7; $d++): ?>
                                <option value="<?= $d ?>"<?= $d === 5 ? ' selected' : '' ?>><?= $d ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="shift-hours">Effective hours</label>
                        <input id="shift-hours" name="effective_hours" type="number" min="0.5" max="24" step="0.25" class="form-control" required placeholder="7.5">
                    </div>
                    <div class="col-12 form-text mt-1">Effective hours = productive time per person per shift, excluding breaks.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="shift-notes">Notes <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea id="shift-notes" name="notes" class="form-control" rows="2"></textarea>
                </div>
                <div class="alert alert-danger py-2 d-none" id="shift-error" role="alert"></div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary" id="shift-submit">Add shift</button>
                    <button type="button" class="btn btn-ghost d-none" id="shift-cancel">Cancel editing</button>
                </div>
            </form>
        </section>
    </div>

    <div class="col-xl-8">
        <section class="panel" aria-labelledby="shift-list-title">
            <div class="panel-header">
                <h2 class="panel-title" id="shift-list-title">Existing shifts</h2>
            </div>
            <div id="shift-table"></div>
        </section>
    </div>
</div>
<?php
page_end(['/assets/js/shifts.js']);
