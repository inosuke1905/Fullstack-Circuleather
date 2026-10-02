<?php
if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

$batchDetail = null;
$batchDetailLoadError = null;
if ($batchDetailId > 0) {
    try {
        require_once __DIR__ . '/../db.php';
        $findBatch = $mysqli->prepare("SELECT * FROM {$detailTable} WHERE id = ?");
        $findBatch->bind_param('i', $batchDetailId);
        $findBatch->execute();
        $batchDetail = $findBatch->get_result()->fetch_assoc() ?: null;
    } catch (Throwable $exception) {
        error_log('Could not load batch details: ' . $exception->getMessage());
        $batchDetailLoadError = t('Materiaalgegevens konden niet worden geladen.', 'Material details could not be loaded.');
    }
}
$inventoryChangeHistory = [];
if ($batchDetail !== null) {
    try {
        $targetUrl = '?page=' . $detailRoute . '&id=' . $batchDetailId;
        $historyQuery = $mysqli->prepare(
            "SELECT actor_name, created_at, details_json FROM notifications
             WHERE recipient_user_id = ? AND type = 'inventory_activity'
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
                $inventoryChangeHistory[] = $entry;
            }
        }
    } catch (Throwable $exception) {
        error_log('Could not load inventory change history: ' . $exception->getMessage());
    }
}
$inventoryChangeLabels = [
    'sku' => 'SKU',
    'material_name' => t('Materiaalnaam', 'Material name'),
    'grade' => 'Grade',
    'color' => t('Kleur', 'Color'),
    'thickness' => t('Dikte', 'Thickness'),
    'sale_price' => t('Verkoopprijs', 'Sale price'),
    'cost_price' => t('Inkoopprijs', 'Cost price'),
    'unit' => t('Eenheid', 'Unit'),
    'stock' => t('Voorraad', 'Stock'),
    'minimum_stock' => t('Minimale voorraad', 'Minimum stock'),
    'origin' => t('Herkomst', 'Origin'),
    'supplier' => t('Leverancier', 'Supplier'),
    'arrival_date' => t('Datum', 'Date'),
];
$formatChangeValue = static fn ($value): string => $value === null || $value === ''
    ? t('Leeg', 'Empty')
    : escape(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
?>
<section class="page-section batch-details-page" aria-labelledby="batch-details-title">
    <div class="page-heading">
        <div>
            <p class="eyebrow"><a href="?page=inventory&amp;view=<?= $detailInventoryView ?>"><?= t('Voorraad', 'Inventory') ?></a> / <?= $isPieceDetail ? t('Leerstukdetails', 'Leather piece details') : t('Batchdetails', 'Batch details') ?></p>
            <h1 id="batch-details-title"><?= $isPieceDetail ? t('Stuk leer bekijken en bewerken', 'View and edit leather piece') : t('Batch bekijken en bewerken', 'View and edit batch') ?></h1>
        </div>
        <?php if ($batchDetail !== null): ?>
            <span class="detail-sku"><?= escape($batchDetail['sku'] ?? ('#' . $batchDetail['id'])) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($detailError !== null): ?>
        <p class="form-error" role="alert"><?= $detailError ?></p>
    <?php elseif ($batchDetailLoadError !== null): ?>
        <p class="form-error" role="alert"><?= $batchDetailLoadError ?></p>
    <?php elseif (isset($_GET['saved']) && $batchDetail !== null): ?>
        <p class="settings-saved" role="status"><?= t('Wijzigingen opgeslagen.', 'Changes saved.') ?></p>
    <?php elseif (isset($_GET['deleted'])): ?>
        <p class="settings-saved" role="status"><?= $isPieceDetail ? t('Stuk leer verwijderd.', 'Leather piece deleted.') : t('Batch verwijderd.', 'Batch deleted.') ?></p>
    <?php endif; ?>

    <?php if ($batchDetail === null): ?>
        <section class="settings-panel batch-not-found">
            <h2><?= t('Materiaal niet gevonden', 'Material not found') ?></h2>
            <p><?= t('Dit materiaal bestaat niet of kon niet worden geladen.', 'This material does not exist or could not be loaded.') ?></p>
            <a class="button button-secondary" href="?page=inventory&amp;view=<?= $detailInventoryView ?>"><?= t('Terug naar inventaris', 'Back to inventory') ?></a>
        </section>
    <?php else: ?>
        <?php $shareResourceType = $isPieceDetail ? 'piece' : 'batch'; $shareResourceId = (int) $batchDetail['id']; require __DIR__ . '/share-controls.php'; ?>
        <?php if ($inventoryChangeHistory !== []): ?>
            <section class="change-history" aria-labelledby="inventory-history-title">
                <h2 id="inventory-history-title"><?= t('Wat is gewijzigd', 'What changed') ?></h2>
                <?php foreach ($inventoryChangeHistory as $entry): ?>
                    <article class="change-history-entry">
                        <p class="change-history-meta"><strong><?= escape($entry['actor_name']) ?></strong><time datetime="<?= escape(date(DATE_ATOM, strtotime($entry['created_at']))) ?>"><?= escape(date('d M Y, H:i', strtotime($entry['created_at']))) ?></time></p>
                        <dl>
                            <?php foreach ($entry['changes'] as $field => $change): ?>
                                <div><dt><?= $inventoryChangeLabels[$field] ?? escape((string) $field) ?></dt><dd><span class="change-before"><?= $formatChangeValue($change['before'] ?? null) ?></span><span class="change-arrow" aria-hidden="true">&rarr;</span><span class="change-after"><?= $formatChangeValue($change['after'] ?? null) ?></span></dd></div>
                            <?php endforeach; ?>
                        </dl>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
        <form class="batch-edit-form" action="?page=<?= $detailRoute ?>&amp;id=<?= (int) $batchDetail['id'] ?>" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update">

            <div class="batch-edit-fields">
                <section class="settings-panel edit-panel" aria-labelledby="edit-material-title">
                    <h2 id="edit-material-title"><?= t('Materiaalgegevens', 'Material details') ?></h2>
                    <label for="material-name"><?= t('Materiaalnaam', 'Material name') ?> *</label>
                    <input id="material-name" name="material_name" type="text" maxlength="150" required value="<?= escape($batchDetail['material_name']) ?>">
                    <label for="sku"><?= $isPieceDetail ? 'SKU' : 'SKU / Batchnummer' ?></label>
                    <input id="sku" name="sku" type="text" maxlength="64" value="<?= escape($batchDetail['sku'] ?? '') ?>">
                    <div class="edit-field-pair">
                        <div>
                            <label for="grade">Grade *</label>
                            <select id="grade" name="grade" required>
                                <?php foreach (['A', 'B', 'C', 'snippers'] as $grade): ?>
                                    <option value="<?= escape($grade) ?>"<?= $batchDetail['grade'] === $grade ? ' selected' : '' ?>><?= escape(ucfirst($grade)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="color"><?= t('Kleur', 'Color') ?></label>
                            <input id="color" name="color" type="text" maxlength="80" value="<?= escape($batchDetail['color'] ?? '') ?>">
                        </div>
                    </div>
                    <label for="thickness"><?= t('Dikte (mm)', 'Thickness (mm)') ?></label>
                    <input id="thickness" name="thickness" type="text" maxlength="40" value="<?= escape($batchDetail['thickness'] ?? '') ?>">
                    <label for="origin"><?= t('Herkomst / Beschrijving', 'Origin / Description') ?></label>
                    <textarea id="origin" name="origin" rows="4"><?= escape($batchDetail['origin'] ?? '') ?></textarea>
                </section>

                <section class="settings-panel edit-panel" aria-labelledby="edit-stock-title">
                    <h2 id="edit-stock-title"><?= t('Prijs & voorraad', 'Pricing & stock') ?></h2>
                    <div class="edit-field-pair">
                        <div>
                            <label for="sale-price"><?= $isPieceDetail ? t('Verkoopprijs per stuk (€)', 'Sale price per piece (€)') : t('Verkoopprijs per kg (€)', 'Sale price per kg (€)') ?> *</label>
                            <input id="sale-price" name="sale_price" type="number" min="0" step="0.01" required value="<?= escape((string) $batchDetail['sale_price']) ?>">
                        </div>
                        <div>
                            <label for="cost-price"><?= $isPieceDetail ? t('Inkoopkosten per stuk (€)', 'Cost per piece (€)') : t('Inkoopkosten per kg (€)', 'Cost per kg (€)') ?> *</label>
                            <input id="cost-price" name="cost_price" type="number" min="0" step="0.01" required value="<?= escape((string) $batchDetail['cost_price']) ?>">
                        </div>
                    </div>
                    <div class="edit-field-pair">
                        <div>
                            <label for="unit"><?= t('Eenheid', 'Unit') ?> *</label>
                            <select id="unit" name="unit" required>
                                <option value="<?= $detailUnit ?>"><?= $isPieceDetail ? t('Stuk', 'Piece') : 'Kilogram (kg)' ?></option>
                            </select>
                        </div>
                        <div>
                            <label for="stock"><?= t('Voorraad', 'Stock') ?> *</label>
                            <input id="stock" name="stock" type="number" min="0" step="0.01" required value="<?= escape((string) $batchDetail['stock']) ?>">
                        </div>
                    </div>
                    <label for="minimum-stock"><?= t('Minimale voorraad (melding)', 'Minimum stock alert') ?></label>
                    <input id="minimum-stock" name="minimum_stock" type="number" min="0" step="0.01" value="<?= escape((string) $batchDetail['minimum_stock']) ?>">
                    <label for="supplier"><?= t('Bron / leverancier', 'Source / supplier') ?> *</label>
                    <input id="supplier" name="supplier" type="text" maxlength="150" required value="<?= escape($batchDetail['supplier']) ?>">
                    <label for="arrival-date"><?= t('Inzamedatum', 'Collection date') ?></label>
                    <input id="arrival-date" name="arrival_date" type="date" value="<?= escape($batchDetail['arrival_date'] ?? '') ?>">
                </section>

                <section class="settings-panel edit-panel edit-photos" aria-labelledby="edit-photos-title">
                    <h2 id="edit-photos-title"><?= t('Foto\'s & documentatie', 'Photos & documentation') ?></h2>
                    <div class="edit-field-pair">
                        <div>
                            <label for="batch-photo"><?= t('Materiaalfoto', 'Material photo') ?></label>
                            <?php if (!empty($batchDetail['batch_photo_path'])): ?>
                                <a class="current-photo" href="<?= escape($batchDetail['batch_photo_path']) ?>" target="_blank" rel="noopener">
                                    <img src="<?= escape($batchDetail['batch_photo_path']) ?>" alt="<?= t('Huidige batchfoto', 'Current batch photo') ?>">
                                    <span><?= t('Huidige foto bekijken', 'View current photo') ?></span>
                                </a>
                            <?php endif; ?>
                            <input id="batch-photo" name="batch_photo" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                        </div>
                        <div>
                            <label for="inspection-photo"><?= t('Kwaliteitsinspectiefoto', 'Quality inspection photo') ?></label>
                            <?php if (!empty($batchDetail['inspection_photo_path'])): ?>
                                <a class="current-photo" href="<?= escape($batchDetail['inspection_photo_path']) ?>" target="_blank" rel="noopener">
                                    <img src="<?= escape($batchDetail['inspection_photo_path']) ?>" alt="<?= t('Huidige inspectiefoto', 'Current inspection photo') ?>">
                                    <span><?= t('Huidige foto bekijken', 'View current photo') ?></span>
                                </a>
                            <?php endif; ?>
                            <input id="inspection-photo" name="inspection_photo" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                        </div>
                    </div>
                    <p class="settings-note"><?= t('Maximaal 5 MB per foto. Een nieuwe foto vervangt de huidige.', 'Maximum 5 MB per photo. A new photo replaces the current one.') ?></p>
                </section>
            </div>

            <div class="batch-detail-actions">
                <a class="button button-secondary" href="?page=inventory&amp;view=<?= $detailInventoryView ?>"><?= t('Terug naar inventaris', 'Back to inventory') ?></a>
                <button class="button button-primary" type="submit"><?= t('Wijzigingen opslaan', 'Save changes') ?></button>
            </div>
        </form>

        <form class="delete-batch-form" action="?page=<?= $detailRoute ?>&amp;id=<?= (int) $batchDetail['id'] ?>" method="post" onsubmit="return confirm('<?= t('Weet je zeker dat je dit materiaal permanent wilt verwijderen?', 'Are you sure you want to permanently delete this material?') ?>')">
            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="delete">
            <button class="button button-danger" type="submit"><?= $isPieceDetail ? t('Stuk leer verwijderen', 'Delete leather piece') : t('Batch verwijderen', 'Delete batch') ?></button>
        </form>
    <?php endif; ?>
</section>
