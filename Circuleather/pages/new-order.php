<?php
if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

$isPieceOrder = $orderType === 'pieces';
$orderUnit = $isPieceOrder ? 'piece' : 'kg';
$orderTable = $isPieceOrder ? 'individual_pieces' : 'batches';
$materialLabel = $isPieceOrder ? t('Los stuk leer', 'Individual leather piece') : t('Materiaalbatch', 'Material batch');
$quantityLabel = $isPieceOrder ? t('Aantal stuks', 'Number of pieces') : t('Hoeveelheid (kg)', 'Quantity (kg)');
$quantityStep = $isPieceOrder ? '1' : '0.01';
$availableBatches = [];
$batchLoadError = null;
try {
    require_once __DIR__ . '/../db.php';
    $availableQuery = $mysqli->prepare(
        "SELECT id, sku, material_name, grade, color, thickness, unit, stock, sale_price
         FROM {$orderTable} WHERE stock >= ? AND unit = ? ORDER BY material_name, sku"
    );
    $minimumQuantity = $isPieceOrder ? 1 : 0.01;
    $availableQuery->bind_param('ds', $minimumQuantity, $orderUnit);
    $availableQuery->execute();
    $availableBatches = $availableQuery->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $exception) {
    error_log('Could not load batches for an order: ' . $exception->getMessage());
    $batchLoadError = t('Materialen konden niet worden geladen.', 'Materials could not be loaded.');
}

$batchOptions = '<option value="">' . ($isPieceOrder ? t('Selecteer een stuk leer', 'Select a leather piece') : t('Selecteer een batch', 'Select a batch')) . '</option>';
foreach ($availableBatches as $availableBatch) {
    $batchLabel = ($availableBatch['sku'] ?? ('#' . $availableBatch['id'])) . ' · '
        . $availableBatch['material_name'] . ' · Grade ' . $availableBatch['grade'];
    if (!empty($availableBatch['color'])) {
        $batchLabel .= ' · ' . $availableBatch['color'];
    }
    $batchOptions .= '<option value="' . (int) $availableBatch['id'] . '"'
        . ' data-unit="' . escape($availableBatch['unit']) . '"'
        . ' data-thickness="' . escape($availableBatch['thickness'] ?? '') . '"'
        . ' data-stock="' . escape((string) $availableBatch['stock']) . '"'
        . ' data-price="' . escape((string) $availableBatch['sale_price']) . '"'
        . '>' . escape($batchLabel) . ' (' . escape((string) $availableBatch['stock']) . ' ' . ($isPieceOrder ? t('stuks', 'pieces') : 'kg') . ')</option>';
}
?>
<section class="page-section new-order-page" aria-labelledby="new-order-title">
    <div class="page-heading">
        <div><p class="eyebrow"><?= t('Bestellingen / Nieuwe order', 'Orders / New order') ?></p><h1 id="new-order-title"><?= t('Order aanmaken', 'Create order') ?></h1></div>
    </div>

    <nav class="order-views" aria-label="<?= t('Ordertype', 'Order type') ?>">
        <a href="?page=new-order&amp;type=batch"<?= !$isPieceOrder ? ' aria-current="page"' : '' ?>><?= t('Batches', 'Batches') ?></a>
        <a href="?page=new-order&amp;type=pieces"<?= $isPieceOrder ? ' aria-current="page"' : '' ?>><?= t('Losse stukken leer', 'Individual pieces') ?></a>
    </nav>

    <?php if ($orderError !== null): ?>
        <p class="form-error" role="alert"><?= $orderError ?></p>
    <?php elseif ($batchLoadError !== null): ?>
        <p class="form-error" role="alert"><?= $batchLoadError ?></p>
    <?php elseif ($availableBatches === []): ?>
        <p class="form-error" role="alert"><?= $isPieceOrder ? t('Er zijn geen losse stukken leer beschikbaar om te bestellen.', 'There are no individual leather pieces available to order.') : t('Er zijn geen batches beschikbaar om te bestellen.', 'There are no batches available to order.') ?> <a href="?page=inventory&amp;view=<?= $isPieceOrder ? 'pieces' : 'batches' ?>"><?= t('Bekijk de voorraad.', 'View inventory.') ?></a></p>
    <?php endif; ?>

    <form class="order-form" action="?page=new-order&amp;type=<?= escape($orderType) ?>" method="post">
        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="order_type" value="<?= escape($orderType) ?>">

        <fieldset class="order-form-section">
            <legend><?= t('01 - Klantgegevens', '01 - Client details') ?></legend>
            <div class="order-client-grid">
                <div>
                    <label for="client-name"><?= t('Naam', 'Name') ?> *</label>
                    <input id="client-name" name="client_name" type="text" maxlength="150" autocomplete="name" required>
                </div>
                <div>
                    <label for="client-email"><?= t('E-mailadres', 'Email address') ?> *</label>
                    <input id="client-email" name="email" type="email" maxlength="254" autocomplete="email" required>
                </div>
                <div>
                    <label for="client-phone"><?= t('Telefoonnummer', 'Phone number') ?> *</label>
                    <input id="client-phone" name="phone" type="tel" maxlength="40" autocomplete="tel" required>
                </div>
                <div>
                    <label for="client-address"><?= t('Adres', 'Street address') ?> *</label>
                    <input id="client-address" name="street_address" type="text" maxlength="255" autocomplete="street-address" required>
                </div>
                <div>
                    <label for="client-postal-code"><?= t('Postcode', 'Postal code') ?> *</label>
                    <input id="client-postal-code" name="postal_code" type="text" maxlength="24" autocomplete="postal-code" required>
                </div>
                <div>
                    <label for="client-city"><?= t('Plaats', 'City') ?> *</label>
                    <input id="client-city" name="city" type="text" maxlength="100" autocomplete="address-level2" required>
                </div>
            </div>
        </fieldset>

        <fieldset class="order-form-section order-items-section">
            <legend><?= t('02 - Materialen', '02 - Materials') ?></legend>
            <p class="order-section-note"><?= $isPieceOrder ? t('Kies een stuk leer en vul het aantal hele stuks in. De prijs is per stuk.', 'Choose a leather piece and enter a whole number of pieces. Prices are per piece.') : t('Kies een batch en vul de bestelde hoeveelheid in kilogram in. De prijs is per kilogram.', 'Choose a batch and enter the quantity in kilograms. Prices are per kilogram.') ?></p>
            <div id="order-items" class="order-items-list">
                <div class="order-item-row" data-order-item>
                    <div class="order-item-heading">
                        <strong data-item-heading data-item-label="<?= t('Artikel', 'Item') ?>"><?= t('Artikel', 'Item') ?> 1</strong>
                        <button class="remove-order-item" type="button" aria-label="<?= t('Artikel verwijderen', 'Remove item') ?>" disabled>&times;</button>
                    </div>
                    <div class="order-item-fields">
                        <div class="order-material-field">
                            <label for="order-batch-0"><?= $materialLabel ?> *</label>
                            <select id="order-batch-0" class="order-batch-select" name="items[0][inventory_id]" required><?= $batchOptions ?></select>
                        </div>
                        <div>
                            <label for="order-quantity-0"><?= $quantityLabel ?> *</label>
                            <input id="order-quantity-0" class="order-quantity" name="items[0][quantity]" type="number" min="<?= $quantityStep ?>" step="<?= $quantityStep ?>" required placeholder="0">
                        </div>
                        <div class="order-item-meta" aria-live="polite">
                            <span class="order-item-thickness"><?= t('Dikte', 'Thickness') ?>: —</span>
                            <span class="order-item-stock"><?= t('Beschikbaar', 'Available') ?>: —</span>
                            <span class="order-item-price"><?= t('Prijs', 'Price') ?>: —</span>
                            <span class="order-item-total"><?= t('Regeltotaal', 'Line total') ?>: —</span>
                        </div>
                    </div>
                </div>
            </div>

            <template id="order-item-template">
                <div class="order-item-row" data-order-item>
                    <div class="order-item-heading">
                        <strong data-item-heading data-item-label="<?= t('Artikel', 'Item') ?>"><?= t('Artikel', 'Item') ?> 2</strong>
                        <button class="remove-order-item" type="button" aria-label="<?= t('Artikel verwijderen', 'Remove item') ?>">&times;</button>
                    </div>
                    <div class="order-item-fields">
                        <div class="order-material-field">
                            <label data-batch-label><?= $materialLabel ?> *</label>
                            <select class="order-batch-select" data-batch-select required><?= $batchOptions ?></select>
                        </div>
                        <div>
                            <label data-quantity-label><?= $quantityLabel ?> *</label>
                            <input class="order-quantity" data-quantity-input type="number" min="<?= $quantityStep ?>" step="<?= $quantityStep ?>" required placeholder="0">
                        </div>
                        <div class="order-item-meta" aria-live="polite">
                            <span class="order-item-thickness"><?= t('Dikte', 'Thickness') ?>: —</span>
                            <span class="order-item-stock"><?= t('Beschikbaar', 'Available') ?>: —</span>
                            <span class="order-item-price"><?= t('Prijs', 'Price') ?>: —</span>
                            <span class="order-item-total"><?= t('Regeltotaal', 'Line total') ?>: —</span>
                        </div>
                    </div>
                </div>
            </template>

            <button id="add-order-item" class="button button-secondary add-order-item" type="button"<?= $availableBatches === [] ? ' disabled' : '' ?>>+ <?= $isPieceOrder ? t('Stuk leer toevoegen', 'Add leather piece') : t('Batch toevoegen', 'Add batch') ?></button>
        </fieldset>

        <div class="order-form-footer">
            <a class="button button-secondary" href="?page=orders"><?= t('Annuleren', 'Cancel') ?></a>
            <div class="order-submit-group">
                <p><?= t('Ordertotaal', 'Order total') ?> <strong id="order-total">€ 0,00</strong></p>
                <button class="button button-primary" type="submit"<?= $availableBatches === [] ? ' disabled' : '' ?>><?= t('Order opslaan', 'Save order') ?></button>
            </div>
        </div>
    </form>
</section>
