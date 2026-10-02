<?php
/*
 * CLI integration checks for separate batch/piece storage, rendering, editing and deletion.
 * Each command mode exercises a real action or template using known test fixtures.
 * The DB_NAME guard prevents fixture writes against the application database.
 */

// Run only against the disposable migration rehearsal database.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('DB_NAME') !== 'circuleather_storage_test') {
    throw new RuntimeException('This test requires the isolated circuleather_storage_test database.');
}
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('CIRCULEATHER_APP', true);
function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function t(string $dutch, string $english): string { return escape($english); }
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
require __DIR__ . '/../db.php';
// Supply the session context normally initialized by index.php.
$_SESSION = ['csrf_token' => 'storage-smoke', 'user_id' => 0, 'full_name' => 'Storage test'];
$_FILES = [];
// Separate CLI modes are needed because successful action handlers redirect and exit.
$mode = $argv[1] ?? 'render';
$pieceMode = !str_contains($mode, 'batch');
$sku = $mode === 'create-delete-piece' || $mode === 'delete-piece' ? 'TEST-PIECE-DELETE' : ($pieceMode ? 'TEST-PIECE-STORAGE' : 'TEST-BATCH-STORAGE');
$table = $pieceMode ? 'individual_pieces' : 'batches';
$findFixture = static function () use ($mysqli, $table, $sku): ?array {
    $query = $mysqli->prepare("SELECT * FROM {$table} WHERE sku = ?");
    $query->bind_param('s', $sku);
    $query->execute();
    return $query->get_result()->fetch_assoc();
};
$payload = [
    'csrf_token' => 'storage-smoke', 'sku' => $sku, 'material_name' => 'Storage test',
    'grade' => 'A', 'color' => 'Black', 'thickness' => '2 mm',
    'sale_price' => '20.00', 'cost_price' => '10.00', 'unit' => $pieceMode ? 'piece' : 'kg',
    'stock' => '10', 'minimum_stock' => '1', 'origin' => 'Isolated test',
    'supplier' => 'Test supplier', 'arrival_date' => '',
];

if (str_starts_with($mode, 'create-')) {
    check($findFixture() === null, 'Fixture already exists');
    $_POST = $payload;
    register_shutdown_function(static function () use ($findFixture): void {
        $row = $findFixture();
        check($row !== null && (float) $row['stock'] === 10.0, 'Creation did not save to the correct table');
        echo "Creation passed.\n";
    });
    require __DIR__ . '/../actions/save_batch.php';
    throw new RuntimeException($batchError ?? 'Creation did not redirect');
}

if ($mode === 'edit-piece' || $mode === 'delete-piece' || $mode === 'reject-delete-piece') {
    $fixture = $findFixture();
    check($fixture !== null, 'Missing fixture');
    $batchDetailId = (int) $fixture['id'];
    $isPieceDetail = true;
    $detailTable = 'individual_pieces';
    $detailRoute = 'piece-detail';
    $detailUnit = 'piece';
    $detailInventoryView = 'pieces';
    $detailError = null;
    $_POST = array_merge($payload, ['action' => $mode === 'edit-piece' ? 'update' : 'delete', 'color' => 'Brown']);
    if ($mode !== 'reject-delete-piece') {
        register_shutdown_function(static function () use ($findFixture, $mode): void {
            $row = $findFixture();
            check($mode === 'delete-piece' ? $row === null : ($row !== null && $row['color'] === 'Brown'), 'Edit/delete failed');
            echo "Edit/delete passed.\n";
        });
    }
    require __DIR__ . '/../actions/manage_batch.php';
    check($mode === 'reject-delete-piece' && $detailError !== null && $findFixture() !== null, 'Order-linked piece was deleted');
    echo "Referenced piece deletion correctly rejected.\n";
    exit;
}

// Multiple lines exercise statement reuse with different bound inventory IDs and prices.
if (in_array($mode, ['order-multi-batch', 'order-multi-piece'], true)) {
    $fixture = $findFixture();
    check($fixture !== null, 'Missing first multi-line fixture');
    $beforeStock = (float) $fixture['stock'];
    $mysqli->query("INSERT INTO {$table} (sku, material_name, grade, sale_price, cost_price, unit, stock, supplier)
        VALUES ('TEST-MULTI-SECOND', 'Second multi-line material', 'A', 30, 10, '{$payload['unit']}', 10, 'Test')");
    $secondId = (int) $mysqli->insert_id;
    $firstQuantity = $pieceMode ? '2' : '1.5';
    $secondQuantity = $pieceMode ? '2' : '0.5';
    $_POST = [
        'csrf_token' => 'storage-smoke', 'order_type' => $pieceMode ? 'pieces' : 'batch',
        'client_name' => 'Storage test', 'email' => 'storage-test@example.invalid',
        'street_address' => 'Test street', 'postal_code' => '1000AA', 'city' => 'Test', 'phone' => '123',
        'items' => [
            ['inventory_id' => (string) $fixture['id'], 'quantity' => $firstQuantity],
            ['inventory_id' => (string) $secondId, 'quantity' => $secondQuantity],
        ],
    ];
    register_shutdown_function(static function () use ($mysqli, $findFixture, $fixture, $beforeStock, $table, $pieceMode, $secondId, $firstQuantity, $secondQuantity): void {
        $order = $mysqli->query('SELECT * FROM orders ORDER BY id DESC LIMIT 1')->fetch_assoc();
        $lines = $mysqli->query('SELECT * FROM order_items WHERE order_id = ' . (int) $order['id'] . ' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $reference = $pieceMode ? 'individual_piece_id' : 'batch_id';
        check(count($lines) === 2 && (int) $lines[0][$reference] === (int) $fixture['id']
            && (int) $lines[1][$reference] === $secondId, 'Multi-line inventory references failed');
        check((float) $lines[0]['unit_price'] === 20.0 && (float) $lines[1]['unit_price'] === 30.0
            && (float) $order['total_amount'] === (float) $firstQuantity * 20 + (float) $secondQuantity * 30, 'Multi-line prices or total failed');
        $second = $mysqli->query("SELECT stock FROM {$table} WHERE id = {$secondId}")->fetch_assoc();
        check((float) $findFixture()['stock'] === $beforeStock - (float) $firstQuantity
            && (float) $second['stock'] === 10 - (float) $secondQuantity, 'Multi-line stock deductions failed');
        echo "Multi-line order, snapshots, totals and stock deductions passed.\n";
    });
    require __DIR__ . '/../actions/save_order.php';
    throw new RuntimeException($orderError ?? 'Multi-line creation did not redirect');
}

if (str_starts_with($mode, 'order-') || $mode === 'reject-fraction') {
    $fixture = $findFixture();
    check($fixture !== null, 'Missing order fixture');
    $beforeStock = (float) $fixture['stock'];
    $quantity = $pieceMode ? '2' : '1.5';
    if ($mode === 'reject-fraction') { $quantity = '1.5'; }
    $_POST = [
        'csrf_token' => 'storage-smoke', 'order_type' => $pieceMode ? 'pieces' : 'batch',
        'client_name' => 'Storage test', 'email' => 'storage-test@example.invalid',
        'street_address' => 'Test street', 'postal_code' => '1000AA', 'city' => 'Test', 'phone' => '123',
        'items' => [['inventory_id' => (string) $fixture['id'], 'quantity' => $quantity]],
    ];
    $orderError = null;
    if ($mode !== 'reject-fraction') {
        register_shutdown_function(static function () use ($mysqli, $findFixture, $fixture, $pieceMode, $quantity, $beforeStock): void {
            check((float) $findFixture()['stock'] === $beforeStock - (float) $quantity, 'Stock deduction failed');
            $item = $mysqli->query('SELECT * FROM order_items ORDER BY id DESC LIMIT 1')->fetch_assoc();
            $reference = $pieceMode ? 'individual_piece_id' : 'batch_id';
            $other = $pieceMode ? 'batch_id' : 'individual_piece_id';
            check((int) $item[$reference] === (int) $fixture['id'] && $item[$other] === null, 'Wrong order reference');
            check($item['sku'] === $fixture['sku'] && (float) $item['line_total'] === (float) $quantity * 20, 'Order snapshot/price incorrect');
            echo "Order and stock deduction passed.\n";
        });
    }
    require __DIR__ . '/../actions/save_order.php';
    check($mode === 'reject-fraction' && $orderError !== null && (float) $findFixture()['stock'] === $beforeStock, 'Fractional piece validation failed');
    echo "Fractional piece order correctly rejected.\n";
    exit;
}

foreach (['batches', 'pieces'] as $view) {
    $_GET = ['view' => $view];
    ob_start();
    require __DIR__ . '/../pages/inventory.php';
    $html = ob_get_clean();
    check($inventoryError === null, 'Inventory failed');
    $expectedTable = $view === 'pieces' ? 'individual_pieces' : 'batches';
    $expected = $mysqli->query("SELECT * FROM {$expectedTable} ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
    check(array_column($inventoryBatches, 'sku') === array_column($expected, 'sku'), 'Inventory reads the wrong table');
    check((float) $inventoryStats['total_stock'] === array_sum(array_column($expected, 'stock')), 'Inventory totals wrong');
    $orderType = $view === 'pieces' ? 'pieces' : 'batch';
    $orderError = null;
    ob_start();
    require __DIR__ . '/../pages/new-order.php';
    $html = ob_get_clean();
    check($batchLoadError === null && str_contains($html, 'items[0][inventory_id]'), 'Order form failed');
    foreach ($availableBatches as $row) { check(in_array($row['sku'], array_column($expected, 'sku'), true), 'Order options use wrong table'); }
    $fixture = $expected[0];
    $batchDetailId = (int) $fixture['id'];
    $isPieceDetail = $view === 'pieces';
    $detailTable = $expectedTable;
    $detailRoute = $isPieceDetail ? 'piece-detail' : 'batch-detail';
    $detailUnit = $isPieceDetail ? 'piece' : 'kg';
    $detailInventoryView = $view;
    $detailError = null;
    ob_start();
    require __DIR__ . '/../pages/batch-detail.php';
    $html = ob_get_clean();
    check($batchDetailLoadError === null && $batchDetail['sku'] === $fixture['sku'], 'Detail page loads wrong table');
    check(str_contains($html, 'action="?page=' . $detailRoute), 'Detail action uses wrong route');
}
ob_start();
require __DIR__ . '/../pages/orders.php';
ob_end_clean();
check($ordersError === null, 'Orders overview failed');
echo "Inventory, totals, order forms, details, and order history passed.\n";
