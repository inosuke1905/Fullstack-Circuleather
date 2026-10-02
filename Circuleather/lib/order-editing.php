<?php
declare(strict_types=1);

if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

function orderEditState(mysqli $db, int $id, bool $lock = false): ?array
{
    $query = $db->prepare(
        'SELECT o.*, c.full_name AS client_name, c.email, c.phone,
            c.street_address, c.postal_code, c.city
         FROM orders o INNER JOIN clients c ON c.id = o.client_id WHERE o.id = ?'
        . ($lock ? ' FOR UPDATE' : '')
    );
    $query->bind_param('i', $id);
    $query->execute();
    $order = $query->get_result()->fetch_assoc();
    if (!$order) {
        return null;
    }
    $query = $db->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
    $query->bind_param('i', $id);
    $query->execute();
    return ['order' => $order, 'items' => $query->get_result()->fetch_all(MYSQLI_ASSOC)];
}

function orderEditRevision(array $state): string
{
    return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
}

function orderInventoryKey(array $item): string
{
    return $item['individual_piece_id'] !== null
        ? 'piece:' . $item['individual_piece_id']
        : 'batch:' . $item['batch_id'];
}

// Quantities and money are calculated as integer hundredths to avoid rounding drift.
function orderHundredths(string $amount): int
{
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
}

function orderDecimal(int $amount): string
{
    return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
}

function applyOrderAction(
    mysqli $db,
    int $id,
    array $input,
    ?callable $onStockChanged = null,
    ?callable $onOrderChanged = null
): string
{
    $action = $input['action'] ?? 'update';
    if (!is_string($action) || !in_array($action, ['update', 'delete'], true)) {
        throw new DomainException('invalid_action');
    }
    if ($action === 'delete') {
        deleteOrder($db, $id, is_string($input['revision'] ?? null) ? $input['revision'] : '');
        return 'deleted';
    }
    updateOrder($db, $id, $input, $onStockChanged, $onOrderChanged);
    return 'saved';
}

function deleteOrder(mysqli $db, int $id, string $revision): void
{
    $db->begin_transaction();
    try {
        $state = orderEditState($db, $id, true);
        if ($state === null) {
            throw new DomainException('not_found');
        }
        if (!hash_equals(orderEditRevision($state), $revision)) {
            throw new DomainException('conflict');
        }

        // Only unsent orders still reserve stock. Cancelled orders already returned it.
        if (in_array($state['order']['status'], ['open', 'processing'], true)) {
            $reserved = [];
            foreach ($state['items'] as $item) {
                $key = orderInventoryKey($item);
                $reserved[$key] = ($reserved[$key] ?? 0) + orderHundredths($item['quantity']);
            }
            ksort($reserved, SORT_STRING);
            foreach ($reserved as $key => $quantity) {
                [$type, $inventoryId] = explode(':', $key);
                $table = $type === 'piece' ? 'individual_pieces' : 'batches';
                $query = $db->prepare("SELECT stock FROM {$table} WHERE id = ? FOR UPDATE");
                $query->bind_param('s', $inventoryId);
                $query->execute();
                $inventory = $query->get_result()->fetch_assoc();
                if (!$inventory) {
                    throw new DomainException('missing_inventory');
                }
                $stock = orderHundredths($inventory['stock']) + $quantity;
                if ($stock > 9999999999) {
                    throw new DomainException('stock_limit');
                }
                $stockValue = orderDecimal($stock);
                $query = $db->prepare("UPDATE {$table} SET stock = ? WHERE id = ?");
                $query->bind_param('ss', $stockValue, $inventoryId);
                $query->execute();
            }
        }

        // The order-items foreign key cascades; the shared customer is retained.
        $query = $db->prepare('DELETE FROM orders WHERE id = ?');
        $query->bind_param('i', $id);
        $query->execute();
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

function updateOrder(
    mysqli $db,
    int $id,
    array $input,
    ?callable $onStockChanged = null,
    ?callable $onOrderChanged = null
): void
{
    $text = static fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    $client = [];
    foreach (['client_name' => 150, 'street_address' => 255, 'postal_code' => 24, 'city' => 100, 'phone' => 40] as $field => $limit) {
        $client[$field] = $text($field);
        if ($client[$field] === '' || mb_strlen($client[$field]) > $limit) {
            throw new DomainException('invalid_client');
        }
    }
    $client['email'] = mb_strtolower($text('email'));
    if (!filter_var($client['email'], FILTER_VALIDATE_EMAIL) || strlen($client['email']) > 254) {
        throw new DomainException('invalid_client');
    }
    $status = $text('status');
    $payment = $text('payment_status');
    if (!in_array($status, ['open', 'processing', 'shipped', 'delivered', 'cancelled'], true)
        || !in_array($payment, ['unpaid', 'paid'], true)) {
        throw new DomainException('invalid_status');
    }
    $postedItems = $input['items'] ?? null;
    if (!is_array($postedItems) || count($postedItems) < 1 || count($postedItems) > 30) {
        throw new DomainException('invalid_items');
    }
    $items = [];
    $seen = [];
    $seenLines = [];
    foreach ($postedItems as $item) {
        if (!is_array($item)) {
            throw new DomainException('invalid_items');
        }
        $key = $item['inventory_key'] ?? '';
        $lineId = $item['line_id'] ?? '0';
        $quantity = $item['quantity'] ?? '';
        $price = $item['unit_price'] ?? '';
        if (!is_string($key) || !preg_match('/^(batch|piece):([1-9][0-9]{0,17})$/D', $key, $match)
            || !is_string($lineId) || !preg_match('/^[0-9]{1,18}$/D', $lineId)
            || !is_string($quantity) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $quantity)
            || !is_string($price) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $price)) {
            throw new DomainException('invalid_items');
        }
        $amount = orderHundredths($quantity);
        $priceCents = orderHundredths($price);
        if ($amount < 1 || ($match[1] === 'piece' && $amount % 100 !== 0)) {
            throw new DomainException('invalid_quantity');
        }
        if (isset($seen[$key]) || ((int) $lineId !== 0 && isset($seenLines[(int) $lineId]))) {
            throw new DomainException('duplicate_item');
        }
        if ($priceCents > intdiv(99999999999900, $amount)) {
            throw new DomainException('total_too_large');
        }
        $seen[$key] = true;
        $seenLines[(int) $lineId] = true;
        $items[] = ['key' => $key, 'type' => $match[1], 'inventory_id' => (int) $match[2],
            'line_id' => (int) $lineId, 'quantity' => $amount, 'price' => $priceCents,
            'line_total' => intdiv($amount * $priceCents + 50, 100)];
    }

    $db->begin_transaction();
    try {
        $state = orderEditState($db, $id, true);
        if ($state === null) {
            throw new DomainException('not_found');
        }
        if (!hash_equals(orderEditRevision($state), $text('revision'))) {
            throw new DomainException('conflict');
        }
        $oldItems = [];
        $oldReserved = [];
        $allowedTypes = [];
        foreach ($state['items'] as $old) {
            $key = orderInventoryKey($old);
            $oldItems[(int) $old['id']] = $old;
            $oldReserved[$key] = ($oldReserved[$key] ?? 0)
                + ($state['order']['status'] === 'cancelled' ? 0 : orderHundredths($old['quantity']));
            $allowedTypes[explode(':', $key)[0]] = true;
        }
        $newReserved = [];
        $totalCents = 0;
        foreach ($items as $item) {
            if (($item['line_id'] !== 0 && !isset($oldItems[$item['line_id']])) || !isset($allowedTypes[$item['type']])) {
                throw new DomainException('invalid_items');
            }
            $newReserved[$item['key']] = $status === 'cancelled' ? 0 : $item['quantity'];
            $totalCents += $item['line_total'];
        }
        if ($totalCents > 999999999999) {
            throw new DomainException('total_too_large');
        }
        $inventory = [];
        $keys = array_unique([...array_keys($oldReserved), ...array_keys($newReserved)]);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            [$type, $inventoryId] = explode(':', $key);
            $table = $type === 'piece' ? 'individual_pieces' : 'batches';
            $query = $db->prepare("SELECT * FROM {$table} WHERE id = ? FOR UPDATE");
            $query->bind_param('s', $inventoryId);
            $query->execute();
            $row = $query->get_result()->fetch_assoc();
            if (!$row) {
                throw new DomainException('missing_inventory');
            }
            $stock = orderHundredths($row['stock']) + ($oldReserved[$key] ?? 0) - ($newReserved[$key] ?? 0);
            if ($stock < 0) {
                throw new DomainException('insufficient_stock');
            }
            if ($stock > 9999999999) {
                throw new DomainException('stock_limit');
            }
            $inventory[$key] = $row;
            if ($stock !== orderHundredths($row['stock'])) {
                if (
                    $onStockChanged !== null
                    && orderHundredths($row['stock']) > orderHundredths($row['minimum_stock'])
                    && $stock <= orderHundredths($row['minimum_stock'])
                ) {
                    $onStockChanged($type, (int) $inventoryId, $row, orderDecimal($stock));
                }
                $stockValue = orderDecimal($stock);
                $query = $db->prepare("UPDATE {$table} SET stock = ? WHERE id = ?");
                $query->bind_param('ss', $stockValue, $inventoryId);
                $query->execute();
            }
        }

        // Customer records are shared, as on the order-creation page.
        $query = $db->prepare('SELECT id FROM clients WHERE email = ? FOR UPDATE');
        $query->bind_param('s', $client['email']);
        $query->execute();
        $existingClient = $query->get_result()->fetch_assoc();
        if ($existingClient) {
            $clientId = (int) $existingClient['id'];
            $query = $db->prepare('UPDATE clients SET full_name = ?, street_address = ?, postal_code = ?, city = ?, phone = ? WHERE id = ?');
            $query->bind_param('sssssi', $client['client_name'], $client['street_address'], $client['postal_code'], $client['city'], $client['phone'], $clientId);
        } else {
            $query = $db->prepare('INSERT INTO clients (full_name, street_address, postal_code, city, email, phone) VALUES (?, ?, ?, ?, ?, ?)');
            $query->bind_param('ssssss', $client['client_name'], $client['street_address'], $client['postal_code'], $client['city'], $client['email'], $client['phone']);
        }
        $query->execute();
        if (!$existingClient) {
            $clientId = (int) $db->insert_id;
        }
        $retained = [];
        foreach ($items as $item) {
            $old = $oldItems[$item['line_id']] ?? null;
            $snapshot = $old && orderInventoryKey($old) === $item['key'] ? $old : $inventory[$item['key']];
            $batchId = $item['type'] === 'batch' ? $item['inventory_id'] : null;
            $pieceId = $item['type'] === 'piece' ? $item['inventory_id'] : null;
            $quantity = orderDecimal($item['quantity']);
            $price = orderDecimal($item['price']);
            $lineTotal = orderDecimal($item['line_total']);
            if ($old) {
                $query = $db->prepare('UPDATE order_items SET batch_id = ?, individual_piece_id = ?, sku = ?, material_name = ?, grade = ?, color = ?, thickness = ?, quantity = ?, unit = ?, unit_price = ?, line_total = ? WHERE id = ? AND order_id = ?');
                $query->bind_param('iisssssssssii', $batchId, $pieceId, $snapshot['sku'], $snapshot['material_name'], $snapshot['grade'], $snapshot['color'], $snapshot['thickness'], $quantity, $snapshot['unit'], $price, $lineTotal, $item['line_id'], $id);
                $retained[$item['line_id']] = true;
            } else {
                $query = $db->prepare('INSERT INTO order_items (batch_id, individual_piece_id, sku, material_name, grade, color, thickness, quantity, unit, unit_price, line_total, order_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $query->bind_param('iisssssssssi', $batchId, $pieceId, $snapshot['sku'], $snapshot['material_name'], $snapshot['grade'], $snapshot['color'], $snapshot['thickness'], $quantity, $snapshot['unit'], $price, $lineTotal, $id);
            }
            $query->execute();
        }
        foreach ($oldItems as $lineId => $old) {
            if (!isset($retained[$lineId])) {
                $query = $db->prepare('DELETE FROM order_items WHERE id = ? AND order_id = ?');
                $query->bind_param('ii', $lineId, $id);
                $query->execute();
            }
        }
        $total = orderDecimal($totalCents);
        $query = $db->prepare('UPDATE orders SET client_id = ?, status = ?, payment_status = ?, total_amount = ? WHERE id = ?');
        $query->bind_param('isssi', $clientId, $status, $payment, $total, $id);
        $query->execute();

        $changes = [];
        $clientFields = [
            'client_name' => 'client_name',
            'email' => 'email',
            'phone' => 'phone',
            'street_address' => 'street_address',
            'postal_code' => 'postal_code',
            'city' => 'city',
        ];
        foreach ($clientFields as $field => $orderField) {
            $before = (string) ($state['order'][$orderField] ?? '');
            $after = (string) $client[$field];
            if ($before !== $after) {
                $changes[$field] = ['before' => $before, 'after' => $after];
            }
        }
        foreach (['status' => $status, 'payment_status' => $payment] as $field => $after) {
            $before = (string) $state['order'][$field];
            if ($before !== $after) {
                $changes[$field] = ['before' => $before, 'after' => $after];
            }
        }

        $itemDescription = static function (array $item): string {
            return trim(
                ($item['sku'] !== null && $item['sku'] !== '' ? $item['sku'] . ' ' : '')
                . $item['material_name'] . ' - ' . $item['quantity'] . ' ' . $item['unit']
                . ' @ ' . $item['unit_price']
            );
        };
        $beforeItems = implode("\n", array_map($itemDescription, $state['items']));
        $afterItems = [];
        foreach ($items as $item) {
            $old = $oldItems[$item['line_id']] ?? null;
            $snapshot = $old && orderInventoryKey($old) === $item['key'] ? $old : $inventory[$item['key']];
            $afterItems[] = $itemDescription([
                'sku' => $snapshot['sku'],
                'material_name' => $snapshot['material_name'],
                'quantity' => orderDecimal($item['quantity']),
                'unit' => $snapshot['unit'],
                'unit_price' => orderDecimal($item['price']),
            ]);
        }
        $afterItemsDescription = implode("\n", $afterItems);
        if ($beforeItems !== $afterItemsDescription) {
            $changes['order_items'] = ['before' => $beforeItems, 'after' => $afterItemsDescription];
        }
        if ($onOrderChanged !== null && $changes !== []) {
            $onOrderChanged($changes);
        }
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}
