<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function url(string $page = 'dashboard', array $params = []): string
{
    $query = array_merge(['page' => $page], $params);
    return 'index.php?' . http_build_query($query);
}

function redirect(string $page = 'dashboard', array $params = []): never
{
    header('Location: ' . url($page, $params));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['_csrf'] ?? '', (string)$token)) {
        http_response_code(419);
        exit('Your form expired. Please go back and try again.');
    }
}

function money(mixed $amount): string
{
    return 'GHS ' . number_format((float)$amount, 2);
}

function post_string(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function post_float(string $key, float $default = 0): float
{
    $value = $_POST[$key] ?? $default;
    return is_numeric($value) ? (float)$value : $default;
}

function selected(mixed $a, mixed $b): string
{
    return (string)$a === (string)$b ? ' selected' : '';
}

function checked(bool $value): string
{
    return $value ? ' checked' : '';
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function audit(string $action, string $entity, ?int $entityId = null): void
{
    $user = current_user();
    if (!$user) {
        return;
    }
    db()->prepare(
        'INSERT INTO audit_logs (user_id, action, entity, entity_id, ip) VALUES (?, ?, ?, ?, ?)'
    )->execute([$user['id'], $action, $entity, $entityId, client_ip()]);
}

function setting_value(string $key, mixed $fallback = ''): mixed
{
    $settings = app_settings();
    return array_key_exists($key, $settings) ? $settings[$key] : $fallback;
}