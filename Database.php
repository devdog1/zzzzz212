<?php

class Database {
    private $pdo;

    public function __construct() {
        // Check if we are in a testing environment or if MySQL is unavailable
        if (getenv('USE_SQLITE') === 'true') {
            $this->pdo = new PDO("sqlite:event_mgmt.sqlite");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return;
        }

        // Load configuration using DOCUMENT_ROOT
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
        $configPath = rtrim($docRoot, '/\\') . '/inc/config.php';

        if (file_exists($configPath)) {
            require $configPath;
        } else {
            throw new Exception("Configuration file not found at $configPath");
        }

        $dbConfig = $config['db']['events'];
        $dsn = "mysql:host={$dbConfig['dbhost']};dbname={$dbConfig['dbname']};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->pdo = new PDO($dsn, $dbConfig['dbuser'], $dbConfig['dbpass'], $options);
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
}
