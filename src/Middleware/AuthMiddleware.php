<?php

namespace PantonePredictor\Middleware;

class AuthMiddleware
{
    private array $publicRoutes = [
        '/login',
    ];

    public function handle(callable $next): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = '/' . trim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        // Allow public routes
        foreach ($this->publicRoutes as $route) {
            if ($uri === $route) {
                $next();
                return;
            }
        }

        // Check authentication
        if (empty($_SESSION['_user'])) {
            if (str_starts_with($uri, '/api/')) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Not authenticated']);
                return;
            }
            redirect('/login');
            return;
        }

        // Admin-only routes
        $adminRoutes = ['/settings', '/setup'];
        foreach ($adminRoutes as $route) {
            if (str_starts_with($uri, $route) && !is_admin()) {
                http_response_code(403);
                echo '<h1>403 — Access Denied</h1>';
                return;
            }
        }

        $next();
    }
}
