<?php

if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

$postedToken = $_POST['csrf_token'] ?? '';
if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
    if ($requestedPage === 'logout') {
        http_response_code(403);
        exit;
    }
    $authError = t('Het formulier is verlopen. Vernieuw de pagina en probeer opnieuw.', 'The form expired. Refresh the page and try again.');
    return;
}

if ($requestedPage === 'logout') {
    try {
        require_once __DIR__ . '/../db.php';
        $accountSettings = array_merge(
            $defaults,
            is_array($_SESSION['settings'] ?? null) ? $_SESSION['settings'] : []
        );
        $userId = (int) $_SESSION['user_id'];
        $lowStockNotifications = (int) $accountSettings['low_stock_notifications'];
        $orderNotifications = (int) $accountSettings['order_notifications'];
        $saveSettings = $mysqli->prepare(
            'UPDATE users SET language = ?, theme = ?,
                low_stock_notifications = ?, order_notifications = ? WHERE id = ?'
        );
        $saveSettings->bind_param(
            'ssiii',
            $accountSettings['language'],
            $accountSettings['theme'],
            $lowStockNotifications,
            $orderNotifications,
            $userId
        );
        $saveSettings->execute();
    } catch (Throwable $exception) {
        error_log('Could not save account preferences on logout: ' . $exception->getMessage());
    }

    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header('Location: ?page=login&logged_out=1');
    exit;
}

$authValue = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
};

$email = mb_strtolower($authValue('email'));
$password = $_POST['password'] ?? '';
$password = is_string($password) ? $password : '';

if ($requestedPage === 'login') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $authError = t('E-mailadres of wachtwoord is onjuist.', 'Email or password is incorrect.');
        return;
    }

    try {
        require_once __DIR__ . '/../db.php';
        $statement = $mysqli->prepare(
            'SELECT id, full_name, email, password_hash, language, theme,
                low_stock_notifications, order_notifications
             FROM users WHERE email = ? LIMIT 1'
        );
        $statement->bind_param('s', $email);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $authError = t('E-mailadres of wachtwoord is onjuist.', 'Email or password is incorrect.');
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['settings'] = [
            'language' => in_array($user['language'], ['nl', 'en'], true) ? $user['language'] : 'nl',
            'theme' => in_array($user['theme'], ['light', 'dark', 'system'], true) ? $user['theme'] : 'light',
            'low_stock_notifications' => (bool) $user['low_stock_notifications'],
            'order_notifications' => (bool) $user['order_notifications'],
        ];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ?page=inventory');
        exit;
    } catch (Throwable $exception) {
        error_log('Login failed: ' . $exception->getMessage());
        $authError = t('Inloggen is tijdelijk niet mogelijk. Probeer het later opnieuw.', 'Login is temporarily unavailable. Please try again later.');
        return;
    }
}

if ($requestedPage === 'register') {
    $fullName = $authValue('full_name');
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $confirmPassword = is_string($confirmPassword) ? $confirmPassword : '';

    if (
        $fullName === ''
        || mb_strlen($fullName) > 120
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 254
        || strlen($password) < 10
        || strlen($password) > 72
        || $password !== $confirmPassword
    ) {
        $authError = t(
            'Controleer je naam, e-mailadres en wachtwoord. Het wachtwoord moet minimaal 10 tekens bevatten en beide invoervelden moeten overeenkomen.',
            'Check your name, email, and password. Passwords must be at least 10 characters and both entries must match.'
        );
        return;
    }

    try {
        require_once __DIR__ . '/../db.php';
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $statement = $mysqli->prepare('INSERT INTO users (full_name, email, password_hash) VALUES (?, ?, ?)');
        $statement->bind_param('sss', $fullName, $email, $passwordHash);
        $statement->execute();

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $mysqli->insert_id;
        $_SESSION['full_name'] = $fullName;
        $_SESSION['email'] = $email;
        $_SESSION['settings'] = $defaults;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: ?page=inventory');
        exit;
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062) {
            $authError = t('Er bestaat al een account met dit e-mailadres.', 'An account with this email already exists.');
            return;
        }
        error_log('Registration failed: ' . $exception->getMessage());
        $authError = t('Registreren is tijdelijk niet mogelijk. Probeer het later opnieuw.', 'Registration is temporarily unavailable. Please try again later.');
    } catch (Throwable $exception) {
        error_log('Registration failed: ' . $exception->getMessage());
        $authError = t('Registreren is tijdelijk niet mogelijk. Probeer het later opnieuw.', 'Registration is temporarily unavailable. Please try again later.');
    }
}
