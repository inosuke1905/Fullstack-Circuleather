<?php

if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

$postedToken = $_POST['csrf_token'] ?? '';
if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
    $orderError = t('Het formulier is verlopen. Vernieuw de pagina en probeer opnieuw.', 'The form expired. Refresh the page and try again.');
    return;
}

$postedOrderType = $_POST['order_type'] ?? null;
if (!in_array($postedOrderType, ['batch', 'pieces'], true)) {
    $orderError = t('Kies eerst een batchorder of een order voor losse stukken leer.', 'Choose a batch order or an individual-piece order first.');
    return;
}
$expectedUnit = $postedOrderType === 'pieces' ? 'piece' : 'kg';
$orderTable = $postedOrderType === 'pieces' ? 'individual_pieces' : 'batches';

$getValue = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
};

$clientName = $getValue('client_name');
$streetAddress = $getValue('street_address');
$postalCode = $getValue('postal_code');
$city = $getValue('city');
$email = mb_strtolower($getValue('email'));
$phone = $getValue('phone');
$postedItems = $_POST['items'] ?? null;

if (
    $clientName === ''
    || mb_strlen($clientName) > 150
    || $streetAddress === ''
    || mb_strlen($streetAddress) > 255
    || $postalCode === ''
    || mb_strlen($postalCode) > 24
    || $city === ''
    || mb_strlen($city) > 100
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || strlen($email) > 254
    || $phone === ''
    || mb_strlen($phone) > 40
    || !is_array($postedItems)
    || $postedItems === []
    || count($postedItems) > 30
) {
    $orderError = t('Controleer de klantgegevens en voeg minimaal één geldig orderartikel toe.', 'Check the client details and add at least one valid order item.');
    return;
}

$items = [];
$seenBatchIds = [];
foreach ($postedItems as $postedItem) {
    if (!is_array($postedItem)) {
        $orderError = t('Een orderartikel is ongeldig.', 'An order item is invalid.');
        return;
    }

    $rawBatchId = $postedItem['inventory_id'] ?? '';
    $quantity = $postedItem['quantity'] ?? '';
    if (
        (!is_string($rawBatchId) && !is_int($rawBatchId))
        || !ctype_digit((string) $rawBatchId)
        || !is_string($quantity)
        || preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $quantity) !== 1
        || (float) $quantity <= 0
    ) {
        $orderError = t('Controleer de gekozen materialen en hoeveelheden.', 'Check the selected materials and quantities.');
        return;
    }

    if ($expectedUnit === 'piece' && (float) $quantity !== floor((float) $quantity)) {
        $orderError = t('Bestel losse stukken leer in hele aantallen.', 'Order individual leather pieces in whole numbers.');
        return;
    }

    $batchId = (int) $rawBatchId;
    if ($batchId < 1 || isset($seenBatchIds[$batchId])) {
        $orderError = t('Kies ieder artikel maximaal één keer per order.', 'Choose each item at most once per order.');
        return;
    }

    $seenBatchIds[$batchId] = true;
    $items[] = ['batch_id' => $batchId, 'quantity' => $quantity];
}

try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../lib/notifications.php';
    $mysqli->begin_transaction();

    $lockedItems = [];
    $totalAmount = 0.0;
    foreach ($items as $item) {
        $batchId = $item['batch_id'];
        $findBatch = $mysqli->prepare(
            "SELECT id, sku, material_name, grade, color, thickness, unit, stock, minimum_stock, sale_price
             FROM {$orderTable} WHERE id = ? FOR UPDATE"
        );
        $findBatch->bind_param('i', $batchId);
        $findBatch->execute();
        $batch = $findBatch->get_result()->fetch_assoc();
        if (!$batch) {
            throw new DomainException('batch_not_found');
        }
        if ($batch['unit'] !== $expectedUnit) {
            throw new DomainException('wrong_order_type');
        }

        $quantity = (float) $item['quantity'];
        if ($quantity > (float) $batch['stock']) {
            throw new DomainException('insufficient_stock');
        }

        $unitPrice = (float) $batch['sale_price'];
        $lineTotal = round($quantity * $unitPrice, 2);
        $totalAmount += $lineTotal;
        $lockedItems[] = [
            'batch' => $batch,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ];
    }

    $findClient = $mysqli->prepare('SELECT id FROM clients WHERE email = ? FOR UPDATE');
    $findClient->bind_param('s', $email);
    $findClient->execute();
    $existingClient = $findClient->get_result()->fetch_assoc();

    if ($existingClient) {
        $clientId = (int) $existingClient['id'];
        $updateClient = $mysqli->prepare(
            'UPDATE clients SET full_name = ?, street_address = ?, postal_code = ?, city = ?, phone = ? WHERE id = ?'
        );
        $updateClient->bind_param('sssssi', $clientName, $streetAddress, $postalCode, $city, $phone, $clientId);
        $updateClient->execute();
    } else {
        $insertClient = $mysqli->prepare(
            'INSERT INTO clients (full_name, street_address, postal_code, city, email, phone)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertClient->bind_param('ssssss', $clientName, $streetAddress, $postalCode, $city, $email, $phone);
        $insertClient->execute();
        $clientId = (int) $mysqli->insert_id;
    }

    $orderNumber = 'ORD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $createdByUserId = (int) ($_SESSION['user_id'] ?? 0);
    $createdByName = (string) ($_SESSION['full_name'] ?? 'Unknown');
    $insertOrder = $mysqli->prepare(
        'INSERT INTO orders (order_number, client_id, total_amount, created_by_user_id, created_by_name) VALUES (?, ?, ?, ?, ?)'
    );
    $insertOrder->bind_param('sidis', $orderNumber, $clientId, $totalAmount, $createdByUserId, $createdByName);
    $insertOrder->execute();
    $orderId = (int) $mysqli->insert_id;

    foreach ($lockedItems as $item) {
        $batch = $item['batch'];
        $inventoryId = (int) $batch['id'];
        $batchId = $postedOrderType === 'batch' ? $inventoryId : null;
        $individualPieceId = $postedOrderType === 'pieces' ? $inventoryId : null;
        $sku = $batch['sku'];
        $materialName = $batch['material_name'];
        $grade = $batch['grade'];
        $color = $batch['color'];
        $thickness = $batch['thickness'];
        $quantity = $item['quantity'];
        $unit = $batch['unit'];
        $unitPrice = $item['unit_price'];
        $lineTotal = $item['line_total'];

        $insertItem = $mysqli->prepare(
            'INSERT INTO order_items (
                order_id, batch_id, individual_piece_id, sku, material_name, grade, color, thickness,
                quantity, unit, unit_price, line_total
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertItem->bind_param(
            'iiisssssdsdd',
            $orderId,
            $batchId,
            $individualPieceId,
            $sku,
            $materialName,
            $grade,
            $color,
            $thickness,
            $quantity,
            $unit,
            $unitPrice,
            $lineTotal
        );
        $insertItem->execute();

        $reduceStock = $mysqli->prepare("UPDATE {$orderTable} SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $reduceStock->bind_param('did', $quantity, $inventoryId, $quantity);
        $reduceStock->execute();
        if ($reduceStock->affected_rows !== 1) {
            throw new DomainException('insufficient_stock');
        }
        $previousStock = (float) $batch['stock'];
        $newStock = $previousStock - (float) $quantity;
        $minimumStock = (float) $batch['minimum_stock'];
        if ($previousStock > $minimumStock && $newStock <= $minimumStock) {
            createLowStockNotifications(
                $mysqli,
                (string) $batch['material_name'],
                $postedOrderType === 'pieces' ? 'piece' : 'batch',
                $inventoryId,
                $newStock,
                $minimumStock,
                (string) $batch['unit'],
                (string) ($_SESSION['full_name'] ?? 'System')
            );
        }
    }

    $mysqli->commit();
    createOrderNotifications($mysqli, 'created', $orderNumber, $orderId, (string) ($_SESSION['full_name'] ?? 'System'));
    header('Location: ?page=order-detail&id=' . $orderId . '&created=1');
    exit;
} catch (DomainException $exception) {
    $mysqli->rollback();
    $orderError = match ($exception->getMessage()) {
        'batch_not_found' => t('Een gekozen artikel bestaat niet meer.', 'A selected item no longer exists.'),
        'wrong_order_type' => t('Een gekozen artikel hoort niet bij dit ordertype. Kies alleen batches of alleen losse stukken leer.', 'A selected item does not match this order type. Choose only batches or only individual leather pieces.'),
        'insufficient_stock' => t('Er is onvoldoende voorraad voor één van de gekozen artikelen.', 'There is not enough stock for one of the selected items.'),
        default => t('De order kon niet worden opgeslagen.', 'The order could not be saved.'),
    };
} catch (Throwable $exception) {
    if (isset($mysqli)) {
        $mysqli->rollback();
    }
    error_log('Order creation failed: ' . $exception->getMessage());
    $orderError = t('De order kon niet worden opgeslagen. Probeer het opnieuw.', 'The order could not be saved. Please try again.');
}
