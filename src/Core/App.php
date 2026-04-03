<?php

namespace PantonePredictor\Core;

use PantonePredictor\Middleware\AuthMiddleware;

class App
{
    private static array $config = [];
    private static Database $database;
    private static Session $session;
    private static string $basePath;

    public function __construct()
    {
        self::$basePath = dirname(__DIR__, 2);

        $configFile = self::$basePath . '/config/config.php';
        if (!file_exists($configFile)) {
            throw new \RuntimeException('Configuration file not found: ' . $configFile);
        }
        self::$config = require $configFile;

        date_default_timezone_set(self::config('app.timezone', 'America/New_York'));

        if (self::config('app.debug', false)) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(0);
            ini_set('display_errors', '0');
        }

        self::$database = Database::init(self::$config['db']);
        self::$session = new Session();
        self::$session->start(self::$config['session'] ?? []);
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value    = self::$config;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function db(): Database
    {
        return self::$database;
    }

    public static function session(): Session
    {
        return self::$session;
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    public function run(): void
    {
        $router = new Router();

        $auth = new AuthMiddleware();
        $router->addMiddleware([$auth, 'handle']);

        // Auth routes
        $router->get('/login', 'AuthController@loginForm');
        $router->post('/login', 'AuthController@login');
        $router->get('/logout', 'AuthController@logout');

        // Dashboard
        $router->get('/', 'DashboardController@index');

        // Predictions
        $router->get('/predictions', 'PredictionController@index');
        $router->get('/predictions/saved', 'PredictionController@saved');
        $router->get('/custom-lab', 'PredictionController@customLab');

        // Settings (admin only)
        $router->get('/settings', 'SettingsController@index');
        $router->post('/settings', 'SettingsController@save');
        $router->post('/settings/password', 'SettingsController@changePassword');
        $router->get('/setup', 'SettingsController@setup');
        $router->post('/setup', 'SettingsController@saveSetup');

        // API endpoints (JSON)
        $router->get('/api/series/search', 'ApiController@seriesSearch');
        $router->get('/api/series/formulas', 'ApiController@seriesFormulas');
        $router->post('/api/predictions/generate', 'ApiController@generate');
        $router->post('/api/predictions/save', 'ApiController@savePrediction');
        $router->post('/api/predictions/save-batch', 'ApiController@saveBatch');
        $router->post('/api/predictions/delete', 'ApiController@deletePrediction');
        $router->get('/api/predictions/export', 'ApiController@export');
        $router->post('/api/custom-lab/match', 'ApiController@customLabMatch');
        $router->get('/api/cms/test', 'ApiController@testCmsConnection');

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';

        $router->dispatch($method, $uri);
    }
}
