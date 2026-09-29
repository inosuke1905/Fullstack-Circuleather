<?php

declare(strict_types=1);

define('CIRCULEATHER_APP', true);

if (is_file(__DIR__ . '/database/.inventory-migration')) {
    http_response_code(503);
    header('Retry-After: 60');
    exit('Inventory is being updated. Please try again shortly.');
}

$defaults = [
    'language' => 'nl',
    'theme' => 'light',
    'low_stock_notifications' => true,
    'order_notifications' => true,
];

session_start();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$settings = array_merge($defaults, is_array($_SESSION['settings'] ?? null) ? $_SESSION['settings'] : []);
$language = ($settings['language'] ?? 'nl') === 'en' ? 'en' : 'nl';

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function t(string $dutch, string $english): string
{
    global $language;
    return escape($language === 'en' ? $english : $dutch);
}

$requestedPage = $_GET['page'] ?? 'inventory';
if (!is_string($requestedPage)) {
    $requestedPage = 'inventory';
}
$isPublicPage = in_array($requestedPage, ['login', 'register'], true);

if ($isPublicPage && isset($_SESSION['user_id'])) {
    header('Location: ?page=inventory');
    exit;
}
if (!$isPublicPage && !isset($_SESSION['user_id'])) {
    header('Location: ?page=login');
    exit;
}
if ($requestedPage === 'logout' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ?page=inventory');
    exit;
}

$authError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($requestedPage, ['login', 'register', 'logout'], true)) {
    require __DIR__ . '/actions/authenticate.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['page'] ?? '') === 'settings') {
    $languagePreference = $_POST['language'] ?? 'nl';
    $themePreference = $_POST['theme'] ?? 'light';

    $settings = [
        'language' => is_string($languagePreference) && in_array($languagePreference, ['nl', 'en'], true) ? $languagePreference : 'nl',
        'theme' => is_string($themePreference) && in_array($themePreference, ['light', 'dark', 'system'], true) ? $themePreference : 'light',
        'low_stock_notifications' => isset($_POST['low_stock_notifications']),
        'order_notifications' => isset($_POST['order_notifications']),
    ];

    try {
        require_once __DIR__ . '/db.php';
        $userId = (int) $_SESSION['user_id'];
        $lowStockNotifications = (int) $settings['low_stock_notifications'];
        $orderNotifications = (int) $settings['order_notifications'];
        $updateSettings = $mysqli->prepare(
            'UPDATE users SET language = ?, theme = ?,
                low_stock_notifications = ?, order_notifications = ? WHERE id = ?'
        );
        $updateSettings->bind_param(
            'ssiii',
            $settings['language'],
            $settings['theme'],
            $lowStockNotifications,
            $orderNotifications,
            $userId
        );
        $updateSettings->execute();
        $_SESSION['settings'] = $settings;
        header('Location: ?page=settings&saved=1');
    } catch (Throwable $exception) {
        error_log('Could not save account preferences: ' . $exception->getMessage());
        header('Location: ?page=settings&error=1');
    }
    exit;
}

$language = $settings['language'] === 'en' ? 'en' : 'nl';
$settings['theme'] = in_array($settings['theme'], ['light', 'dark', 'system'], true) ? $settings['theme'] : 'light';

$pages = [
    'inventory' => $language === 'en' ? 'Inventory' : 'Inventaris',
    'batch' => $language === 'en' ? 'Add batch' : 'Batch toevoegen',
    'orders' => $language === 'en' ? 'Orders' : 'Bestellingen',
    'new-order' => $language === 'en' ? 'Create order' : 'Order aanmaken',
    'settings' => $language === 'en' ? 'Settings' : 'Instellingen',
];
$navigationPages = array_diff_key($pages, ['settings' => true]);
$authPages = [
    'login' => $language === 'en' ? 'Log in' : 'Inloggen',
    'register' => $language === 'en' ? 'Create account' : 'Account aanmaken',
];

$page = $_GET['page'] ?? 'inventory';
$routes = [...array_keys($pages), 'batch-detail', 'piece-detail', 'order-detail', ...array_keys($authPages), 'logout'];
if (!is_string($page) || !in_array($page, $routes, true)) {
    $page = 'inventory';
}
$rawBatchId = $_GET['id'] ?? '';
$batchDetailId = is_string($rawBatchId) && ctype_digit($rawBatchId) ? (int) $rawBatchId : 0;
$isPieceDetail = $page === 'piece-detail';
$detailTable = $isPieceDetail ? 'individual_pieces' : 'batches';
$detailRoute = $isPieceDetail ? 'piece-detail' : 'batch-detail';
$detailUnit = $isPieceDetail ? 'piece' : 'kg';
$detailInventoryView = $isPieceDetail ? 'pieces' : 'batches';
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'batch';
$batchError = null;
if ($submitted) {
    require __DIR__ . '/actions/save_batch.php';
}
$detailError = null;
if (in_array($page, ['batch-detail', 'piece-detail'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/actions/manage_batch.php';
}
$orderError = null;
$requestedOrderType = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['order_type'] ?? null)
    : ($_GET['type'] ?? null);
$orderType = in_array($requestedOrderType, ['batch', 'pieces'], true) ? $requestedOrderType : 'batch';
if ($page === 'new-order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/actions/save_order.php';
}
$orderDetailId = $batchDetailId;
$orderEditError = null;
$orderEditConflict = false;
if ($page === 'order-detail' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/actions/manage_order.php';
}
$isAuthPage = isset($authPages[$page]);
$title = $pages[$page] ?? $authPages[$page] ?? match ($page) {
    'batch-detail' => $language === 'en' ? 'Batch details' : 'Batchdetails',
    'piece-detail' => $language === 'en' ? 'Leather piece details' : 'Leerstukdetails',
    'order-detail' => $language === 'en' ? 'Order details' : 'Orderdetails',
    default => $language === 'en' ? 'Circuleather' : 'Circuleather',
};
?>
<!doctype html>
<html lang="<?= escape($language) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape($title) ?> | Circuleather</title>
    <link rel="stylesheet" href="style.css?v=<?= hash_file('sha256', __DIR__ . '/style.css') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php if ($page === 'new-order'): ?>
        <script src="order-form.js?v=<?= hash_file('sha256', __DIR__ . '/order-form.js') ?>" defer></script>
    <?php endif; ?>
    <?php if ($page === 'order-detail'): ?>
        <script src="order-detail.js?v=<?= hash_file('sha256', __DIR__ . '/order-detail.js') ?>" defer></script>
    <?php endif; ?>
    <?php if (!$isAuthPage): ?>
        <script src="account-menu.js?v=<?= hash_file('sha256', __DIR__ . '/account-menu.js') ?>" defer></script>
    <?php endif; ?>
</head>
<body data-theme="<?= escape($settings['theme']) ?>">
    <?php if ($isAuthPage): ?>
        <header class="auth-brand">
            <a class="brand" href="?page=<?= isset($_SESSION['user_id']) ? 'inventory' : 'login' ?>"><span class="brand-mark">CL</span><span><strong>CIRCULEATHER</strong><small><?= t('Voorraad & orders', 'Inventory & orders') ?></small></span></a>
        </header>
    <?php else: ?>
    <header class="sidebar">
        <p class="brand"><span class="brand-mark">CL</span><span><strong>CIRCULEATHER</strong><small><?= t('Voorraad & orders', 'Inventory & orders') ?></small></span></p>
        <nav class="sidebar-nav" aria-label="<?= t('Hoofdnavigatie', 'Main navigation') ?>">
            <ul>
                <?php foreach ($navigationPages as $key => $label): ?>
                    <li>
                        <a href="?page=<?= escape($key) ?>"<?= $page === $key || ($key === 'orders' && $page === 'order-detail') ? ' aria-current="page"' : '' ?>><?= escape($label) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <details class="account-menu">
            <summary class="profile">
                <span class="profile-mark"><?= escape(mb_strtoupper(mb_substr($_SESSION['full_name'] ?? 'U', 0, 1))) ?></span>
                <span><strong><?= escape($_SESSION['full_name'] ?? '') ?></strong><small><?= t('Account', 'Account') ?></small></span>
                <span class="account-caret" aria-hidden="true"></span>
            </summary>
            <div class="account-menu-panel">
                <a href="?page=settings"><span class="account-menu-icon account-settings-icon" aria-hidden="true"></span><?= t('Instellingen', 'Settings') ?></a>
                <form action="?page=logout" method="post">
                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                    <button type="submit"><span class="account-menu-icon account-logout-icon" aria-hidden="true"></span><?= t('Uitloggen', 'Log out') ?></button>
                </form>
            </div>
        </details>
    </header>
    <?php endif; ?>

    <main<?= $isAuthPage ? ' class="auth-main"' : '' ?>>
        <?php if (!$isAuthPage): ?>
            <p class="topbar"><span>Circuleather</span><span aria-hidden="true">/</span><strong><?= escape($title) ?></strong><time datetime="2026-09-28"><?= t('28 sep 2026', '28 Sep 2026') ?></time></p>
        <?php endif; ?>

        <?php require __DIR__ . '/pages/' . $page . '.php'; ?>
    </main>
</body>
</html>
