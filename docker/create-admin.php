<?php
/*
 * CLI-only first-administrator setup for a new client installation.
 * The script is copied outside Apache's document root and cannot be opened in a browser.
 * Reads a password interactively, stores its hash and refuses to replace existing accounts.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function prompt(string $label, bool $secret = false): string
{
    fwrite(STDOUT, $label);
    $hidden = $secret && stream_isatty(STDIN);
    if ($hidden) {
        exec('stty -echo');
    }
    try {
        $value = fgets(STDIN);
        if ($value === false) {
            throw new RuntimeException('Input ended before account details were complete.');
        }
        return rtrim($value, "\r\n");
    } finally {
        if ($hidden) {
            exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }
    }
}

try {
    require '/var/www/html/circuleather/db.php';
    // Initial setup is not a replacement for the administrator's account-management screen.
    if ((int) $mysqli->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin'")->fetch_assoc()['total'] !== 0) {
        throw new RuntimeException('An administrator already exists. Use the website to manage accounts.');
    }

    $name = trim(prompt('Administrator name: '));
    $email = mb_strtolower(trim(prompt('Administrator email: ')));
    $password = prompt('Password (10 to 72 bytes): ', true);
    $confirmation = prompt('Confirm password: ', true);

    if ($name === '' || mb_strlen($name) > 120
        || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
        || strlen($password) < 10 || strlen($password) > 72
        || $password !== $confirmation) {
        throw new RuntimeException('Check the name, email, password length and password confirmation.');
    }

    // Lock account creation so two simultaneous setup commands cannot create two initial admins.
    $lock = $mysqli->query("SELECT GET_LOCK('circuleather-first-admin', 10) AS acquired")->fetch_assoc();
    if ((int) $lock['acquired'] !== 1) {
        throw new RuntimeException('Another setup command is running. Try again after it finishes.');
    }
    try {
        if ((int) $mysqli->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin'")->fetch_assoc()['total'] !== 0) {
            throw new RuntimeException('An administrator was created by another setup command.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $mysqli->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
        $insert->bind_param('sss', $name, $email, $hash);
        $insert->execute();
    } finally {
        $mysqli->query("SELECT RELEASE_LOCK('circuleather-first-admin')");
    }
    fwrite(STDOUT, 'Administrator created. Log in with the email and password you entered.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
