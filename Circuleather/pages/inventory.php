<?php
/*
 * Inventory overview with separate views for kilogram batches and individual pieces.
 * Validates search/filter choices, loads summary totals and renders matching records.
 * Summary cards cover the selected inventory type, while the table applies the active filters.
 */

if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

$inventoryBatches = [];
$inventoryError = null;
$inventoryView = isset($_GET['view']) && $_GET['view'] === 'pieces' ? 'pieces' : 'batches';
$isPiecesView = $inventoryView === 'pieces';
$inventoryUnit = $isPiecesView ? 'piece' : 'kg';
$inventoryTable = $isPiecesView ? 'individual_pieces' : 'batches';
$stockUnitLabel = $isPiecesView ? t('stuks', 'pieces') : 'kg';
$searchQuery = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$gradeFilter = isset($_GET['grade']) && is_string($_GET['grade']) ? $_GET['grade'] : 'all';
$stockFilter = isset($_GET['stock_status']) && is_string($_GET['stock_status']) ? $_GET['stock_status'] : 'all';
if (!in_array($gradeFilter, ['all', 'A', 'B', 'C', 'snippers'], true)) {
    $gradeFilter = 'all';
}
if (!in_array($stockFilter, ['all', 'available', 'low', 'out'], true)) {
    $stockFilter = 'all';
}
$inventoryStats = [
    'batch_count' => 0,
    'total_stock' => 0,
    'low_stock_count' => 0,
    'out_of_stock_count' => 0,
    'stock_value' => 0,
];

try {
    require_once __DIR__ . '/../db.php';
    // Compute overview totals separately so searching does not change the summary cards.
    $statsQuery = $mysqli->prepare(
        "SELECT
            COUNT(*) AS batch_count,
            COALESCE(SUM(stock), 0) AS total_stock,
            COALESCE(SUM(CASE WHEN stock > 0 AND stock <= minimum_stock THEN 1 ELSE 0 END), 0) AS low_stock_count,
            COALESCE(SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_count,
            COALESCE(SUM(stock * sale_price), 0) AS stock_value
        FROM {$inventoryTable} WHERE unit = ?"
    );
    $statsQuery->bind_param('s', $inventoryUnit);
    $statsQuery->execute();
    $inventoryStats = $statsQuery->get_result()->fetch_assoc();

    $searchPattern = '%' . $searchQuery . '%';
    // Search terms and filters are parameters; the table name comes from the fixed view choice above.
    $batchQuery = $mysqli->prepare(
        "SELECT id, sku, material_name, grade, color, thickness, stock, minimum_stock,
            sale_price, unit, supplier, origin, created_by_name
         FROM {$inventoryTable}
         WHERE (material_name LIKE ? OR sku LIKE ? OR color LIKE ? OR supplier LIKE ? OR origin LIKE ?)
            AND unit = ?
            AND (? = 'all' OR grade = ?)
            AND (
                ? = 'all'
                OR (? = 'available' AND stock > minimum_stock)
                OR (? = 'low' AND stock > 0 AND stock <= minimum_stock)
                OR (? = 'out' AND stock <= 0)
            )
         ORDER BY id DESC"
    );
    $batchQuery->bind_param(
        'ssssssssssss',
        $searchPattern,
        $searchPattern,
        $searchPattern,
        $searchPattern,
        $searchPattern,
        $inventoryUnit,
        $gradeFilter,
        $gradeFilter,
        $stockFilter,
        $stockFilter,
        $stockFilter,
        $stockFilter
    );
    $batchQuery->execute();
    $inventoryBatches = $batchQuery->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $exception) {
    error_log('Could not load inventory: ' . $exception->getMessage());
    $inventoryError = t('Voorraad kon niet uit de database worden geladen.', 'Inventory could not be loaded from the database.');
}

$formatNumber = static fn ($value): string => number_format((float) $value, 2, ',', '.');
$itemLabel = $isPiecesView ? t('artikel', 'item') : t('batch', 'batch');
$itemsLabel = $isPiecesView ? t('artikelen', 'items') : t('batches', 'batches');
$stockRecordLabel = (int) $inventoryStats['batch_count'] === 1 ? $itemLabel : $itemsLabel;
$resultLabel = count($inventoryBatches) === 1 ? $itemLabel : $itemsLabel;
$viewLabel = $isPiecesView ? t('Losse stukken leer', 'Individual pieces') : t('Batches', 'Batches');
$viewActionLabel = $isPiecesView ? t('Bekijk artikel', 'View item') : t('Bekijk batch', 'View batch');
$viewUrl = static fn (string $view): string => '?' . http_build_query([
    'page' => 'inventory',
    'view' => $view,
    'q' => $searchQuery,
    'grade' => $gradeFilter,
    'stock_status' => $stockFilter,
]);
?>
<section class="page-section inventory-page" aria-labelledby="inventory-title">
    <div class="page-heading">
        <div><p class="eyebrow"><?= t('Voorraad', 'Inventory') ?></p><h1 id="inventory-title"><?= t('Leermateriaal inventaris', 'Leather inventory') ?></h1></div>
        <?php if (!$isPiecesView): ?>
            <a class="button button-primary" href="?page=batch">+ <?= t('Batch toevoegen', 'Add batch') ?></a>
        <?php endif; ?>
    </div>
    <?php if (isset($_GET['saved'])): ?>
        <p class="settings-saved" role="status"><?= $isPiecesView ? t('Stuk leer opgeslagen.', 'Leather piece saved.') : t('Batch opgeslagen.', 'Batch saved.') ?></p>
    <?php elseif (isset($_GET['deleted'])): ?>
        <p class="settings-saved" role="status"><?= $isPiecesView ? t('Stuk leer verwijderd.', 'Leather piece deleted.') : t('Batch verwijderd.', 'Batch deleted.') ?></p>
    <?php endif; ?>
    <?php if ($inventoryError !== null): ?>
        <p class="form-error" role="alert"><?= $inventoryError ?></p>
    <?php endif; ?>

    <nav class="inventory-views" aria-label="<?= t('Voorraadweergave', 'Inventory view') ?>">
        <a href="<?= escape($viewUrl('batches')) ?>"<?= !$isPiecesView ? ' aria-current="page"' : '' ?>><?= t('Batches', 'Batches') ?></a>
        <a href="<?= escape($viewUrl('pieces')) ?>"<?= $isPiecesView ? ' aria-current="page"' : '' ?>><?= t('Losse stukken leer', 'Individual pieces') ?></a>
    </nav>
    <p class="inventory-view-description"><?= $isPiecesView
        ? t('Leer dat per stuk wordt bijgehouden en verkocht.', 'Leather tracked and sold by the piece.')
        : t('Batches leer die per kilogram worden bijgehouden en verkocht.', 'Leather batches tracked and sold by the kilogram.') ?></p>

    <section class="stats-grid" aria-label="<?= t('Voorraadoverzicht', 'Inventory overview') ?>">
        <article class="stat-card"><h2><?= t('Totale voorraad', 'Total stock') ?></h2><strong><?= $formatNumber($inventoryStats['total_stock']) ?> <?= $stockUnitLabel ?></strong><p><?= (int) $inventoryStats['batch_count'] ?> <?= $stockRecordLabel ?></p></article>
        <article class="stat-card"><h2><?= t('Weinig voorraad', 'Low stock') ?></h2><strong><?= (int) $inventoryStats['low_stock_count'] ?></strong><p><?= $isPiecesView ? t('artikelen onder minimum', 'items below minimum') : t('batches onder minimum', 'batches below minimum') ?></p></article>
        <article class="stat-card stat-card-dark"><h2><?= t('Uit voorraad', 'Out of stock') ?></h2><strong><?= (int) $inventoryStats['out_of_stock_count'] ?></strong><p><?= t('direct aanvullen', 'restock now') ?></p></article>
        <article class="stat-card"><h2><?= t('Voorraadwaarde', 'Stock value') ?></h2><strong>€ <?= $formatNumber($inventoryStats['stock_value']) ?></strong><p><?= t('op basis van verkoopprijs', 'based on sale price') ?></p></article>
    </section>

    <div class="table-toolbar">
        <form class="search-form inventory-filters" method="get" action="index.php">
            <input type="hidden" name="page" value="inventory">
            <input type="hidden" name="view" value="<?= escape($inventoryView) ?>">
            <label for="inventory-search"><?= t('Zoeken op naam, SKU, kleur of herkomst', 'Search by name, SKU, color, or source') ?></label>
            <input id="inventory-search" type="search" name="q" value="<?= escape($searchQuery) ?>" placeholder="<?= t('Zoek materiaal of herkomst', 'Search materials or source') ?>">
            <label for="inventory-grade"><?= t('Filter op grade', 'Filter by grade') ?></label>
            <select id="inventory-grade" name="grade" aria-label="<?= t('Filter op grade', 'Filter by grade') ?>">
                <option value="all"<?= $gradeFilter === 'all' ? ' selected' : '' ?>><?= t('Alle grades', 'All grades') ?></option>
                <option value="A"<?= $gradeFilter === 'A' ? ' selected' : '' ?>>Grade A</option>
                <option value="B"<?= $gradeFilter === 'B' ? ' selected' : '' ?>>Grade B</option>
                <option value="C"<?= $gradeFilter === 'C' ? ' selected' : '' ?>>Grade C</option>
                <option value="snippers"<?= $gradeFilter === 'snippers' ? ' selected' : '' ?>>Snippers</option>
            </select>
            <label for="inventory-stock-status"><?= t('Filter op voorraadstatus', 'Filter by stock status') ?></label>
            <select id="inventory-stock-status" name="stock_status" aria-label="<?= t('Filter op voorraadstatus', 'Filter by stock status') ?>">
                <option value="all"<?= $stockFilter === 'all' ? ' selected' : '' ?>><?= t('Alle statussen', 'All statuses') ?></option>
                <option value="available"<?= $stockFilter === 'available' ? ' selected' : '' ?>><?= t('Op voorraad', 'In stock') ?></option>
                <option value="low"<?= $stockFilter === 'low' ? ' selected' : '' ?>><?= t('Lage voorraad', 'Low stock') ?></option>
                <option value="out"<?= $stockFilter === 'out' ? ' selected' : '' ?>><?= t('Uit voorraad', 'Out of stock') ?></option>
            </select>
            <button type="submit"><?= t('Zoeken', 'Search') ?></button>
        </form>
        <span class="result-count"><?= count($inventoryBatches) ?> <?= $resultLabel ?></span>
    </div>

    <div class="table-wrap"><table>
        <caption><?= $viewLabel ?></caption>
        <thead>
            <tr>
                <th scope="col">SKU</th>
                <th scope="col"><?= t('Materiaal', 'Material') ?></th>
                <th scope="col"><?= t('Aangemaakt door', 'Created by') ?></th>
                <th scope="col">Grade</th>
                <th scope="col"><?= t('Kleur', 'Color') ?></th>
                <th scope="col"><?= t('Dikte', 'Thickness') ?></th>
                <th scope="col"><?= t('Voorraad', 'Stock') ?></th>
                <th scope="col"><?= t('Minimum', 'Minimum') ?></th>
                <th scope="col"><?= $isPiecesView ? t('Prijs/stuk', 'Price/piece') : t('Prijs/kg', 'Price/kg') ?></th>
                <th scope="col"><?= t('Herkomst / bron', 'Origin / source') ?></th>
                <th scope="col"><?= t('Status', 'Status') ?></th>
                <th scope="col"><?= t('Actie', 'Action') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($inventoryBatches === []): ?>
                <tr>
                    <td colspan="12">
                        <?php if ($inventoryError !== null): ?>
                            <?= t('De voorraad kon niet worden geladen.', 'Inventory could not be loaded.') ?>
                        <?php elseif ($searchQuery !== '' || $gradeFilter !== 'all' || $stockFilter !== 'all'): ?>
                            <?= $isPiecesView ? t('Geen losse stukken leer gevonden.', 'No individual pieces found.') : t('Geen batches gevonden.', 'No batches found.') ?>
                        <?php elseif ($isPiecesView): ?>
                            <?= t('Er zijn nog geen losse stukken leer beschikbaar.', 'No individual pieces of leather are available yet.') ?>
                        <?php else: ?>
                            <?= t('Er zijn nog geen batches toegevoegd.', 'No batches have been added yet.') ?>
                            <a href="?page=batch"><?= t('Leermateriaal toevoegen', 'Add leather') ?></a>.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($inventoryBatches as $batch): ?>
                    <?php
                    $stockAmount = (float) $batch['stock'];
                    $minimumAmount = (float) $batch['minimum_stock'];
                    $stockStatus = $stockAmount <= 0
                        ? ['out', t('UIT', 'OUT')]
                        : ($stockAmount <= $minimumAmount ? ['low', t('LAAG', 'LOW')] : ['ok', 'OK']);
                    ?>
                    <tr>
                        <td><?= escape($batch['sku'] ?? '—') ?></td>
                        <td><?= escape($batch['material_name']) ?></td>
                        <td><?= !empty($batch['created_by_name']) ? escape($batch['created_by_name']) : t('Onbekend', 'Unknown') ?></td>
                        <td><?= escape($batch['grade']) ?></td>
                        <td><?= escape($batch['color'] ?? '—') ?></td>
                        <td><?= escape($batch['thickness'] ?? '—') ?></td>
                        <td><?= $formatNumber($batch['stock']) ?> <?= $stockUnitLabel ?></td>
                        <td><?= $formatNumber($batch['minimum_stock']) ?> <?= $stockUnitLabel ?></td>
                        <td>€ <?= $formatNumber($batch['sale_price']) ?></td>
                        <td class="batch-origin-cell"><strong><?= escape($batch['supplier']) ?></strong><?php if (!empty($batch['origin'])): ?><small><?= escape($batch['origin']) ?></small><?php endif; ?></td>
                        <td><span class="status-badge status-<?= escape($stockStatus[0]) ?>"><?= $stockStatus[1] ?></span></td>
                        <td><a class="button button-secondary button-small view-batch-button" href="?page=<?= $isPiecesView ? 'piece-detail' : 'batch-detail' ?>&amp;id=<?= (int) $batch['id'] ?>" aria-label="<?= $viewActionLabel ?>" title="<?= $viewActionLabel ?>"><span aria-hidden="true">&#128269;</span></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table></div>
</section>
