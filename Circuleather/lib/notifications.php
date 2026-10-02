<?php

if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

function createLowStockNotifications(
    mysqli $db,
    string $itemName,
    string $inventoryType,
    int $inventoryId,
    float $stock,
    float $minimumStock,
    string $unit,
    string $actorName
): void {
    try {
        $recipients = $db->query(
            'SELECT id, language FROM users WHERE is_active = 1 AND low_stock_notifications = 1'
        );
        $insert = $db->prepare(
            'INSERT INTO notifications (recipient_user_id, type, title, message, target_url, actor_name)
             VALUES (?, \'low_stock\', ?, ?, ?, ?)'
        );
        while ($recipient = $recipients->fetch_assoc()) {
            $isEnglish = $recipient['language'] === 'en';
            $formatNumber = static fn (float $value): string => $isEnglish
                ? number_format($value, 2, '.', ',')
                : number_format($value, 2, ',', '.');
            $title = $isEnglish ? 'Low stock' : 'Lage voorraad';
            $message = $isEnglish
                ? $itemName . ': ' . $formatNumber($stock) . ' ' . $unit . ' remaining (minimum ' . $formatNumber($minimumStock) . ').'
                : $itemName . ': nog ' . $formatNumber($stock) . ' ' . $unit . ' (minimum ' . $formatNumber($minimumStock) . ').';
            $targetUrl = $inventoryType === 'piece'
                ? '?page=piece-detail&id=' . $inventoryId
                : '?page=batch-detail&id=' . $inventoryId;
            $recipientId = (int) $recipient['id'];
            $insert->bind_param('issss', $recipientId, $title, $message, $targetUrl, $actorName);
            $insert->execute();
        }
    } catch (Throwable $exception) {
        error_log('Could not create low-stock notifications: ' . $exception->getMessage());
    }
}

function notificationChangedFields(?array $changes, bool $isEnglish): string
{
    if ($changes === null || $changes === []) {
        return '';
    }

    $labels = [
        'full_name' => ['Naam', 'Name'], 'role' => ['Rol', 'Role'], 'is_active' => ['Accountstatus', 'Account status'],
        'sku' => ['SKU', 'SKU'], 'material_name' => ['Naam', 'Name'], 'grade' => ['Grade', 'Grade'],
        'color' => ['Kleur', 'Color'], 'thickness' => ['Dikte', 'Thickness'],
        'sale_price' => ['Verkoopprijs', 'Sale price'], 'cost_price' => ['Inkoopprijs', 'Cost price'],
        'unit' => ['Eenheid', 'Unit'], 'stock' => ['Voorraad', 'Stock'],
        'minimum_stock' => ['Minimale voorraad', 'Minimum stock'], 'origin' => ['Herkomst', 'Origin'],
        'supplier' => ['Leverancier', 'Supplier'], 'arrival_date' => ['Datum', 'Date'],
        'client_name' => ['Klantnaam', 'Customer name'], 'email' => ['E-mailadres', 'Email address'],
        'phone' => ['Telefoonnummer', 'Phone number'], 'street_address' => ['Adres', 'Street address'],
        'postal_code' => ['Postcode', 'Postal code'], 'city' => ['Plaats', 'City'],
        'status' => ['Orderstatus', 'Order status'], 'payment_status' => ['Betaalstatus', 'Payment status'],
        'order_items' => ['Orderregels', 'Order items'],
    ];
    $names = [];
    foreach (array_keys($changes) as $field) {
        $names[] = $labels[$field][$isEnglish ? 1 : 0] ?? ucfirst(str_replace('_', ' ', $field));
    }
    return implode(', ', $names);
}

function notificationDetailsJson(?array $changes): ?string
{
    return $changes === null || $changes === []
        ? null
        : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function createAccountNotifications(
    mysqli $db,
    string $event,
    string $fullName,
    string $email,
    string $actorName,
    ?array $changes = null
): void
{
    $eventLabels = [
        'created' => ['created' => 'aangemaakt', 'updated' => 'created'],
        'updated' => ['created' => 'gewijzigd', 'updated' => 'updated'],
        'deleted' => ['created' => 'verwijderd', 'updated' => 'deleted'],
        'password_reset' => ['created' => 'wachtwoord opnieuw ingesteld voor', 'updated' => 'password reset for'],
    ];
    if (!isset($eventLabels[$event]) || ($event === 'updated' && ($changes === null || $changes === []))) {
        return;
    }

    try {
        $recipients = $db->query("SELECT id, language FROM users WHERE is_active = 1 AND role = 'admin'");
        $insert = $db->prepare(
            'INSERT INTO notifications (recipient_user_id, type, event_action, title, message, target_url, actor_name, details_json)
             VALUES (?, \'account_activity\', ?, ?, ?, \'?page=users\', ?, ?)'
        );
        while ($recipient = $recipients->fetch_assoc()) {
            $isEnglish = $recipient['language'] === 'en';
            $title = $isEnglish ? 'Account activity' : 'Accountactiviteit';
            $label = $eventLabels[$event][$isEnglish ? 'updated' : 'created'];
            $message = $label . ': ' . $fullName . '.';
            $changedFields = notificationChangedFields($changes, $isEnglish);
            if ($changedFields !== '') {
                $message .= $isEnglish ? ' Fields changed: ' . $changedFields . '.' : ' Gewijzigde velden: ' . $changedFields . '.';
            }
            $recipientId = (int) $recipient['id'];
            $detailsJson = notificationDetailsJson($changes);
            $insert->bind_param('isssss', $recipientId, $event, $title, $message, $actorName, $detailsJson);
            $insert->execute();
        }
    } catch (Throwable $exception) {
        error_log('Could not create account notifications: ' . $exception->getMessage());
    }
}

function createOrderNotifications(mysqli $db, string $event, string $orderNumber, int $orderId, string $actorName, ?array $changes = null): void
{
    $eventLabels = [
        'created' => ['nl' => 'aangemaakt', 'en' => 'created'],
        'updated' => ['nl' => 'bijgewerkt', 'en' => 'updated'],
        'deleted' => ['nl' => 'verwijderd', 'en' => 'deleted'],
    ];
    if (!isset($eventLabels[$event]) || ($event === 'updated' && ($changes === null || $changes === []))) {
        return;
    }

    try {
        $recipients = $db->query(
            'SELECT id, language FROM users WHERE is_active = 1 AND order_notifications = 1'
        );
        $insert = $db->prepare(
            'INSERT INTO notifications (recipient_user_id, type, event_action, title, message, target_url, actor_name, details_json)
             VALUES (?, \'order_activity\', ?, ?, ?, ?, ?, ?)'
        );
        while ($recipient = $recipients->fetch_assoc()) {
            $isEnglish = $recipient['language'] === 'en';
            $verb = $eventLabels[$event][$isEnglish ? 'en' : 'nl'];
            $title = $isEnglish ? 'Order ' . $verb : 'Bestelling ' . $verb;
            $message = ($isEnglish ? 'Order ' : 'Bestelling ') . $orderNumber . ' ' . $verb . '.';
            $changedFields = notificationChangedFields($changes, $isEnglish);
            if ($changedFields !== '') {
                $message .= $isEnglish ? ' Fields changed: ' . $changedFields . '.' : ' Gewijzigde velden: ' . $changedFields . '.';
            }
            $targetUrl = $event === 'deleted' ? '?page=orders' : '?page=order-detail&id=' . $orderId;
            $recipientId = (int) $recipient['id'];
            $detailsJson = notificationDetailsJson($changes);
            $insert->bind_param('issssss', $recipientId, $event, $title, $message, $targetUrl, $actorName, $detailsJson);
            $insert->execute();
        }
    } catch (Throwable $exception) {
        error_log('Could not create order notifications: ' . $exception->getMessage());
    }
}

function createInventoryActivityNotifications(
    mysqli $db,
    string $event,
    string $itemName,
    string $inventoryType,
    int $inventoryId,
    int $actorId,
    string $actorName,
    ?array $changes = null,
    string $sku = ''
): void {
    $eventLabels = [
        'created' => ['nl' => 'toegevoegd', 'en' => 'created'],
        'updated' => ['nl' => 'gewijzigd', 'en' => 'changed'],
        'deleted' => ['nl' => 'verwijderd', 'en' => 'deleted'],
    ];
    if (!isset($eventLabels[$event])) {
        return;
    }

    try {
        $recipients = $db->prepare(
            'SELECT id, language FROM users WHERE is_active = 1 AND inventory_notifications = 1 AND id <> ?'
        );
        $recipients->bind_param('i', $actorId);
        $recipients->execute();
        $recipientRows = $recipients->get_result();
        $insert = $db->prepare(
            'INSERT INTO notifications (recipient_user_id, type, event_action, title, message, target_url, actor_name, details_json)
             VALUES (?, \'inventory_activity\', ?, ?, ?, ?, ?, ?)'
        );
        while ($recipient = $recipientRows->fetch_assoc()) {
            $isEnglish = $recipient['language'] === 'en';
            $itemLabel = $inventoryType === 'piece'
                ? ($isEnglish ? 'Leather piece' : 'Leerstuk')
                : 'Batch';
            $verb = $eventLabels[$event][$isEnglish ? 'en' : 'nl'];
            $title = $isEnglish ? 'Inventory changed' : 'Voorraad gewijzigd';
            $itemReference = $sku !== '' ? $sku : $itemName;
            $message = $itemLabel . ' ' . $itemReference . ' ' . $verb . '.';
            $changedFields = notificationChangedFields($changes, $isEnglish);
            if ($changedFields !== '') {
                $message .= $isEnglish ? ' Fields changed: ' . $changedFields . '.' : ' Gewijzigde velden: ' . $changedFields . '.';
            }
            $targetUrl = $inventoryType === 'piece'
                ? '?page=piece-detail&id=' . $inventoryId
                : '?page=batch-detail&id=' . $inventoryId;
            $recipientId = (int) $recipient['id'];
            $detailsJson = notificationDetailsJson($changes);
            $insert->bind_param('issssss', $recipientId, $event, $title, $message, $targetUrl, $actorName, $detailsJson);
            $insert->execute();
        }
    } catch (Throwable $exception) {
        error_log('Could not create inventory activity notifications: ' . $exception->getMessage());
    }
}