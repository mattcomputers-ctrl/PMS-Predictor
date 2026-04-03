<?php

namespace PantonePredictor\Controllers;

use PantonePredictor\Core\CSRF;
use PantonePredictor\Core\Database;

class AuthController
{
    public function loginForm(): void
    {
        if (!empty($_SESSION['_user'])) {
            redirect('/');
            return;
        }
        $pageTitle = 'Login';
        include dirname(__DIR__) . '/Views/auth/login.php';
    }

    public function login(): void
    {
        CSRF::validateRequest();

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $_SESSION['_flash']['error'] = 'Username and password are required.';
            redirect('/login');
            return;
        }

        $db   = Database::getInstance();
        $user = $db->fetch("SELECT * FROM users WHERE username = ?", [$username]);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $_SESSION['_flash']['error'] = 'Invalid credentials.';
            redirect('/login');
            return;
        }

        $db->query("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$user['id']]);

        $_SESSION['_user'] = [
            'id'           => $user['id'],
            'username'     => $user['username'],
            'display_name' => $user['display_name'],
            'is_admin'     => (bool) $user['is_admin'],
        ];

        redirect('/');
    }

    public function logout(): void
    {
        $session = new \PantonePredictor\Core\Session();
        $session->destroy();
        redirect('/login');
    }
}
