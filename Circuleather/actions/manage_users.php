<?php

if (!defined('CIRCULEATHER_APP') || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit;
}

$postedToken = $_POST['csrf_token'] ?? '';
if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
    http_response_code(403);
    exit;
}

$redirectUsers = static function (string $result): never {
    header('Location: ?page=users&' . rawurlencode($result) . '=1');
    exit;
};

$action = isset($_POST['delete']) ? 'delete' : ($_POST['action'] ?? '');
if (!is_string($action) || !in_array($action, ['create', 'update', 'delete', 'reset_password'], true)) {
    $redirectUsers('invalid');
}

$transactionStarted = false;
try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../lib/notifications.php';
    $actorName = (string) ($_SESSION['full_name'] ?? 'System');

    if ($action === 'create') {
        $fullName = trim(is_string($_POST['full_name'] ?? null) ? $_POST['full_name'] : '');
        $email = mb_strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $role = $_POST['role'] ?? 'worker';
        if (
            $fullName === '' || mb_strlen($fullName) > 120
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
            || strlen($password) < 10 || strlen($password) > 72
            || !is_string($role) || !in_array($role, ['admin', 'worker'], true)
        ) {
            $redirectUsers('invalid');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $insertUser = $mysqli->prepare(
            'INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)'
        );
        $insertUser->bind_param('ssss', $fullName, $email, $passwordHash, $role);
        $insertUser->execute();
        createAccountNotifications($mysqli, 'created', $fullName, $email, $actorName);
        $redirectUsers('created');
    }

    $rawUserId = $_POST['user_id'] ?? '';
    if (!is_string($rawUserId) || !ctype_digit($rawUserId) || (int) $rawUserId < 1) {
        $redirectUsers('invalid');
    }
    $userId = (int) $rawUserId;
    $currentUserId = (int) $_SESSION['user_id'];

    $mysqli->begin_transaction();
    $transactionStarted = true;
    $findUser = $mysqli->prepare('SELECT id, full_name, email, role, is_active FROM users WHERE id = ? FOR UPDATE');
    $findUser->bind_param('i', $userId);
    $findUser->execute();
    $targetUser = $findUser->get_result()->fetch_assoc();
    if (!$targetUser) {
        $mysqli->rollback();
        $redirectUsers('not_found');
    }

    if ($action === 'reset_password') {
        $password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        if (strlen($password) < 10 || strlen($password) > 72) {
            $mysqli->rollback();
            $redirectUsers('password_invalid');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $resetPassword = $mysqli->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $resetPassword->bind_param('si', $passwordHash, $userId);
        $resetPassword->execute();
        $mysqli->commit();
        $transactionStarted = false;
        createAccountNotifications($mysqli, 'password_reset', $targetUser['full_name'], $targetUser['email'], $actorName);
        $redirectUsers('password_saved');
    }

    if ($action === 'delete') {
        if ($userId === $currentUserId) {
            $mysqli->rollback();
            $redirectUsers('self');
        }
        if ($targetUser['role'] === 'admin' && (bool) $targetUser['is_active']) {
            $activeAdmins = $mysqli->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE");
            if ($activeAdmins->num_rows <= 1) {
                $mysqli->rollback();
                $redirectUsers('last_admin');
            }
        }
        $deleteUser = $mysqli->prepare('DELETE FROM users WHERE id = ?');
        $deleteUser->bind_param('i', $userId);
        $deleteUser->execute();
        $mysqli->commit();
        $transactionStarted = false;
        createAccountNotifications($mysqli, 'deleted', $targetUser['full_name'], $targetUser['email'], $actorName);
        $redirectUsers('deleted');
    }

    $fullName = trim(is_string($_POST['full_name'] ?? null) ? $_POST['full_name'] : '');
    $email = mb_strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    $role = $_POST['role'] ?? $targetUser['role'];
    $activeValue = $_POST['is_active'] ?? (string) (int) $targetUser['is_active'];
    if (
        $fullName === '' || mb_strlen($fullName) > 120
        || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
        || !is_string($role) || !in_array($role, ['admin', 'worker'], true)
        || !is_string($activeValue) || !in_array($activeValue, ['0', '1'], true)
    ) {
        $mysqli->rollback();
        $redirectUsers('invalid');
    }

    if ($userId === $currentUserId && ($role !== $targetUser['role'] || (int) $activeValue !== (int) $targetUser['is_active'])) {
        $mysqli->rollback();
        $redirectUsers('self');
    }

    if (
        $targetUser['role'] === 'admin' && (bool) $targetUser['is_active']
        && ($role !== 'admin' || $activeValue !== '1')
    ) {
        $activeAdmins = $mysqli->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE");
        if ($activeAdmins->num_rows <= 1) {
            $mysqli->rollback();
            $redirectUsers('last_admin');
        }
    }

    $updateUser = $mysqli->prepare(
        'UPDATE users SET full_name = ?, email = ?, role = ?, is_active = ? WHERE id = ?'
    );
    $isActive = (int) $activeValue;
    $changePairs = [
        'full_name' => [$targetUser['full_name'], $fullName],
        'email' => [$targetUser['email'], $email],
        'role' => [$targetUser['role'], $role],
        'is_active' => [$targetUser['is_active'], $isActive],
    ];
    $changes = [];
    foreach ($changePairs as $field => [$before, $after]) {
        if ((string) $before !== (string) $after) {
            $changes[$field] = ['before' => (string) $before, 'after' => (string) $after];
        }
    }
    $updateUser->bind_param('sssii', $fullName, $email, $role, $isActive, $userId);
    $updateUser->execute();
    $mysqli->commit();
    $transactionStarted = false;
    createAccountNotifications($mysqli, 'updated', $fullName, $email, $actorName, $changes);
    $redirectUsers('saved');
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $mysqli->rollback();
    }
    error_log('User management failed: ' . $exception->getMessage());
    $redirectUsers($exception->getCode() === 1062 ? 'duplicate' : 'error');
} catch (Throwable $exception) {
    if ($transactionStarted) {
        try {
            $mysqli->rollback();
        } catch (Throwable) {
        }
    }
    error_log('User management failed: ' . $exception->getMessage());
    $redirectUsers('error');
}