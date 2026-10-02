<?php
/*
 * Connects the order detail form to the transaction logic in lib/order-editing.php.
 * Callbacks record stock alerts and meaningful before/after changes.
 * Validation errors are returned to the template; successful actions redirect to avoid resubmission.
 */

if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

$postedToken = $_POST['csrf_token'] ?? '';
if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
    $orderEditError = t('Het formulier is verlopen. Vernieuw de pagina en probeer opnieuw.', 'The form expired. Refresh the page and try again.');
    return;
}

try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../lib/notifications.php';
    require_once __DIR__ . '/../lib/order-editing.php';
    $actorName = (string) ($_SESSION['full_name'] ?? 'System');
    $findOrderNumber = $mysqli->prepare('SELECT order_number FROM orders WHERE id = ? LIMIT 1');
    $findOrderNumber->bind_param('i', $orderDetailId);
    $findOrderNumber->execute();
    $orderNumber = (string) ($findOrderNumber->get_result()->fetch_assoc()['order_number'] ?? ('#' . $orderDetailId));
    // The library calls these hooks within its transaction; the action supplies notification behavior.
    $stockChanged = static function (string $type, int $inventoryId, array $inventory, string $newStock) use ($mysqli, $actorName): void {
        createLowStockNotifications(
            $mysqli,
            (string) $inventory['material_name'],
            $type,
            $inventoryId,
            (float) $newStock,
            (float) $inventory['minimum_stock'],
            (string) $inventory['unit'],
            $actorName
        );
    };
    $orderChanged = static function (array $changes) use ($mysqli, $orderNumber, $orderDetailId, $actorName): void {
        createOrderNotifications($mysqli, 'updated', $orderNumber, $orderDetailId, $actorName, $changes);
    };
    $result = applyOrderAction($mysqli, $orderDetailId, $_POST, $stockChanged, $orderChanged);
    if ($result === 'deleted') {
        createOrderNotifications($mysqli, 'deleted', $orderNumber, $orderDetailId, $actorName);
    }
    header('Location: ' . ($result === 'deleted' ? '?page=orders&deleted=1' : '?page=order-detail&id=' . $orderDetailId . '&saved=1'));
    exit;
} catch (DomainException $exception) {
    $orderEditConflict = $exception->getMessage() === 'conflict';
    $orderEditError = match ($exception->getMessage()) {
        'not_found' => t('Deze order bestaat niet meer.', 'This order no longer exists.'),
        'conflict' => t('De order is ondertussen gewijzigd. De nieuwste gegevens zijn geladen. Controleer deze en probeer opnieuw.', 'The order changed while you were editing. The latest details have been loaded. Review them and try again.'),
        'invalid_client' => t('Controleer de klantgegevens.', 'Check the customer details.'),
        'invalid_status' => t('Kies een geldige order- en betaalstatus.', 'Choose a valid order and payment status.'),
        'invalid_action' => t('Kies een geldige orderactie.', 'Choose a valid order action.'),
        'invalid_quantity' => t('Gebruik positieve hoeveelheden en hele aantallen voor losse stukken.', 'Use positive quantities and whole numbers for individual pieces.'),
        'duplicate_item' => t('Kies ieder materiaal maximaal eenmaal per order.', 'Choose each material only once per order.'),
        'insufficient_stock' => t('Er is onvoldoende voorraad voor deze wijziging. Verlaag de hoeveelheid of kies ander materiaal.', 'There is not enough stock for this change. Reduce the quantity or choose another material.'),
        'missing_inventory' => t('Een gekozen materiaal is niet meer beschikbaar.', 'A selected material is no longer available.'),
        'total_too_large', 'stock_limit' => t('De ingevoerde hoeveelheden of prijzen zijn te hoog.', 'The entered quantities or prices are too high.'),
        default => t('Controleer de artikelen. Een order moet 1 tot 30 geldige artikelen bevatten.', 'Check the items. An order must contain 1 to 30 valid items.'),
    };
} catch (Throwable $exception) {
    error_log('Order action failed: ' . $exception->getMessage());
    $orderEditError = ($_POST['action'] ?? '') === 'delete'
        ? t('De order kon niet worden verwijderd. Probeer het opnieuw.', 'The order could not be deleted. Please try again.')
        : t('De wijzigingen konden niet worden opgeslagen. Probeer het opnieuw.', 'The changes could not be saved. Please try again.');
}
