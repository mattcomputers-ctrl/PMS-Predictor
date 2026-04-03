<?php

namespace PantonePredictor\Controllers;

use PantonePredictor\Core\CSRF;
use PantonePredictor\Core\Database;
use PantonePredictor\Services\CMSDatabase;

class SettingsController
{
    public function index(): void
    {
        $pageTitle = 'Settings';
        $db = Database::getInstance();

        $settings = [];
        $rows = $db->fetchAll("SELECT setting_key, setting_value FROM settings");
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        include dirname(__DIR__) . '/Views/settings/index.php';
    }

    public function save(): void
    {
        CSRF::validateRequest();
        $db = Database::getInstance();

        $fields = [
            'cms_host', 'cms_port', 'cms_database',
            'cms_username', 'app_name', 'prediction_k', 'noise_threshold',
        ];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $db->query(
                    "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    [$field, trim($_POST[$field])]
                );
            }
        }

        // Handle password separately (only update if provided)
        if (!empty($_POST['cms_password'])) {
            $db->query(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('cms_password', ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                [trim($_POST['cms_password'])]
            );
        }

        // Test connection if host is provided
        $host = trim($_POST['cms_host'] ?? '');
        if ($host !== '') {
            CMSDatabase::reset();
            try {
                $result = CMSDatabase::getInstance()->testConnection();
                if ($result['success']) {
                    $db->query(
                        "INSERT INTO settings (setting_key, setting_value) VALUES ('cms_configured', '1')
                         ON DUPLICATE KEY UPDATE setting_value = '1'"
                    );
                    $_SESSION['_flash']['success'] = 'Settings saved. CMS connection verified.';
                } else {
                    $_SESSION['_flash']['error'] = 'Settings saved but CMS connection failed: ' . $result['message'];
                }
            } catch (\Throwable $e) {
                $_SESSION['_flash']['error'] = 'Settings saved but CMS connection failed: ' . $e->getMessage();
            }
        } else {
            $_SESSION['_flash']['success'] = 'Settings saved.';
        }

        redirect('/settings');
    }

    public function changePassword(): void
    {
        CSRF::validateRequest();
        $db = Database::getInstance();

        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($new === '' || $current === '') {
            $_SESSION['_flash']['error'] = 'All password fields are required.';
            redirect('/settings');
            return;
        }

        if (strlen($new) < 8) {
            $_SESSION['_flash']['error'] = 'New password must be at least 8 characters.';
            redirect('/settings');
            return;
        }

        if ($new !== $confirm) {
            $_SESSION['_flash']['error'] = 'New passwords do not match.';
            redirect('/settings');
            return;
        }

        $user = $db->fetch("SELECT * FROM users WHERE id = ?", [current_user_id()]);
        if (!$user || !password_verify($current, $user['password_hash'])) {
            $_SESSION['_flash']['error'] = 'Current password is incorrect.';
            redirect('/settings');
            return;
        }

        $hash = password_hash($new, PASSWORD_BCRYPT);
        $db->query("UPDATE users SET password_hash = ? WHERE id = ?", [$hash, current_user_id()]);

        $_SESSION['_flash']['success'] = 'Password updated successfully.';
        redirect('/settings');
    }

    public function setup(): void
    {
        if (is_cms_configured()) {
            redirect('/settings');
            return;
        }
        $pageTitle = 'Initial Setup';
        include dirname(__DIR__) . '/Views/settings/setup.php';
    }

    public function saveSetup(): void
    {
        // Reuse the save logic
        $this->save();
    }
}
