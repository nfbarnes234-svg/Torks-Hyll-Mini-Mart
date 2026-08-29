<?php
declare(strict_types=1);

function render_header(string $title): void
{
    $user = current_user();
    $settings = app_settings();
    $flashes = consume_flashes();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> · <?= e($settings['business_name'] ?? app_config('app.name')) ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/app.css">
    </head>
    <body class="<?= $user ? 'app-shell' : 'auth-shell' ?>">
    <?php if ($user): ?>
        <aside class="sidebar">
            <a class="brand" href="<?= e(url('dashboard')) ?>">
                <span class="brand-mark">T<span>&</span>H</span>
                <span><strong>Torks & Hyll</strong><small>Store operations</small></span>
            </a>
            <nav class="nav">
                <div class="nav-label">Workspace</div>
                <?php
                $items = [
                    ['dashboard', 'Overview', '⌂'],
                    ['pos', 'Point of sale', '＋'],
                    ['inventory', 'Inventories', '▤'],
                    ['sales', 'Sales made', '↗'],
                ];
                if ($user['role'] === 'manager') {
                    $items[] = ['import', 'Import data', '⇩'];
                    $items[] = ['categories', 'Categories', '◫'];
                    $items[] = ['users', 'Team access', '◎'];
                    $items[] = ['settings', 'Settings', '⚙'];
                }
                foreach ($items as [$page, $label, $icon]):
                    ?>
                    <a class="<?= ($_GET['page'] ?? 'dashboard') === $page ? 'active' : '' ?>" href="<?= e(url($page)) ?>">
                        <span class="nav-icon"><?= e($icon) ?></span><?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-foot">
                <div class="status-dot"><i></i> System online</div>
                <a class="user-chip" href="<?= e(url('settings')) ?>">
                    <span class="avatar"><?= e(strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1))) ?></span>
                    <span><strong><?= e($user['first_name'] . ' ' . $user['last_name']) ?></strong><small><?= e(ucfirst($user['role'])) ?></small></span>
                </a>
            </div>
        </aside>
        <main class="main">
            <header class="topbar">
                <div class="mobile-brand"><span class="brand-mark">T<span>&</span>H</span> Torks & Hyll</div>
                <div class="topbar-right">
                    <span class="date-label"><?= e(date('D, d M Y')) ?></span>
                    <a class="topbar-user" href="<?= e(url('settings')) ?>">
                        <?= e($user['first_name'] . ' ' . $user['last_name']) ?>
                        <span class="role-pill"><?= e(strtoupper($user['role'])) ?></span>
                    </a>
                    <a class="logout" href="<?= e(url('logout')) ?>">Log out</a>
                </div>
            </header>
            <div class="page">
                <?php foreach ($flashes as $flash): ?>
                    <div class="toast toast-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
                <?php endforeach; ?>
    <?php else: ?>
        <?php foreach ($flashes as $flash): ?>
            <div class="toast toast-<?= e($flash['type']) ?> auth-toast"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
}

function render_footer(): void
{
    ?>
            </div>
        </main>
    <script src="assets/js/app.js"></script>
    </body>
    </html>
    <?php
}