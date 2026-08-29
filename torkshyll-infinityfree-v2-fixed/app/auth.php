<?php
declare(strict_types=1);

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id > 0) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch() ?: null;
        if (!$user) {
            unset($_SESSION['user_id']);
        }
    }
    return $user;
}

function login_user(string $email, string $password): bool
{
    $email = strtolower(trim($email));
    $recent = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    );
    $recent->execute([$email]);
    if ((int)$recent->fetchColumn() >= 5) {
        return false;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !(bool)$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        db()->prepare('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, NOW())')
            ->execute([$email, client_ip()]);
        return false;
    }

    db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['last_activity'] = time();
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    audit('login', 'user', (int)$user['id']);
    return true;
}

function logout_user(): void
{
    $user = current_user();
    if ($user) {
        audit('logout', 'user', (int)$user['id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login');
    }
    $timeout = 60 * 60 * 8;
    if ((int)($_SESSION['last_activity'] ?? 0) + $timeout < time()) {
        logout_user();
        flash('warning', 'Your session expired. Please sign in again.');
        redirect('login');
    }
    $_SESSION['last_activity'] = time();
}

function require_manager(): void
{
    require_login();
    if (current_user()['role'] !== 'manager') {
        http_response_code(403);
        exit('403 — Managers only');
    }
}

function can(string $page): bool
{
    $user = current_user();
    if (!$user) {
        return $page === 'login';
    }
    if ($user['role'] === 'manager') {
        return true;
    }
    return in_array($page, ['dashboard', 'inventory', 'sales', 'pos', 'receipt'], true);
}