<?php

declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'mysql';
$database = getenv('DB_NAME') ?: 'circuleather';
$username = getenv('DB_USER') ?: 'student';
$password = getenv('DB_PASSWORD') ?: 'veiligwachtwoord';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli($host, $username, $password, $database);
    $mysqli->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    throw new RuntimeException('Could not connect to the database.', 0, $exception);
}
