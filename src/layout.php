<?php
declare(strict_types=1);

// Shared page chrome: <head>, sidebar, top bar and footer.

/** Public asset URL with a cache-busting version parameter. */
function asset(string $path): string
{
    $file = __DIR__ . '/../public' . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function nav_items(): array
{
    return [
        ['key' => 'dashboard', 'href' => 'index.php', 'label' => 'Dashboard', 'icon' => 'fa-gauge-high'],
        ['section' => 'Manage'],
        ['key' => 'structure', 'href' => 'structure.php', 'label' => 'Organization', 'icon' => 'fa-sitemap', 'role' => ROLE_ADMIN],
        ['key' => 'shifts', 'href' => 'shifts.php', 'label' => 'Shifts', 'icon' => 'fa-user-clock', 'role' => ROLE_ADMIN],
        ['key' => 'employees', 'href' => 'employees.php', 'label' => 'Employees', 'icon' => 'fa-users'],
        ['key' => 'users', 'href' => 'users.php', 'label' => 'Users', 'icon' => 'fa-user-shield', 'role' => ROLE_ADMIN],
        ['section' => 'Reports & Analytics'],
        ['key' => 'staffing-report', 'href' => 'staffing-report.php', 'label' => 'Staffing Report', 'icon' => 'fa-file-lines'],
        ['key' => 'staffing-gap', 'href' => 'staffing-gap.php', 'label' => 'Staffing Gap', 'icon' => 'fa-scale-unbalanced'],
        ['key' => 'workforce', 'href' => 'workforce-analytics.php', 'label' => 'Workforce Analytics', 'icon' => 'fa-chart-line'],
    ];
}

/**
 * @param array $options subtitle (string), actions (raw HTML for the header's right side),
 *                       header (bool, default true), spinner (bool)
 */
function page_start(string $title, string $active, array $options = []): void
{
    $user = current_user();
    $appName = config('app_name');
    ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · <?= e($appName) ?></title>
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <link href="/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(asset('/assets/css/layout.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('/assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body data-role="<?= e($user['role']) ?>">
<?php if (!empty($options['spinner'])): ?>
    <div id="spinner" class="page-spinner" role="status">
        <div class="spinner-border" style="width: 3rem; height: 3rem;"><span class="visually-hidden">Loading…</span></div>
    </div>
<?php endif; ?>
<div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Main navigation">
        <a href="index.php" class="sidebar-brand">
            <img src="/assets/img/logo.png" alt="" class="brand-logo">
            <span class="brand-name"><?= e($appName) ?></span>
        </a>
        <div class="sidebar-user">
            <div class="sidebar-user-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></div>
            <div>
                <div class="sidebar-user-name"><?= e($user['name']) ?></div>
                <span class="role-badge role-<?= e($user['role']) ?>"><?= e(ucfirst($user['role'])) ?></span>
            </div>
        </div>
        <nav class="side-nav">
            <?php foreach (nav_items() as $item): ?>
                <?php if (isset($item['section'])): ?>
                    <div class="nav-section"><?= e($item['section']) ?></div>
                <?php elseif (empty($item['role']) || $item['role'] === $user['role']): ?>
                    <a href="<?= e($item['href']) ?>" class="side-link<?= $item['key'] === $active ? ' active' : '' ?>"
                        <?= $item['key'] === $active ? 'aria-current="page"' : '' ?>>
                        <i class="fa <?= e($item['icon']) ?>" aria-hidden="true"></i><span><?= e($item['label']) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="content">
        <header class="topbar">
            <a href="index.php" class="topbar-brand d-lg-none">
                <img src="/assets/img/logo.png" alt="<?= e($appName) ?>" class="brand-logo">
            </a>
            <button type="button" class="sidebar-toggler" aria-controls="sidebar" aria-label="Toggle navigation">
                <i class="fa fa-bars" aria-hidden="true"></i>
            </button>
            <span class="topbar-title d-none d-sm-inline"><?= e($title) ?></span>
            <div class="topbar-actions">
                <button type="button" class="btn btn-ghost btn-sm" id="change-password">
                    <i class="fa fa-key" aria-hidden="true"></i><span class="d-none d-md-inline ms-1">Password</span>
                </button>
                <form method="post" action="/logout.php" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fa fa-right-from-bracket me-1" aria-hidden="true"></i>Sign out
                    </button>
                </form>
            </div>
        </header>

        <main class="page">
            <?php if (!empty($_GET['denied'])): ?>
                <div class="alert alert-app alert-app-warning mb-4">
                    <i class="fa fa-triangle-exclamation me-2" aria-hidden="true"></i>That page is only available to administrators.
                </div>
            <?php endif; ?>
            <?php if ($options['header'] ?? true): ?>
                <header class="page-header">
                    <div>
                        <h1 class="page-title"><?= e($title) ?></h1>
                        <?php if (!empty($options['subtitle'])): ?>
                            <p class="page-subtitle"><?= e($options['subtitle']) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($options['actions'])): ?>
                        <div class="page-actions"><?= $options['actions'] ?></div>
                    <?php endif; ?>
                </header>
            <?php endif; ?>
<?php
}

function page_end(array $scripts = []): void
{
    ?>
        </main>

        <footer class="app-footer">
            <span><?= e(config('org_name')) ?> · <?= e(config('app_name')) ?></span>
        </footer>
    </div>

    <a href="#" class="back-to-top" aria-label="Back to top"><i class="fa fa-arrow-up" aria-hidden="true"></i></a>
</div>

<div id="toast-stack" class="toast-stack" aria-live="polite"></div>

<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('/assets/js/app.js')) ?>"></script>
<?php foreach ($scripts as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Panel with a chart area */
function chart_panel(string $id, string $title, string $subtitle = '', string $class = '', bool $tall = false): void
{
    ?>
    <section class="panel <?= e($class) ?>" id="<?= e($id) ?>" aria-labelledby="<?= e($id) ?>-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="<?= e($id) ?>-title"><?= e($title) ?></h2>
                <?php if ($subtitle !== ''): ?><p class="panel-subtitle"><?= e($subtitle) ?></p><?php endif; ?>
            </div>
            <button type="button" class="btn btn-sm btn-ghost table-toggle no-print" data-table-toggle aria-pressed="false">
                <i class="fa fa-table me-1" aria-hidden="true"></i>Table
            </button>
        </div>
        <div data-legend class="mb-2"></div>
        <div class="chart-box<?= $tall ? ' tall' : '' ?>"></div>
    </section>
    <?php
}
