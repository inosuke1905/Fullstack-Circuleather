<?php
/*
 * Loads and displays an order for editing, including its material snapshots and change history.
 * Available stock includes this order's existing reservation so unchanged lines remain valid.
 * The hidden revision is checked by the server to avoid overwriting another person's changes.
 */

if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../lib/order-editing.php';
$orderState = null;
$orderLoadError = null;
$catalog = [];
try {
    require_once __DIR__ . '/../db.php';
    $orderState = orderEditState($mysqli, $orderDetailId);
    if ($orderState !== null) {
        $allowedTypes = [];
        // Add back this order's current reservation when calculating stock available to its editor.
        $reserved = [];
        foreach ($orderState['items'] as $line) {
            $key = orderInventoryKey($line);
            $allowedTypes[explode(':', $key)[0]] = true;
            $reserved[$key] = ($reserved[$key] ?? 0) + ($orderState['order']['status'] === 'cancelled' ? 0 : orderHundredths($line['quantity']));
        }
        foreach ($allowedTypes as $type => $_) {
            $table = $type === 'piece' ? 'individual_pieces' : 'batches';
            foreach ($mysqli->query("SELECT * FROM {$table} ORDER BY material_name, sku")->fetch_all(MYSQLI_ASSOC) as $row) {
                $key = $type . ':' . $row['id'];
                $row['available'] = orderDecimal(orderHundredths($row['stock']) + ($reserved[$key] ?? 0));
                $catalog[$key] = $row;
            }
        }
    }
} catch (Throwable $exception) {
    error_log('Order details failed: ' . $exception->getMessage());
    $orderLoadError = t('De ordergegevens konden niet worden geladen.', 'Order details could not be loaded.');
}
$orderChangeHistory = [];
if ($orderState !== null && isset($mysqli)) {
    try {
        $targetUrl = '?page=order-detail&id=' . $orderDetailId;
        $historyQuery = $mysqli->prepare(
            "SELECT actor_name, created_at, details_json FROM notifications
             WHERE recipient_user_id = ? AND type = 'order_activity'
                AND event_action = 'updated' AND target_url = ? AND details_json IS NOT NULL
             ORDER BY id DESC LIMIT 10"
        );
        $currentUserId = (int) $_SESSION['user_id'];
        $historyQuery->bind_param('is', $currentUserId, $targetUrl);
        $historyQuery->execute();
        foreach ($historyQuery->get_result()->fetch_all(MYSQLI_ASSOC) as $entry) {
            $changes = json_decode($entry['details_json'], true);
            if (is_array($changes) && $changes !== []) {
                $entry['changes'] = $changes;
                $orderChangeHistory[] = $entry;
            }
        }
    } catch (Throwable $exception) {
        error_log('Could not load order change history: ' . $exception->getMessage());
    }
}
// Keep invalid submitted values for correction; after a revision conflict show the latest saved state.
$usePosted = $orderEditError !== null && !$orderEditConflict && ($_POST['action'] ?? '') !== 'delete';
$formValue = static function (string $key) use ($orderState, $usePosted): string {
    $value = $usePosted ? ($_POST[$key] ?? '') : ($orderState['order'][$key] ?? '');
    return is_string($value) ? $value : '';
};
$formatOrderMoney = static fn ($value): string => number_format((float) $value, 2, ',', '.');
$orderChangeLabels = [
    'client_name' => t('Klantnaam', 'Customer name'),
    'email' => t('E-mailadres', 'Email address'),
    'phone' => t('Telefoonnummer', 'Phone number'),
    'street_address' => t('Adres', 'Street address'),
    'postal_code' => t('Postcode', 'Postal code'),
    'city' => t('Plaats', 'City'),
    'status' => t('Orderstatus', 'Order status'),
    'payment_status' => t('Betaalstatus', 'Payment status'),
    'order_items' => t('Orderregels', 'Order items'),
];
$formatChangeValue = static fn ($value): string => $value === null || $value === ''
    ? t('Leeg', 'Empty')
    : escape(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
?>
<section class="page-section order-details-page" aria-labelledby="order-details-title">
    <div class="page-heading">
        <div><p class="eyebrow"><a href="?page=orders"><?= t('Bestellingen', 'Orders') ?></a> / <?= t('Orderdetails', 'Order details') ?></p><h1 id="order-details-title"><?= t('Order bekijken en bewerken', 'View and edit order') ?></h1></div>
        <?php if ($orderState !== null): ?><span class="detail-sku"><?= escape($orderState['order']['order_number']) ?></span><?php endif; ?>
    </div>
    <?php if ($orderEditError !== null): ?><p class="form-error" role="alert"><?= $orderEditError ?></p><?php endif; ?>
    <?php if ($orderLoadError !== null): ?><p class="form-error" role="alert"><?= $orderLoadError ?></p><?php endif; ?>
    <?php if ($orderState === null || $orderLoadError !== null): ?>
        <section class="settings-panel">
            <h2><?= t('Order niet beschikbaar', 'Order unavailable') ?></h2>
            <p><?= t('Deze order bestaat niet of kon niet worden geladen.', 'This order does not exist or could not be loaded.') ?></p>
            <a class="button button-secondary" href="?page=orders"><?= t('Terug naar bestellingen', 'Back to orders') ?></a>
        </section>
    <?php else: ?>
        <?php
        $linesById = array_column($orderState['items'], null, 'id');
        $formLines = [];
        foreach ($orderState['items'] as $line) {
            $formLines[] = ['line_id' => (string) $line['id'], 'inventory_key' => orderInventoryKey($line), 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price']];
        }
        if ($usePosted && is_array($_POST['items'] ?? null)) {
            $formLines = array_slice(array_values(array_filter($_POST['items'], 'is_array')), 0, 30);
        }
        if ($formLines === []) {
            $formLines[] = [];
        }
        $renderLine = static function (array $line, string $index) use ($catalog, $linesById): void {
            $value = static fn (string $key): string => is_string($line[$key] ?? null) ? $line[$key] : '';
            $selected = $value('inventory_key');
            $old = $linesById[(int) $value('line_id')] ?? null;
            $piece = str_starts_with($selected, 'piece:');
            ?>
            <div class="order-item-row" data-edit-item>
                <input type="hidden" data-line-id name="items[<?= escape($index) ?>][line_id]" value="<?= escape($value('line_id') ?: '0') ?>">
                <div class="order-item-heading">
                    <strong data-edit-heading><?= t('Artikel', 'Item') ?> <?= ctype_digit($index) ? (int) $index + 1 : '' ?></strong>
                    <button class="remove-order-item" type="button" data-edit-remove aria-label="<?= t('Artikel verwijderen', 'Remove item') ?>">&times;</button>
                </div>
                <div class="order-edit-item-fields">
                    <div>
                        <label for="edit-material-<?= escape($index) ?>"><?= t('Materiaal', 'Material') ?> *</label>
                        <select id="edit-material-<?= escape($index) ?>" name="items[<?= escape($index) ?>][inventory_key]" data-edit-material required>
                            <option value=""><?= t('Selecteer materiaal', 'Select material') ?></option>
                            <?php foreach ($catalog as $key => $material): ?>
                                <?php $snapshot = $old && orderInventoryKey($old) === $key ? $old : $material; ?>
                                <option value="<?= escape($key) ?>" data-unit="<?= escape($material['unit']) ?>" data-stock="<?= escape($material['available']) ?>" data-price="<?= escape($snapshot['unit_price'] ?? $material['sale_price']) ?>"<?= $selected === $key ? ' selected' : '' ?>><?= escape(($snapshot['sku'] ?? '#'.$material['id']) . ' · ' . $snapshot['material_name'] . ' · Grade ' . $snapshot['grade'] . (!empty($snapshot['color']) ? ' · ' . $snapshot['color'] : '') . (!empty($snapshot['thickness']) ? ' · ' . $snapshot['thickness'] : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="edit-quantity-<?= escape($index) ?>"><?= t('Hoeveelheid', 'Quantity') ?> *</label>
                        <input id="edit-quantity-<?= escape($index) ?>" name="items[<?= escape($index) ?>][quantity]" data-edit-quantity type="number" min="<?= $piece ? '1' : '0.01' ?>" step="<?= $piece ? '1' : '0.01' ?>" value="<?= escape($value('quantity')) ?>" required>
                    </div>
                    <div>
                        <label for="edit-price-<?= escape($index) ?>"><?= t('Prijs per eenheid (€)', 'Unit price (€)') ?> *</label>
                        <input id="edit-price-<?= escape($index) ?>" name="items[<?= escape($index) ?>][unit_price]" data-edit-price type="number" min="0" max="99999999.99" step="0.01" value="<?= escape($value('unit_price')) ?>" required>
                    </div>
                </div>
                <div class="order-edit-item-summary"><span data-edit-stock></span><strong data-edit-line-total></strong></div>
            </div>
            <?php
        };
        $revision = $usePosted && is_string($_POST['revision'] ?? null) ? $_POST['revision'] : orderEditRevision($orderState);
        ?>
        <?php if (isset($_GET['saved']) && $orderEditError === null): ?><p class="settings-saved" role="status"><?= t('Orderwijzigingen opgeslagen en voorraad bijgewerkt.', 'Order changes saved and inventory updated.') ?></p><?php elseif (isset($_GET['created']) && $orderEditError === null): ?><p class="settings-saved" role="status"><?= t('Order aangemaakt en voorraad bijgewerkt.', 'Order created and inventory updated.') ?></p><?php endif; ?>
        <?php $shareResourceType = 'order'; $shareResourceId = (int) $orderState['order']['id']; require __DIR__ . '/share-controls.php'; ?>
        <?php if ($orderChangeHistory !== []): ?>
            <section class="change-history" aria-labelledby="order-history-title">
                <h2 id="order-history-title"><?= t('Wat is gewijzigd', 'What changed') ?></h2>
                <?php foreach ($orderChangeHistory as $entry): ?>
                    <article class="change-history-entry">
                        <p class="change-history-meta"><strong><?= escape($entry['actor_name']) ?></strong><time datetime="<?= escape(date(DATE_ATOM, strtotime($entry['created_at']))) ?>"><?= escape(date('d M Y, H:i', strtotime($entry['created_at']))) ?></time></p>
                        <dl>
                            <?php foreach ($entry['changes'] as $field => $change): ?>
                                <div><dt><?= $orderChangeLabels[$field] ?? escape((string) $field) ?></dt><dd><span class="change-before"><?= $formatChangeValue($change['before'] ?? null) ?></span><span class="change-arrow" aria-hidden="true">&rarr;</span><span class="change-after"><?= $formatChangeValue($change['after'] ?? null) ?></span></dd></div>
                            <?php endforeach; ?>
                        </dl>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
        <form class="order-form" id="order-edit-form" action="?page=order-detail&amp;id=<?= (int) $orderState['order']['id'] ?>" method="post">
            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="revision" value="<?= escape($revision) ?>">
            <fieldset class="order-form-section">
                <legend><?= t('01 - Klantgegevens', '01 - Customer details') ?></legend>
                <div class="order-client-grid">
                    <?php foreach ([
                        ['client_name', t('Naam', 'Name'), 'text', 150, 'name'],
                        ['email', t('E-mailadres', 'Email address'), 'email', 254, 'email'],
                        ['phone', t('Telefoonnummer', 'Phone number'), 'tel', 40, 'tel'],
                        ['street_address', t('Adres', 'Street address'), 'text', 255, 'street-address'],
                        ['postal_code', t('Postcode', 'Postal code'), 'text', 24, 'postal-code'],
                        ['city', t('Plaats', 'City'), 'text', 100, 'address-level2'],
                    ] as [$name, $label, $type, $limit, $autocomplete]): ?>
                        <div><label for="edit-<?= $name ?>"><?= $label ?> *</label><input id="edit-<?= $name ?>" name="<?= $name ?>" type="<?= $type ?>" maxlength="<?= $limit ?>" autocomplete="<?= $autocomplete ?>" value="<?= escape($formValue($name)) ?>" required></div>
                    <?php endforeach; ?>
                </div>
                <p class="settings-note"><?= t('Klantgegevens worden gedeeld met andere orders van hetzelfde e-mailadres.', 'Customer details are shared with other orders using the same email address.') ?></p>
            </fieldset>
            <fieldset class="order-form-section">
                <legend><?= t('02 - Orderstatus', '02 - Order status') ?></legend>
                <div class="order-client-grid">
                    <div><label for="edit-status"><?= t('Status', 'Status') ?></label><select id="edit-status" name="status">
                        <?php foreach (['open' => t('Open', 'Open'), 'processing' => t('In verwerking', 'Processing'), 'shipped' => t('Verzonden', 'Shipped'), 'delivered' => t('Afgeleverd', 'Delivered'), 'cancelled' => t('Geannuleerd', 'Cancelled')] as $status => $label): ?>
                            <option value="<?= $status ?>"<?= $formValue('status') === $status ? ' selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                        <button class="button button-secondary order-shipping-button" id="order-shipping-button" type="button" aria-describedby="order-shipping-note" disabled><?= $formValue('status') === 'shipped' ? t('Markeren als open', 'Mark as open') : t('Markeren als verzonden', 'Mark as shipped') ?></button>
                    </div>
                    <div><label for="edit-payment"><?= t('Betaalstatus', 'Payment status') ?></label><select id="edit-payment" name="payment_status">
                        <option value="unpaid"<?= $formValue('payment_status') === 'unpaid' ? ' selected' : '' ?>><?= t('Openstaand', 'Unpaid') ?></option>
                        <option value="paid"<?= $formValue('payment_status') === 'paid' ? ' selected' : '' ?>><?= t('Betaald', 'Paid') ?></option>
                    </select></div>
                </div>
                <p class="settings-note" id="order-shipping-note"><?= t('Met deze knop sla je de status en de overige wijzigingen in de order direct op.', 'This button immediately saves the status and any other changes to the order.') ?></p>
                <p class="settings-note" data-cancel-note<?= $formValue('status') !== 'cancelled' ? ' hidden' : '' ?>><?= t('Bij annuleren gaat het bestelde materiaal terug naar de voorraad. De betaalstatus verandert niet automatisch.', 'Cancelling returns the ordered material to stock. Payment status does not change automatically.') ?></p>
            </fieldset>
            <fieldset class="order-form-section">
                <legend><?= t('03 - Artikelen', '03 - Items') ?></legend>
                <p class="order-section-note"><?= t('Pas materialen, hoeveelheden of prijzen aan. De voorraad wordt bij opslaan automatisch aangepast.', 'Edit materials, quantities, or prices. Stock is adjusted automatically when you save.') ?></p>
                <div class="order-items-list" id="order-edit-items">
                    <?php foreach ($formLines as $index => $line) { $renderLine($line, (string) $index); } ?>
                </div>
                <template id="order-edit-template"><?php $renderLine([], '__INDEX__'); ?></template>
                <button class="button button-secondary add-order-item" id="order-edit-add" type="button">+ <?= t('Artikel toevoegen', 'Add item') ?></button>
            </fieldset>
            <div class="order-form-footer">
                <a class="button button-secondary" href="?page=orders"><?= t('Terug naar bestellingen', 'Back to orders') ?></a>
                <div class="order-submit-group">
                    <p><?= t('Ordertotaal', 'Order total') ?> <strong id="order-edit-total" aria-live="polite">€ <?= $formatOrderMoney($orderState['order']['total_amount']) ?></strong></p>
                    <button class="button button-primary" type="submit" name="action" value="update"><?= t('Wijzigingen opslaan', 'Save changes') ?></button>
                </div>
            </div>
        </form>
        <?php
        $deleteStockNote = match ($orderState['order']['status']) {
            'open', 'processing' => t('Het gereserveerde materiaal gaat terug naar de voorraad.', 'The reserved material will be returned to stock.'),
            'cancelled' => t('De voorraad is bij annulering al teruggezet en blijft ongewijzigd.', 'Stock was already returned on cancellation and will remain unchanged.'),
            default => t('De voorraad blijft ongewijzigd, omdat de order al is verzonden.', 'Stock will remain unchanged because the order has already been shipped.'),
        };
        ?>
        <form class="delete-order-form" id="order-delete-form" action="?page=order-detail&amp;id=<?= (int) $orderState['order']['id'] ?>" method="post" data-confirm="<?= t('Weet je zeker dat je deze order permanent wilt verwijderen?', 'Are you sure you want to permanently delete this order?') ?> <?= $deleteStockNote ?>">
            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="revision" value="<?= escape(orderEditRevision($orderState)) ?>">
            <input type="hidden" name="action" value="delete">
            <p class="settings-note"><?= t('Deze order en de bijbehorende orderregels worden permanent verwijderd.', 'This order and its line items will be permanently deleted.') ?> <?= $deleteStockNote ?></p>
            <button class="button button-danger" type="submit"><?= t('Order verwijderen', 'Delete order') ?></button>
        </form>
    <?php endif; ?>
</section>
