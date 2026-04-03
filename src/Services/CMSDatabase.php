<?php

declare(strict_types=1);

namespace PantonePredictor\Services;

use PantonePredictor\Core\Database;

class CMSDatabase
{
    private static ?self $instance = null;
    private ?\PDO $pdo = null;
    private array $config;

    private function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $db = Database::getInstance();
            $config = [
                'host'     => self::getSetting($db, 'cms_host'),
                'port'     => (int) self::getSetting($db, 'cms_port', '1433'),
                'database' => self::getSetting($db, 'cms_database', 'CMS'),
                'username' => self::getSetting($db, 'cms_username'),
                'password' => self::getSetting($db, 'cms_password'),
            ];
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function isConfigured(): bool
    {
        return is_cms_configured();
    }

    private static function getSetting(Database $db, string $key, string $default = ''): string
    {
        $row = $db->fetch("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        return $row['setting_value'] ?? $default;
    }

    private function connect(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = $this->config['host'];
        $port = $this->config['port'];
        $name = $this->config['database'];

        if (in_array('sqlsrv', \PDO::getAvailableDrivers(), true)) {
            $dsn = sprintf(
                'sqlsrv:Server=%s,%d;Database=%s;TrustServerCertificate=yes;LoginTimeout=5',
                $host, $port, $name
            );
        } elseif (in_array('dblib', \PDO::getAvailableDrivers(), true)) {
            $dsn = sprintf('dblib:host=%s:%d;dbname=%s', $host, $port, $name);
        } else {
            throw new \RuntimeException(
                'No SQL Server PDO driver available. Install php_pdo_sqlsrv or php_pdo_dblib.'
            );
        }

        $this->pdo = new \PDO($dsn, $this->config['username'], $this->config['password'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        return $this->pdo;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testConnection(): array
    {
        try {
            $pdo = $this->connect();
            $row = $pdo->query("SELECT @@VERSION AS version")->fetch(\PDO::FETCH_ASSOC);
            return [
                'success' => true,
                'message' => 'Connected successfully',
                'version' => $row['version'] ?? 'Unknown',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'version' => null,
            ];
        }
    }
}
