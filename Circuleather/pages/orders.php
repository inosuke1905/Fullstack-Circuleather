<?php
if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

$orders = [];
$ordersError = null;
$orderSearch = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$orderStats = [
    'revenue' => 0,
    'open_orders' => 0,
    'delivered_orders' => 0,
    'outstanding' => 0,
];

try {
    require_once __DIR__ . '/../db.php';
    $stats = $mysqli->query(
        "SELECT
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_amount ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN status IN ('open', 'processing') THEN 1 ELSE 0 END), 0) AS open_orders,
            COALESCE(SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered_orders,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' AND status <> 'cancelled' THEN total_amount ELSE 0 END), 0) AS outstanding
         FROM orders"
    );
    $orderStats = $stats->fetch_assoc();

    $searchPattern = '%' . $orderSearch . '%';
    $orderQuery = $mysqli->prepare(
        'SELECT o.id, o.order_number, o.created_at, o.status, o.payment_status, o.total_amount,
            c.full_name AS client_name, COUNT(oi.id) AS item_count,
            COALESCE(SUM(CASE WHEN oi.unit = "kg" THEN oi.quantity ELSE 0 END), 0) AS total_kg,
            COALESCE(SUM(CASE WHEN oi.unit = "piece" THEN oi.quantity ELSE 0 END), 0) AS total_pieces
         FROM orders o
         INNER JOIN clients c ON c.id = o.client_id
         LEFT JOIN order_items oi ON oi.order_id = o.id
         WHERE (? = "" OR o.order_number LIKE ? OR c.full_name LIKE ? OR c.email LIKE ?)
         GROUP BY o.id, o.order_number, o.created_at, o.status, o.payment_status,
            o.total_amount, c.full_name
         ORDER BY o.id DESC'
    );
    $orderQuery->bind_param('ssss', $orderSearch, $searchPattern, $searchPattern, $searchPattern);
    $orderQuery->execute();
    $orders = $orderQuery->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $exception) {
    error_log('Could not load orders: ' . $exception->getMessage());
    $ordersError = t('Bestellingen konden niet worden geladen.', 'Orders could not be loaded.');
}

$formatOrderNumber = static fn ($value): string => number_format((float) $value, 2, ',', '.');
$orderStatusLabel = static fn (string $status): string => match ($status) {
    'processing' => t('VERWERKING', 'PROCESSING'),
    'shipped' => t('VERZONDEN', 'SHIPPED'),
    'delivered' => t('AFGELEVERD', 'DELIVERED'),
    'cancelled' => t('GEANNULEERD', 'CANCELLED'),
    default => t('OPEN', 'OPEN'),
};
?>
<section class="page-section orders-page" aria-labelledby="orders-title">
    <div class="page-heading">
        <div><p class="eyebrow"><?= t('Bestellingen', 'Orders') ?></p><h1 id="orders-title"><?= t('Orderoverzicht', 'Orders overview') ?></h1></div>
        <a class="button button-primary" href="?page=new-order">+ <?= t('Nieuwe order', 'New order') ?></a>
    </div>
    <?php if (isset($_GET['created'])): ?>
        <p class="settings-saved" role="status"><?= t('Order aangemaakt en voorraad bijgewerkt.', 'Order created and inventory updated.') ?></p>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <p class="settings-saved" role="status"><?= t('Order verwijderd.', 'Order deleted.') ?></p>
    <?php endif; ?>
    <?php if ($ordersError !== null): ?>
        <p class="form-error" role="alert"><?= $ordersError ?></p>
    <?php endif; ?>
    <section class="stats-grid" aria-label="<?= t('Orderoverzicht', 'Orders overview') ?>">
        <article class="stat-card"><h2><?= t('Totaal omzet', 'Total revenue') ?></h2><strong>€ <?= $formatOrderNumber($orderStats['revenue']) ?></strong><p><?= (int) $orderStats['open_orders'] ?> <?= t('open orders', 'open orders') ?></p></article>
        <article class="stat-card"><h2><?= t('Open orders', 'Open orders') ?></h2><strong><?= (int) $orderStats['open_orders'] ?></strong><p><?= t('nog te verwerken', 'still to process') ?></p></article>
        <article class="stat-card"><h2><?= t('Afgeleverd', 'Delivered') ?></h2><strong><?= (int) $orderStats['delivered_orders'] ?></strong><p><?= t('succesvol geleverd', 'successfully delivered') ?></p></article>
        <article class="stat-card stat-card-dark"><h2><?= t('Openstaand bedrag', 'Outstanding balance') ?></h2><strong>€ <?= $formatOrderNumber($orderStats['outstanding']) ?></strong><p><?= t('nog te ontvangen', 'yet to be received') ?></p></article>
    </section>
    <div class="table-toolbar">
        <form method="get" action="index.php">
            <input type="hidden" name="page" value="orders">
            <label for="orders-search"><?= t('Zoeken op order of klant', 'Search by order or customer') ?></label>
            <input id="orders-search" type="search" name="q" value="<?= escape($orderSearch) ?>" placeholder="<?= t('Zoek order', 'Search orders') ?>">
            <button type="submit"><?= t('Zoeken', 'Search') ?></button>
        </form>
        <span class="result-count"><?= count($orders) ?> <?= count($orders) === 1 ? t('order', 'order') : t('orders', 'orders') ?></span>
    </div>
    <div class="table-wrap"><table>
        <caption><?= t('Bestellingen', 'Orders') ?></caption>
        <thead>
            <tr>
                <th scope="col"><?= t('Order', 'Order') ?></th>
                <th scope="col"><?= t('Klant', 'Customer') ?></th>
                <th scope="col"><?= t('Datum', 'Date') ?></th>
                <th scope="col"><?= t('Artikelen', 'Items') ?></th>
                <th scope="col"><?= t('Hoeveelheid', 'Quantity') ?></th>
                <th scope="col"><?= t('Omzet', 'Revenue') ?></th>
                <th scope="col"><?= t('Betaald', 'Paid') ?></th>
                <th scope="col"><?= t('Status', 'Status') ?></th>
                <th scope="col"><?= t('Actie', 'Action') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($orders === []): ?>
                <tr><td colspan="9"><?php if ($orderSearch !== ''): ?><?= t('Geen bestellingen gevonden.', 'No orders found.') ?><?php elseif ($ordersError === null): ?><?= t('Er zijn nog geen bestellingen.', 'There are no orders yet.') ?> <a href="?page=new-order"><?= t('Maak de eerste order aan.', 'Create the first order.') ?></a><?php else: ?><?= $ordersError ?><?php endif; ?></td></tr>
            <?php else: ?>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><a href="?page=order-detail&amp;id=<?= (int) $order['id'] ?>"><?= escape($order['order_number']) ?></a></td>
                        <td><?= escape($order['client_name']) ?></td>
                        <td><?= escape(date('d M Y', strtotime($order['created_at']))) ?></td>
                        <td><?= (int) $order['item_count'] ?> <?= t('artikelen', 'items') ?></td>
                        <td><?php if ((float) $order['total_kg'] > 0): ?><?= $formatOrderNumber($order['total_kg']) ?> kg<?php endif; ?><?php if ((float) $order['total_kg'] > 0 && (float) $order['total_pieces'] > 0): ?><br><?php endif; ?><?php if ((float) $order['total_pieces'] > 0): ?><?= (int) $order['total_pieces'] ?> <?= t('stuks', 'pieces') ?><?php endif; ?></td>
                        <td>€ <?= $formatOrderNumber($order['total_amount']) ?></td>
                        <td><?= $order['payment_status'] === 'paid' ? t('Betaald', 'Paid') : t('Openstaand', 'Unpaid') ?></td>
                        <td><span class="order-status-badge order-status-<?= escape($order['status']) ?>"><?= $orderStatusLabel($order['status']) ?></span></td>
                        <td><a class="button button-secondary button-small view-batch-button" href="?page=order-detail&amp;id=<?= (int) $order['id'] ?>" aria-label="<?= t('Bekijk en bewerk order', 'View and edit order') ?>" title="<?= t('Bekijk en bewerk order', 'View and edit order') ?>"><span aria-hidden="true">&#128269;</span></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table></div>
</section>
