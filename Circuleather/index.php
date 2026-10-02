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
    'inventory_notifications' => true,
];

session_start();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

if (isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/db.php';
        $accountLookup = $mysqli->prepare('SELECT full_name, email, role, is_active FROM users WHERE id = ? LIMIT 1');
        $accountId = (int) $_SESSION['user_id'];
        $accountLookup->bind_param('i', $accountId);
        $accountLookup->execute();
        $currentAccount = $accountLookup->get_result()->fetch_assoc();
        if (!$currentAccount || !(bool) $currentAccount['is_active']) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } else {
            $_SESSION['full_name'] = $currentAccount['full_name'];
            $_SESSION['email'] = $currentAccount['email'];
            $_SESSION['role'] = $currentAccount['role'];
        }
    } catch (Throwable $exception) {
        error_log('Account verification failed: ' . $exception->getMessage());
        http_response_code(503);
        exit('Account verification is temporarily unavailable. Please try again.');
    }
}

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
if ($requestedPage === 'register') {
    header('Location: ?page=login');
    exit;
}
$isPublicPage = $requestedPage === 'login';
$isAdmin = ($_SESSION['role'] ?? 'worker') === 'admin';

if ($isPublicPage && isset($_SESSION['user_id'])) {
    header('Location: ?page=inventory');
    exit;
}
if (!$isPublicPage && !isset($_SESSION['user_id'])) {
    header('Location: ?page=login');
    exit;
}
if (!$isAdmin && $requestedPage === 'users') {
    http_response_code(403);
    exit(t('Je hebt geen toegang tot deze pagina.', 'You do not have access to this page.'));
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
        'inventory_notifications' => isset($_POST['inventory_notifications']),
    ];

    try {
        require_once __DIR__ . '/db.php';
        $userId = (int) $_SESSION['user_id'];
        $lowStockNotifications = (int) $settings['low_stock_notifications'];
        $orderNotifications = (int) $settings['order_notifications'];
        $inventoryNotifications = (int) $settings['inventory_notifications'];
        $updateSettings = $mysqli->prepare(
            'UPDATE users SET language = ?, theme = ?,
                low_stock_notifications = ?, order_notifications = ?, inventory_notifications = ? WHERE id = ?'
        );
        $updateSettings->bind_param(
            'ssiiii',
            $settings['language'],
            $settings['theme'],
            $lowStockNotifications,
            $orderNotifications,
            $inventoryNotifications,
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
    'profile' => $language === 'en' ? 'Profile' : 'Profiel',
    'settings' => $language === 'en' ? 'Settings' : 'Instellingen',
    'users' => $language === 'en' ? 'User accounts' : 'Gebruikers',
];
$navigationPages = array_diff_key($pages, ['profile' => true, 'settings' => true]);
if (!$isAdmin) {
    $navigationPages = array_diff_key($navigationPages, ['users' => true]);
}
$authPages = [
    'login' => $language === 'en' ? 'Log in' : 'Inloggen',
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
if ($page === 'users' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/actions/manage_users.php';
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
        <script src="notifications.js?v=<?= hash_file('sha256', __DIR__ . '/notifications.js') ?>" defer></script>
        <?php if (in_array($page, ['batch-detail', 'piece-detail', 'order-detail'], true)): ?>
            <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js" defer></script>
            <script src="share-links.js?v=<?= hash_file('sha256', __DIR__ . '/share-links.js') ?>" defer></script>
        <?php endif; ?>
    <?php endif; ?>
</head>
<body data-theme="<?= escape($settings['theme']) ?>" data-csrf-token="<?= escape($_SESSION['csrf_token']) ?>">
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
                        <a href="?page=<?= escape($key) ?>"<?= $page === $key || ($key === 'orders' && $page === 'order-detail') ? ' aria-current="page"' : '' ?>>
                            <span class="nav-icon" aria-hidden="true">
                                <?php if ($key === 'inventory'): ?>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 8.5 12 4l8.5 4.5-8.5 4.5L3.5 8.5Z"/><path d="M3.5 12.5 12 17l8.5-4.5"/><path d="M3.5 16.5 12 21l8.5-4.5"/></svg>
                                <?php elseif ($key === 'users'): ?>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19v-1.5A3.5 3.5 0 0 0 12.5 14h-1A3.5 3.5 0 0 0 8 17.5V19"/><circle cx="12" cy="7.5" r="3"/><path d="M19 19v-1a3 3 0 0 0-2.5-2.9"/><path d="M5 19v-1a3 3 0 0 1 2.5-2.9"/></svg>
                                <?php elseif ($key === 'orders'): ?>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5H6.5A1.5 1.5 0 0 0 5 6.5v13A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5v-13A1.5 1.5 0 0 0 17.5 5H16"/><path d="M9 3.5h6a1 1 0 0 1 1 1v3H8v-3a1 1 0 0 1 1-1Z"/><path d="M9 12h6M9 16h6"/></svg>
                                <?php elseif ($key === 'new-order'): ?>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4.5h7l4 4v11a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 19.5v-13A1.5 1.5 0 0 1 7.5 5H7Z"/><path d="M14 4.5v4h4"/><path d="M9 13h4"/><path d="M9 16h3"/><path d="m13.5 19.5 1-3.5 5.5-5.5 2.5 2.5-5.5 5.5-3.5 1Z"/><path d="m18.5 11.5 2.5 2.5"/></svg>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5h16"/><path d="M7 4.5h10"/><path d="M6 11.5h12v7H6z"/></svg>
                                <?php endif; ?>
                            </span>
                            <span><?= escape($label) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <div class="account-controls">
        <div class="notification-center" data-notification-center data-label="<?= t('Meldingen', 'Notifications') ?>" data-time-locale="<?= $language === 'en' ? 'en-US' : 'nl-NL' ?>" data-close-label="<?= t('Melding sluiten', 'Dismiss notification') ?>" data-read-label="<?= t('Markeer als gelezen', 'Mark as read') ?>">
            <button class="notification-trigger" type="button" aria-label="<?= t('Meldingen', 'Notifications') ?>" aria-expanded="false" aria-controls="notification-panel" title="<?= t('Meldingen', 'Notifications') ?>">
                <span class="notification-bell" aria-hidden="true">🔔</span>
                <span class="notification-count" hidden></span>
            </button>
            <section id="notification-panel" class="notification-panel" aria-label="<?= t('Meldingen', 'Notifications') ?>" hidden>
                <div class="notification-panel-heading">
                    <h2><?= t('Meldingen', 'Notifications') ?></h2>
                    <button class="notification-mark-all" type="button"><?= t('Alles gelezen', 'Mark all read') ?></button>
                </div>
                <ol class="notification-list"></ol>
                <p class="notification-empty"><?= t('Geen nieuwe meldingen.', 'No new notifications.') ?></p>
            </section>
            <div class="notification-toasts" aria-live="polite" aria-atomic="false"></div>
        </div>
        <details class="account-menu">
            <summary class="profile">
                <span class="profile-mark"><?= escape(mb_strtoupper(mb_substr($_SESSION['full_name'] ?? 'U', 0, 1))) ?></span>
                <span><strong><?= escape($_SESSION['full_name'] ?? '') ?></strong><small><?= ($_SESSION['role'] ?? 'worker') === 'admin' ? t('Beheerder', 'Administrator') : t('Medewerker', 'Worker') ?></small></span>
                <span class="account-caret" aria-hidden="true"></span>
            </summary>
            <div class="account-menu-panel">
                <a href="?page=profile"><span class="account-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20v-1.5a7 7 0 0 1 14 0V20z"/></svg></span><?= t('Profiel', 'Profile') ?></a>
                <a href="?page=settings"><span class="account-menu-icon account-settings-icon" aria-hidden="true"></span><?= t('Instellingen', 'Settings') ?></a>
                <form action="?page=logout" method="post">
                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                    <button type="submit"><span class="account-menu-icon account-logout-icon" aria-hidden="true"></span><?= t('Uitloggen', 'Log out') ?></button>
                </form>
            </div>
        </details>
        </div>
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
