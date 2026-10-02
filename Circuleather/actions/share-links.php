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
if (!$userId || $userId < 1 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(401, ['error' => 'unauthenticated']);
}

$postedToken = $_POST['csrf_token'] ?? '';
if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
    $respond(403, ['error' => 'invalid_token']);
}

$resourceType = $_POST['resource_type'] ?? '';
$rawResourceId = $_POST['resource_id'] ?? '';
$action = $_POST['action'] ?? '';
if (
    !is_string($resourceType) || !in_array($resourceType, ['batch', 'piece', 'order'], true)
    || !is_string($rawResourceId) || !ctype_digit($rawResourceId) || (int) $rawResourceId < 1
    || !is_string($action) || !in_array($action, ['create', 'revoke_all'], true)
) {
    $respond(400, ['error' => 'invalid_request']);
}
$resourceId = (int) $rawResourceId;
$tables = ['batch' => 'batches', 'piece' => 'individual_pieces', 'order' => 'orders'];

try {
    require __DIR__ . '/../db.php';
    $account = $mysqli->prepare('SELECT full_name, language, is_active FROM users WHERE id = ? LIMIT 1');
    $account->bind_param('i', $userId);
    $account->execute();
    $currentUser = $account->get_result()->fetch_assoc();
    if (!$currentUser || !(bool) $currentUser['is_active']) {
        $respond(401, ['error' => 'unauthenticated']);
    }

    $table = $tables[$resourceType];
    $resource = $mysqli->prepare("SELECT id FROM {$table} WHERE id = ? LIMIT 1");
    $resource->bind_param('i', $resourceId);
    $resource->execute();
    if (!$resource->get_result()->fetch_assoc()) {
        $respond(404, ['error' => 'resource_not_found']);
    }

    if ($action === 'create') {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $creatorName = (string) $currentUser['full_name'];
        $language = in_array($currentUser['language'], ['nl', 'en'], true) ? $currentUser['language'] : 'nl';
        $insert = $mysqli->prepare(
            'INSERT INTO share_links (token_hash, resource_type, resource_id, created_by_user_id, created_by_name, language)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->bind_param('ssiiss', $tokenHash, $resourceType, $resourceId, $userId, $creatorName, $language);
        $insert->execute();
        $shareUrl = 'share.php?token=' . $token;
    } else {
        $revoke = $mysqli->prepare(
            'UPDATE share_links SET revoked_at = CURRENT_TIMESTAMP
             WHERE resource_type = ? AND resource_id = ? AND revoked_at IS NULL'
        );
        $revoke->bind_param('si', $resourceType, $resourceId);
        $revoke->execute();
        $shareUrl = null;
    }

    $count = $mysqli->prepare(
        'SELECT COUNT(*) AS total FROM share_links
         WHERE resource_type = ? AND resource_id = ? AND revoked_at IS NULL
            AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)'
    );
    $count->bind_param('si', $resourceType, $resourceId);
    $count->execute();
    $activeCount = (int) $count->get_result()->fetch_assoc()['total'];
    $respond(200, ['shareUrl' => $shareUrl, 'activeCount' => $activeCount]);
} catch (Throwable $exception) {
    error_log('Share link request failed: ' . $exception->getMessage());
    $respond(500, ['error' => 'share_unavailable']);
}