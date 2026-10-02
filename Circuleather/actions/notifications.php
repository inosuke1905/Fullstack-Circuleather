<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$userId || $userId < 1) {
    $respond(401, ['error' => 'unauthenticated']);
}

try {
    require __DIR__ . '/../db.php';
    $account = $mysqli->prepare('SELECT role, is_active FROM users WHERE id = ? LIMIT 1');
    $account->bind_param('i', $userId);
    $account->execute();
    $currentAccount = $account->get_result()->fetch_assoc();
    if (!$currentAccount || !(bool) $currentAccount['is_active']) {
        $respond(401, ['error' => 'unauthenticated']);
    }
    $_SESSION['role'] = $currentAccount['role'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'feed') {
        $mysqli->begin_transaction();
        if ($currentAccount['role'] !== 'admin') {
            $clearAdminAlerts = $mysqli->prepare(
                "UPDATE notifications SET read_at = CURRENT_TIMESTAMP
                 WHERE recipient_user_id = ? AND type = 'account_activity' AND read_at IS NULL"
            );
            $clearAdminAlerts->bind_param('i', $userId);
            $clearAdminAlerts->execute();
        }
        $pendingQuery = $mysqli->prepare(
            'SELECT id, type, event_action, title, message, actor_name, target_url, created_at, details_json
             FROM notifications
             WHERE recipient_user_id = ? AND read_at IS NULL AND delivered_at IS NULL
             ORDER BY id DESC LIMIT 10 FOR UPDATE'
        );
        $pendingQuery->bind_param('i', $userId);
        $pendingQuery->execute();
        $pending = $pendingQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($pending as &$notification) {
            $notification['details'] = $notification['details_json'] === null
                ? []
                : (json_decode($notification['details_json'], true) ?: []);
            unset($notification['details_json']);
        }
        unset($notification);
        $markDelivered = $mysqli->prepare(
            'UPDATE notifications SET delivered_at = CURRENT_TIMESTAMP
             WHERE id = ? AND recipient_user_id = ? AND delivered_at IS NULL'
        );
        foreach ($pending as $notification) {
            $notificationId = (int) $notification['id'];
            $markDelivered->bind_param('ii', $notificationId, $userId);
            $markDelivered->execute();
        }

        $recentQuery = $mysqli->prepare(
            'SELECT id, type, event_action, title, message, actor_name, target_url, created_at, details_json
             FROM notifications WHERE recipient_user_id = ? AND read_at IS NULL
             ORDER BY id DESC LIMIT 20'
        );
        $recentQuery->bind_param('i', $userId);
        $recentQuery->execute();
        $recent = $recentQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($recent as &$notification) {
            $notification['details'] = $notification['details_json'] === null
                ? []
                : (json_decode($notification['details_json'], true) ?: []);
            unset($notification['details_json']);
        }
        unset($notification);
        $unreadQuery = $mysqli->prepare(
            'SELECT COUNT(*) AS unread_count FROM notifications WHERE recipient_user_id = ? AND read_at IS NULL'
        );
        $unreadQuery->bind_param('i', $userId);
        $unreadQuery->execute();
        $unreadCount = (int) $unreadQuery->get_result()->fetch_assoc()['unread_count'];
        $mysqli->commit();
        $respond(200, ['notifications' => $pending, 'recent' => $recent, 'unreadCount' => $unreadCount]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($_POST['action'] ?? '', ['read', 'read_all'], true)) {
        $respond(400, ['error' => 'invalid_request']);
    }
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
        $respond(403, ['error' => 'invalid_token']);
    }

    if ($_POST['action'] === 'read_all') {
        $markAllRead = $mysqli->prepare(
            'UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE recipient_user_id = ? AND read_at IS NULL'
        );
        $markAllRead->bind_param('i', $userId);
        $markAllRead->execute();
    } else {
        $rawNotificationId = $_POST['id'] ?? '';
        if (!is_string($rawNotificationId) || !ctype_digit($rawNotificationId) || (int) $rawNotificationId < 1) {
            $respond(400, ['error' => 'invalid_notification']);
        }
        $notificationId = (int) $rawNotificationId;
        $markRead = $mysqli->prepare(
            'UPDATE notifications SET read_at = CURRENT_TIMESTAMP
             WHERE id = ? AND recipient_user_id = ? AND read_at IS NULL'
        );
        $markRead->bind_param('ii', $notificationId, $userId);
        $markRead->execute();
    }

    $unreadQuery = $mysqli->prepare(
        'SELECT COUNT(*) AS unread_count FROM notifications WHERE recipient_user_id = ? AND read_at IS NULL'
    );
    $unreadQuery->bind_param('i', $userId);
    $unreadQuery->execute();
    $respond(200, ['unreadCount' => (int) $unreadQuery->get_result()->fetch_assoc()['unread_count']]);
} catch (Throwable $exception) {
    if (isset($mysqli) && $mysqli->errno !== 0) {
        try {
            $mysqli->rollback();
        } catch (Throwable) {
        }
    }
    error_log('Notification request failed: ' . $exception->getMessage());
    $respond(500, ['error' => 'notification_unavailable']);
}