<?php
/*
 * Shared MySQL connection, configured through DB_HOST, DB_NAME, DB_USER and DB_PASSWORD.
 * require_once lets the layout, page and action reuse one connection per request.
 * Strict MySQLi errors reach the caller, which chooses the appropriate user-facing message.
 */

declare(strict_types=1);

// Environment overrides also let integration tests use a separate database.
$host = getenv('DB_HOST') ?: 'mysql';
$database = getenv('DB_NAME') ?: 'circuleather';
$username = getenv('DB_USER') ?: 'student';
$dbPassword = getenv('DB_PASSWORD') ?: 'veiligwachtwoord';

// Throw database errors instead of returning false from queries; keep Unicode intact.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli($host, $username, $dbPassword, $database);
    $mysqli->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    throw new RuntimeException('Could not connect to the database.', 0, $exception);
}
