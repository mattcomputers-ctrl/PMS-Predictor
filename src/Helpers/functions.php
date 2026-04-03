<?php

use PantonePredictor\Core\App;
use PantonePredictor\Core\CSRF;

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(): string
{
    return CSRF::field();
}

function csrf_token(): string
{
    return CSRF::token();
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash_messages(): string
{
    $html = '';
    if (!empty($_SESSION['_flash'])) {
        foreach ($_SESSION['_flash'] as $type => $message) {
            $html .= '<div class="alert alert-' . e($type) . '">' . e($message) . '</div>';
        }
        unset($_SESSION['_flash']);
    }
    return $html;
}

function old(string $key, string $default = ''): string
{
    return $_SESSION['_old_input'][$key] ?? $_POST[$key] ?? $default;
}

function current_user(): ?array
{
    return $_SESSION['_user'] ?? null;
}

function current_user_id(): ?int
{
    return isset($_SESSION['_user']['id']) ? (int) $_SESSION['_user']['id'] : null;
}

function is_admin(): bool
{
    return (bool) ($_SESSION['_user']['is_admin'] ?? false);
}

function is_cms_configured(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $db = \PantonePredictor\Core\Database::getInstance();
        $row = $db->fetch("SELECT setting_value FROM settings WHERE setting_key = 'cms_configured'");
        $cached = ($row['setting_value'] ?? '0') === '1';
    } catch (\Throwable) {
        $cached = false;
    }
    return $cached;
}

function get_setting(string $key, string $default = ''): string
{
    try {
        $db = \PantonePredictor\Core\Database::getInstance();
        $row = $db->fetch("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        return $row['setting_value'] ?? $default;
    } catch (\Throwable) {
        return $default;
    }
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function format_date(?string $date, string $format = 'm/d/Y H:i'): string
{
    if (!$date) return '';
    try {
        return (new \DateTime($date))->format($format);
    } catch (\Throwable) {
        return $date;
    }
}
