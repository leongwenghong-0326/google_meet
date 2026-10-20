<?php
// Initialize database using PDO
$host = 'localhost';
$port = 3306;
$user = 'root';
$pass = 'Hong2007';

try {
    $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    $sqlFile = dirname(__DIR__) . '/database/install.sql';
    if (!file_exists($sqlFile)) {
        die("Error: install.sql not found at $sqlFile\n");
    }
    
    $dbName = 'connectmeet_db';
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");

    $sql = file_get_contents($sqlFile);
    $pdo->exec($sql);
    echo "SUCCESS: Database and tables created successfully in $dbName!\n";

    // Verify tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Created tables: " . implode(', ', $tables) . "\n";
    
} catch (PDOException $e) {
    echo "PDO Error: " . $e->getMessage() . "\n";
    exit(1);
}
