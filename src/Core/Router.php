<?php

namespace PantonePredictor\Core;

class Router
{
    private array $routes = ['GET' => [], 'POST' => []];
    private array $middleware = [];
    private string $groupPrefix = '';

    public function get(string $pattern, string|callable $handler): self
    {
        $this->addRoute('GET', $pattern, $handler);
        return $this;
    }

    public function post(string $pattern, string|callable $handler): self
    {
        $this->addRoute('POST', $pattern, $handler);
        return $this;
    }

    public function group(string $prefix, callable $callback): self
    {
        $prev = $this->groupPrefix;
        $this->groupPrefix = $prev . $prefix;
        $callback($this);
        $this->groupPrefix = $prev;
        return $this;
    }

    public function addMiddleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function dispatch(string $method, string $uri): void
    {
        $uri = parse_url($uri, PHP_URL_PATH) ?: '/';
        $uri = '/' . trim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }
        $method = strtoupper($method);

        $matched = null;
        $params  = [];

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $uri, $matches)) {
                $matched = $route;
                foreach ($route['paramNames'] as $name) {
                    $params[$name] = $matches[$name] ?? null;
                }
                break;
            }
        }

        if ($matched === null) {
            $this->sendNotFound();
            return;
        }

        $handler  = $matched['handler'];
        $dispatch = function () use ($handler, $params) {
            $this->callHandler($handler, $params);
        };

        $pipeline = $dispatch;
        foreach (array_reverse($this->middleware) as $mw) {
            $next     = $pipeline;
            $pipeline = function () use ($mw, $next) { $mw($next); };
        }
        $pipeline();
    }

    private function addRoute(string $method, string $pattern, string|callable $handler): void
    {
        $full = $this->groupPrefix . $pattern;
        $full = '/' . trim($full, '/');
        if ($full !== '/') {
            $full = rtrim($full, '/');
        }

        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_]+)\}/', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $full);
        $regex = '#^' . $regex . '$#';

        $this->routes[$method][] = [
            'pattern'    => $full,
            'regex'      => $regex,
            'handler'    => $handler,
            'paramNames' => $paramNames,
        ];
    }

    private function callHandler(string|callable $handler, array $params): void
    {
        if (is_callable($handler) && !is_string($handler)) {
            call_user_func_array($handler, array_values($params));
            return;
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            if (!str_contains($class, '\\')) {
                $class = 'PantonePredictor\\Controllers\\' . $class;
            }
            if (!class_exists($class)) {
                throw new \RuntimeException("Controller [{$class}] not found.");
            }
            $controller = new $class();
            if (!method_exists($controller, $method)) {
                throw new \RuntimeException("Method [{$method}] not found on [{$class}].");
            }
            call_user_func_array([$controller, $method], array_values($params));
            return;
        }

        throw new \RuntimeException('Invalid route handler.');
    }

    private function sendNotFound(): void
    {
        http_response_code(404);
        $viewFile = dirname(__DIR__) . '/Views/errors/404.php';
        if (file_exists($viewFile)) {
            include $viewFile;
        } else {
            echo '<h1>404 — Page Not Found</h1>';
        }
    }
}
