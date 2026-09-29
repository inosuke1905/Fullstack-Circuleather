<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('DB_NAME') !== 'circuleather_order_edit_test') {
    throw new RuntimeException('Use the disposable circuleather_order_edit_test database.');
}
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('CIRCULEATHER_APP', true);
function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function t(string $dutch, string $english): string { return escape($english); }
function verify(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
require __DIR__ . '/../db.php';
require __DIR__ . '/../lib/order-editing.php';

foreach (['order_items', 'orders', 'clients', 'batches', 'individual_pieces'] as $table) {
    $mysqli->query("DELETE FROM {$table}");
}

$mysqli->query("INSERT INTO clients (id, full_name, email, phone, street_address, postal_code, city) VALUES (301, 'Test Customer', 'test@example.invalid', '123', 'Test street', '1000AA', 'Test City')");
$mysqli->query("INSERT INTO batches (id, sku, material_name, grade, sale_price, cost_price, unit, stock, supplier) VALUES (101, 'TEST-B1', 'Current batch name', 'A', 99, 5, 'kg', 96.5, 'Test'), (102, 'TEST-B2', 'Second batch', 'B', 12.34, 5, 'kg', 50, 'Test')");
$mysqli->query("INSERT INTO individual_pieces (id, sku, material_name, grade, sale_price, cost_price, unit, stock, supplier) VALUES (101, 'TEST-P1', 'Current piece name', 'A', 70, 5, 'piece', 8, 'Test'), (102, 'TEST-P2', 'Second piece', 'B', 9, 5, 'piece', 5, 'Test')");
$mysqli->query("INSERT INTO orders (id, order_number, client_id, total_amount) VALUES (201, 'TEST-ORDER-B', 301, 35), (202, 'TEST-ORDER-P', 301, 40)");
$mysqli->query("INSERT INTO order_items (id, order_id, batch_id, individual_piece_id, sku, material_name, grade, quantity, unit, unit_price, line_total) VALUES (401, 201, 101, NULL, 'ORIGINAL-B1', 'Original batch name', 'A', 3.5, 'kg', 10, 35), (402, 202, NULL, 101, 'ORIGINAL-P1', 'Original piece name', 'A', 2, 'piece', 20, 40)");

$payload = static function (int $id) use ($mysqli): array {
    $state = orderEditState($mysqli, $id);
    $input = array_intersect_key($state['order'], array_flip(['client_name', 'email', 'phone', 'street_address', 'postal_code', 'city', 'status', 'payment_status']));
    $input['revision'] = orderEditRevision($state);
    $input['items'] = array_map(static fn(array $row): array => ['line_id' => (string) $row['id'], 'inventory_key' => orderInventoryKey($row), 'quantity' => $row['quantity'], 'unit_price' => $row['unit_price']], $state['items']);
    return $input;
};
$stock = static fn(string $table, int $id): float => (float) $mysqli->query("SELECT stock FROM {$table} WHERE id = {$id}")->fetch_assoc()['stock'];
$databaseState = static function () use ($mysqli): string {
    $state = [];
    foreach (['batches', 'individual_pieces', 'clients', 'orders', 'order_items'] as $table) {
        $state[$table] = $mysqli->query("SELECT * FROM {$table} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    }
    return hash('sha256', json_encode($state));
};
$reject = static function (int $id, array $input, string $code) use ($mysqli, $databaseState): void {
    $before = $databaseState();
    try {
        updateOrder($mysqli, $id, $input);
        throw new RuntimeException('Expected rejection: ' . $code);
    } catch (DomainException $exception) {
        verify($exception->getMessage() === $code, 'Unexpected rejection: ' . $exception->getMessage());
    }
    verify($before === $databaseState(), 'Rejected save changed records');
};

$input = $payload(201);
$stale = $input;
$input['payment_status'] = 'paid';
updateOrder($mysqli, 201, $input);
$state = orderEditState($mysqli, 201);
verify($stock('batches', 101) === 96.5 && $state['items'][0]['unit_price'] === '10.00' && $state['items'][0]['sku'] === 'ORIGINAL-B1', 'Status-only edit changed stock or history');
$reject(201, $stale, 'conflict');

$input = $payload(201); $input['items'][0]['quantity'] = '5.25';
updateOrder($mysqli, 201, $input);
verify($stock('batches', 101) === 94.75 && orderEditState($mysqli, 201)['order']['total_amount'] === '52.50', 'Increasing batch quantity failed');
$input = $payload(201); $input['items'][0]['quantity'] = '1.25';
updateOrder($mysqli, 201, $input);
verify($stock('batches', 101) === 98.75, 'Decreasing batch quantity failed');

$input = $payload(202); $input['items'][0]['quantity'] = '1.5';
$reject(202, $input, 'invalid_quantity');
$input = $payload(201); $input['items'][0]['line_id'] = '402';
$reject(201, $input, 'invalid_items');
$input = $payload(201); $input['items'][] = $input['items'][0];
$reject(201, $input, 'duplicate_item');
$input = $payload(201); $input['status'] = 'invalid';
$reject(201, $input, 'invalid_status');
$input = $payload(201); $input['items'][0]['inventory_key'] = 'piece:101'; $input['items'][0]['quantity'] = '1';
$reject(201, $input, 'invalid_items');
$input = $payload(201); $input['items'][0]['unit_price'] = '99999999.99'; $input['items'][0]['quantity'] = '99999999.99';
$reject(201, $input, 'total_too_large');

// Failure on the second inventory row must roll back the first stock update.
$input = $payload(201); $input['items'][0]['quantity'] = '4';
$input['items'][] = ['line_id' => '0', 'inventory_key' => 'batch:102', 'quantity' => '1000', 'unit_price' => '12.34'];
$reject(201, $input, 'insufficient_stock');

$input = $payload(201);
$input['items'][] = ['line_id' => '0', 'inventory_key' => 'batch:102', 'quantity' => '2', 'unit_price' => '12.34'];
updateOrder($mysqli, 201, $input);
verify($stock('batches', 102) === 48.0 && orderEditState($mysqli, 201)['order']['total_amount'] === '37.18', 'Adding a line failed');
$input = $payload(201); array_shift($input['items']);
updateOrder($mysqli, 201, $input);
verify($stock('batches', 101) === 100.0 && count(orderEditState($mysqli, 201)['items']) === 1, 'Removing a line failed to return stock');
$input = $payload(201); $input['items'][0]['inventory_key'] = 'batch:101'; $input['items'][0]['quantity'] = '1'; $input['items'][0]['unit_price'] = '10';
updateOrder($mysqli, 201, $input);
verify($stock('batches', 102) === 50.0 && $stock('batches', 101) === 99.0 && orderEditState($mysqli, 201)['items'][0]['material_name'] === 'Current batch name', 'Replacing material failed');

$input = $payload(201); $input['status'] = 'cancelled';
updateOrder($mysqli, 201, $input);
verify($stock('batches', 101) === 100.0, 'Cancellation did not return stock');
$input = $payload(201); $input['items'][0]['quantity'] = '5';
updateOrder($mysqli, 201, $input);
verify($stock('batches', 101) === 100.0, 'Saving cancelled order returned stock twice');
$input = $payload(201); $input['status'] = 'open';
updateOrder($mysqli, 201, $input);
verify($stock('batches', 101) === 95.0, 'Reopening did not deduct stock');
$input = $payload(201); $input['status'] = 'delivered';
updateOrder($mysqli, 201, $input);
verify($stock('batches', 101) === 95.0, 'Delivery deducted stock twice');
$input = $payload(201); $input['status'] = 'cancelled';
updateOrder($mysqli, 201, $input);
$mysqli->query('UPDATE batches SET stock = 0 WHERE id = 101');
$input = $payload(201); $input['status'] = 'open';
$reject(201, $input, 'insufficient_stock');

$input = $payload(202); $input['items'][0]['quantity'] = '3'; $input['items'][0]['unit_price'] = '35.50'; $input['payment_status'] = 'paid';
updateOrder($mysqli, 202, $input);
$state = orderEditState($mysqli, 202);
verify($stock('individual_pieces', 101) === 7.0 && $state['order']['total_amount'] === '106.50' && $state['items'][0]['material_name'] === 'Original piece name', 'Piece price/quantity edit failed');
verify($stock('batches', 101) === 0.0, 'Piece order touched batch with same ID');
$input = $payload(202); $input['city'] = 'Updated City';
updateOrder($mysqli, 202, $input);
verify(orderEditState($mysqli, 202)['order']['city'] === 'Updated City', 'Customer edit failed');

$_SESSION = ['csrf_token' => 'test-token'];
$orderDetailId = 202; $orderEditError = null; $orderEditConflict = false;
$_POST = $payload(202); $_POST['csrf_token'] = 'invalid';
$before = $databaseState();
include __DIR__ . '/../actions/manage_order.php';
verify($orderEditError !== null && $before === $databaseState(), 'CSRF validation failed');
$_GET = []; $_POST = []; $orderEditError = null;
ob_start(); include __DIR__ . '/../pages/order-detail.php'; $html = ob_get_clean();
verify($orderLoadError === null && str_contains($html, 'value="35.50"') && str_contains($html, 'value="piece:101"') && str_contains($html, 'Original piece name'), 'Detail page did not prefill historical data');
$orderEditError = 'Test validation error'; $_POST = $payload(202); $_POST['client_name'] = '<script>alert(1)</script>';
ob_start(); include __DIR__ . '/../pages/order-detail.php'; $html = ob_get_clean();
verify(!str_contains($html, '<script>alert') && str_contains($html, '&lt;script&gt;'), 'Failed form values were not escaped');
$orderDetailId = 99999; $orderEditError = null;
ob_start(); include __DIR__ . '/../pages/order-detail.php'; $html = ob_get_clean();
verify(str_contains($html, 'Order unavailable') && !str_contains($html, 'id="order-edit-form"'), 'Missing-order page failed');
ob_start(); include __DIR__ . '/../pages/orders.php'; $html = ob_get_clean();
verify($ordersError === null && str_contains($html, '?page=order-detail&amp;id=202') && (float) $orderStats['revenue'] === 106.5, 'Overview links or cancelled-order totals failed');
$rejectAction = static function (int $id, array $input, string $code) use ($mysqli, $databaseState): void {
    $before = $databaseState();
    try {
        applyOrderAction($mysqli, $id, $input);
        throw new RuntimeException('Expected action rejection: ' . $code);
    } catch (DomainException $exception) {
        verify($exception->getMessage() === $code, 'Unexpected action rejection: ' . $exception->getMessage());
    }
    verify($before === $databaseState(), 'Rejected action changed records');
};
$rejectAction(202, ['action' => ['delete']], 'invalid_action');
$rejectAction(202, ['action' => 'unknown'], 'invalid_action');

// Shipping and reopening save the status without deducting stock a second time.
$oldRevision = $payload(202)['revision'];
$beforeStock = $stock('individual_pieces', 101);
foreach (['shipped', 'open'] as $shippingStatus) {
    $input = $payload(202); $input['status'] = $shippingStatus;
    verify(applyOrderAction($mysqli, 202, $input) === 'saved', 'Status action result was incorrect');
    $state = orderEditState($mysqli, 202);
    verify($state['order']['status'] === $shippingStatus && $state['order']['payment_status'] === 'paid'
        && $state['order']['total_amount'] === '106.50' && $stock('individual_pieces', 101) === $beforeStock,
        'Shipping toggle changed payment, totals, or stock');
    if ($shippingStatus === 'shipped') {
        $rejectAction(202, ['action' => 'delete', 'revision' => $oldRevision], 'conflict');
    }
}
$rejectAction(202, ['action' => 'delete', 'revision' => ''], 'conflict');

// Deletion has the same CSRF guard and its errors must not blank the edit form.
$orderDetailId = 202; $orderEditConflict = false; $orderEditError = null;
$_POST = ['action' => 'delete', 'revision' => $payload(202)['revision'], 'csrf_token' => 'invalid'];
$before = $databaseState();
include __DIR__ . '/../actions/manage_order.php';
verify($orderEditError !== null && $before === $databaseState(), 'Delete bypassed CSRF validation');
ob_start(); include __DIR__ . '/../pages/order-detail.php'; $html = ob_get_clean();
verify(str_contains($html, 'value="Test Customer"') && str_contains($html, 'id="order-shipping-button"')
    && str_contains($html, 'id="order-delete-form"'), 'Delete error blanked the edit page or controls are missing');

// Exercise each persisted status with both inventory tables (including overlapping IDs).
foreach (['open', 'processing', 'shipped', 'delivered', 'cancelled'] as $index => $deleteStatus) {
    $deleteId = 310 + $index;
    $mysqli->query("INSERT INTO orders (id, order_number, client_id, status, total_amount) VALUES ({$deleteId}, 'TEST-DELETE-{$deleteId}', 301, '{$deleteStatus}', 32.50)");
    $mysqli->query("INSERT INTO order_items (order_id, batch_id, individual_piece_id, material_name, grade, quantity, unit, unit_price, line_total) VALUES
        ({$deleteId}, 101, NULL, 'Delete batch', 'A', 1.25, 'kg', 10, 12.50),
        ({$deleteId}, NULL, 101, 'Delete piece', 'A', 2, 'piece', 10, 20)");
    $batchStock = $stock('batches', 101); $pieceStock = $stock('individual_pieces', 101);
    $otherOrder = orderEditRevision(orderEditState($mysqli, 202));
    $input = ['action' => 'delete', 'revision' => $payload($deleteId)['revision']];
    verify(applyOrderAction($mysqli, $deleteId, $input) === 'deleted', 'Delete action result was incorrect');
    $returnsStock = in_array($deleteStatus, ['open', 'processing'], true);
    verify($stock('batches', 101) === $batchStock + ($returnsStock ? 1.25 : 0)
        && $stock('individual_pieces', 101) === $pieceStock + ($returnsStock ? 2 : 0), 'Incorrect deletion stock adjustment for ' . $deleteStatus);
    verify(orderEditState($mysqli, $deleteId) === null
        && (int) $mysqli->query("SELECT COUNT(*) AS n FROM order_items WHERE order_id = {$deleteId}")->fetch_assoc()['n'] === 0,
        'Deletion left the order or its lines behind');
    verify($otherOrder === orderEditRevision(orderEditState($mysqli, 202)), 'Deletion changed another order or its customer');
    $rejectAction($deleteId, $input, 'not_found');
}

// If restoring a later inventory row fails, roll back earlier stock changes and keep the order.
$mysqli->query("INSERT INTO orders (id, order_number, client_id) VALUES (320, 'TEST-DELETE-ROLLBACK', 301)");
$mysqli->query("INSERT INTO order_items (order_id, batch_id, individual_piece_id, material_name, grade, quantity, unit, unit_price, line_total) VALUES
    (320, 101, NULL, 'Rollback batch', 'A', 1, 'kg', 10, 10), (320, NULL, 101, 'Rollback piece', 'A', 1, 'piece', 10, 10)");
$pieceStock = $stock('individual_pieces', 101);
$mysqli->query('UPDATE individual_pieces SET stock = 99999999.99 WHERE id = 101');
$rejectAction(320, ['action' => 'delete', 'revision' => $payload(320)['revision']], 'stock_limit');
$mysqli->query("UPDATE individual_pieces SET stock = {$pieceStock} WHERE id = 101");

echo "Order-editing integration checks passed: history, stock, customer/payment/status, editing, shipping/reopening, deletion for all statuses, cascade, rollback, stale requests, validation, CSRF, escaping, detail pages, and overview totals.\n";
