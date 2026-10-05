<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/layout.php';

$user = require_page_access();

$tiles = [
    ['href' => 'structure.php', 'icon' => 'fa-keyboard', 'label' => 'Data Entry', 'admin' => true],
    ['href' => 'staffing-report.php', 'icon' => 'fa-file-alt', 'label' => 'Reporting'],
    ['href' => 'shifts.php', 'icon' => 'fa-user-clock', 'label' => 'Define Shifts', 'admin' => true],
    ['href' => 'employees.php', 'icon' => 'fa-users', 'label' => 'Employees'],
    ['href' => 'staffing-gap.php', 'icon' => 'fa-scale-unbalanced', 'label' => 'Staffing Gap'],
    ['href' => 'workforce-analytics.php', 'icon' => 'fa-chart-line', 'label' => 'Workforce Analytics'],
];

page_start('Dashboard', 'dashboard', ['header' => false, 'spinner' => true]);
?>
<div class="row g-4">
    <?php foreach ($tiles as $tile): ?>
        <?php if (!empty($tile['admin']) && !is_admin()) continue; ?>
        <div class="col-sm-6 col-xl-3">
            <a href="<?= e($tile['href']) ?>" class="dashboard-card text-decoration-none">
                <div class="bg-secondary rounded d-flex flex-column align-items-center justify-content-center p-4 card-hover" style="aspect-ratio: 1 / 1; min-height: 180px;">
                    <i class="fa <?= e($tile['icon']) ?> fa-3x text-primary mb-3"></i>
                    <h1 class="mb-0 text-white text-center"><?= e($tile['label']) ?></h1>
                </div>
            </a>
        </div>
    <?php endforeach; ?>

    <?php if (is_admin()): ?>
        <div class="col-sm-6 col-xl-3">
            <div class="dashboard-card text-decoration-none position-relative">
                <div class="bg-secondary rounded d-flex flex-column align-items-center justify-content-center p-4 card-hover export-card"
                     style="aspect-ratio: 1 / 1; min-height: 180px; position: relative;"
                     role="button" tabindex="0" aria-expanded="false" aria-controls="export-buttons">
                    <i class="fa fa-download fa-3x text-primary mb-3"></i>
                    <h1 class="mb-0 text-white text-center">Database Export</h1>
                    <div id="export-buttons" class="export-buttons" style="display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(33,37,41,0.95); z-index: 2; flex-direction: column; align-items: center; justify-content: center;">
                        <a href="api/export/csv.php" class="btn btn-light btn-lg d-flex align-items-center justify-content-center mx-5 my-4 text-wrap" style="font-size: 1.1rem; aspect-ratio: 2 / 1; width: 75%;">CSV</a>
                        <a href="api/export/excel.php" class="btn btn-success btn-lg d-flex align-items-center justify-content-center mx-5 text-wrap" style="font-size: 1.1rem; aspect-ratio: 2 / 1; width: 75%;">Excel</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    // Database export tile: reveal the CSV / Excel buttons, hide them on an outside click or Escape.
    (() => {
        const card = document.querySelector(".export-card");
        if (!card) return;
        const buttons = card.querySelector(".export-buttons");
        const toggle = (show) => {
            buttons.style.display = show ? "flex" : "none";
            card.setAttribute("aria-expanded", String(show));
        };
        card.addEventListener("click", (event) => {
            event.stopPropagation();
            toggle(true);
        });
        card.addEventListener("keydown", (event) => {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                toggle(true);
                buttons.querySelector("a").focus();
            }
            if (event.key === "Escape") toggle(false);
        });
        document.addEventListener("click", () => toggle(false));
    })();
</script>
<?php
page_end();
