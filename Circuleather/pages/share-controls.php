<?php
/*
 * Builds the PNG export controls and an off-screen card for the current detail page.
 * The card contains a stable snapshot for html2canvas rather than the editable form.
 * This authenticated export can include contact details and photos; share.php is a separate public view.
 */

if (!defined('CIRCULEATHER_APP') || !isset($shareResourceType, $shareResourceId)) {
    http_response_code(404);
    exit;
}

$shareExportTitle = '';
$shareExportSubtitle = '';
$shareExportFacts = [];
$shareExportRows = [];
$shareExportImages = [];
$orderResource = null;

try {
    require_once __DIR__ . '/../db.php';

    if ($shareResourceType === 'order') {
        $orderShare = $mysqli->prepare(
            'SELECT o.id, o.order_number, o.status, o.payment_status, o.total_amount, o.created_at,
                    c.full_name, c.email, c.phone, c.street_address, c.postal_code, c.city
             FROM orders o
             INNER JOIN clients c ON c.id = o.client_id
             WHERE o.id = ? LIMIT 1'
        );
        $orderShare->bind_param('i', $shareResourceId);
        $orderShare->execute();
        $orderResource = $orderShare->get_result()->fetch_assoc();

        if ($orderResource) {
            $shareExportTitle = $orderResource['order_number'];
            $shareExportSubtitle = t('Bestelling', 'Order');
            $shareExportFacts = [
                [t('Datum', 'Date'), date('d M Y', strtotime($orderResource['created_at']))],
                [t('Status', 'Status'), $orderResource['status'] === 'shipped' ? t('Verzonden', 'Shipped') : ($orderResource['status'] === 'delivered' ? t('Afgeleverd', 'Delivered') : ($orderResource['status'] === 'processing' ? t('In verwerking', 'Processing') : ($orderResource['status'] === 'cancelled' ? t('Geannuleerd', 'Cancelled') : t('Open', 'Open'))))],
                [t('Betaling', 'Payment'), $orderResource['payment_status'] === 'paid' ? t('Betaald', 'Paid') : t('Openstaand', 'Unpaid')],
                [t('Klant', 'Customer'), (string) ($orderResource['full_name'] ?? '')],
                [t('E-mail', 'Email'), (string) ($orderResource['email'] ?? '')],
                [t('Telefoon', 'Phone'), (string) ($orderResource['phone'] ?? '')],
                [t('Adres', 'Address'), trim((string) ($orderResource['street_address'] ?? '') . ' ' . ($orderResource['postal_code'] ?? '') . ' ' . ($orderResource['city'] ?? ''))],
            ];

            $rows = $mysqli->prepare(
                'SELECT sku, material_name, grade, color, thickness, quantity, unit, unit_price, line_total FROM order_items WHERE order_id = ? ORDER BY id'
            );
            $rows->bind_param('i', $shareResourceId);
            $rows->execute();
            $shareExportRows = $rows->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    } else {
        $tableForType = ['batch' => 'batches', 'piece' => 'individual_pieces'];
        $resourceTable = $tableForType[$shareResourceType] ?? null;
        if ($resourceTable !== null) {
            $resourceStatement = $mysqli->prepare(
                "SELECT * FROM {$resourceTable} WHERE id = ? LIMIT 1"
            );
            $resourceStatement->bind_param('i', $shareResourceId);
            $resourceStatement->execute();
            $resource = $resourceStatement->get_result()->fetch_assoc();

            if ($resource) {
                $shareExportTitle = $resource['material_name'];
                $shareExportSubtitle = $shareResourceType === 'piece' ? t('Leerstuk', 'Leather piece') : t('Batch', 'Batch');
                $shareExportFacts = [
                    ['SKU', $resource['sku'] ?? '—'],
                    ['Grade', $resource['grade'] ?? '—'],
                    [t('Kleur', 'Color'), (string) ($resource['color'] ?? '') ?: '—'],
                    [t('Dikte', 'Thickness'), (string) ($resource['thickness'] ?? '') ?: '—'],
                    [t('Voorraad', 'Stock'), number_format((float) $resource['stock'], 2, ',', '.') . ' ' . $resource['unit']],
                    [t('Verkoopprijs', 'Sale price'), '€ ' . number_format((float) $resource['sale_price'], 2, ',', '.') . ' / ' . $resource['unit']],
                    [t('Inkoopprijs', 'Cost price'), '€ ' . number_format((float) $resource['cost_price'], 2, ',', '.') . ' / ' . $resource['unit']],
                    [t('Leverancier', 'Supplier'), (string) ($resource['supplier'] ?? '') ?: '—'],
                    [t('Herkomst', 'Origin'), (string) ($resource['origin'] ?? '') ?: '—'],
                    [t('Inzamedatum', 'Arrival date'), $resource['arrival_date'] ? date('d M Y', strtotime($resource['arrival_date'])) : '—'],
                ];

                if (!empty($resource['batch_photo_path'])) {
                    $shareExportImages[] = ['label' => t('Materiaalfoto', 'Material photo'), 'src' => $resource['batch_photo_path']];
                }
                if (!empty($resource['inspection_photo_path'])) {
                    $shareExportImages[] = ['label' => t('Kwaliteitsinspectiefoto', 'Inspection photo'), 'src' => $resource['inspection_photo_path']];
                }
            }
        }
    }
} catch (Throwable $exception) {
    error_log('Could not prepare PNG export data: ' . $exception->getMessage());
}

$formatNumber = static fn (float $value): string => number_format($value, 2, ',', '.');

// Build the hidden capture card after loading export data; share-links.js clones it for rendering.
$shareExportHtml = '<div class="public-share-body" data-share-export-card style="position:fixed;left:-9999px;top:0;visibility:hidden;width:920px;pointer-events:none;z-index:-1;">
    <header class="public-share-header"><a class="brand" href="#"><span class="brand-mark">CL</span><span><strong>CIRCULEATHER</strong><small>' . t('Gedeelde informatie', 'Shared information') . '</small></span></a></header>
    <main class="public-share-main">
        <section class="public-share-content">
            <p class="eyebrow">' . $shareExportSubtitle . '</p>
            <h1>' . escape((string) $shareExportTitle) . '</h1>';

if ($shareResourceType === 'order') {
    $shareExportHtml .= '<dl class="public-share-facts">';
    foreach ($shareExportFacts as [$label, $value]) {
        $shareExportHtml .= '<div><dt>' . escape((string) $label) . '</dt><dd>' . escape((string) $value) . '</dd></div>';
    }
    $shareExportHtml .= '</dl>';
    $shareExportHtml .= '<div class="table-wrap public-share-table"><table><thead><tr><th>' . t('Artikel', 'Item') . '</th><th>' . t('Hoeveelheid', 'Quantity') . '</th><th>' . t('Prijs', 'Price') . '</th><th>' . t('Totaal', 'Total') . '</th></tr></thead><tbody>';
    foreach ($shareExportRows as $item) {
        $shareExportHtml .= '<tr><td><strong>' . escape((string) ($item['material_name'] ?? '')) . '</strong><small>' . escape((string) (($item['sku'] ?? '') . ' · ' . ($item['grade'] ?? '') . (!empty($item['color']) ? ' · ' . $item['color'] : '') . (!empty($item['thickness']) ? ' · ' . $item['thickness'] : ''))) . '</small></td><td>' . escape((string) $formatNumber((float) ($item['quantity'] ?? 0))) . ' ' . escape((string) ($item['unit'] ?? '')) . '</td><td>€ ' . escape((string) $formatNumber((float) ($item['unit_price'] ?? 0))) . '</td><td>€ ' . escape((string) $formatNumber((float) ($item['line_total'] ?? 0))) . '</td></tr>';
    }
    $shareExportHtml .= '</tbody></table></div>';
    $shareExportHtml .= '<p class="public-share-total"><span>' . t('Bestellingstotaal', 'Order total') . '</span><strong>€ ' . escape((string) $formatNumber((float) ($orderResource['total_amount'] ?? 0))) . '</strong></p>';
} else {
    $shareExportHtml .= '<dl class="public-share-facts">';
    foreach ($shareExportFacts as [$label, $value]) {
        $shareExportHtml .= '<div><dt>' . escape((string) $label) . '</dt><dd>' . escape((string) $value) . '</dd></div>';
    }
    $shareExportHtml .= '</dl>';
    if ($shareExportImages !== []) {
        $shareExportHtml .= '<div class="public-share-photo-grid">';
        foreach ($shareExportImages as $image) {
            $shareExportHtml .= '<figure class="public-share-photo-card"><img src="' . escape((string) $image['src']) . '" alt="' . escape((string) ($image['label'] ?? '')) . '"><figcaption>' . escape((string) ($image['label'] ?? '')) . '</figcaption></figure>';
        }
        $shareExportHtml .= '</div>';
    }
}

$shareExportHtml .= '</section></main><footer class="public-share-footer">CIRCULEATHER</footer></div>';
?>
<section class="share-controls" data-share-controls data-export-label="<?= t('PNG-export gemaakt.', 'PNG export created.') ?>" data-export-error-label="<?= t('PNG-export kon niet worden gemaakt.', 'PNG export could not be created.') ?>" data-export-generating-label="<?= t('PNG wordt gemaakt...', 'Creating PNG...') ?>">
    <div class="share-controls-heading">
        <div><p class="eyebrow"><?= t('Delen', 'Sharing') ?></p><h2><?= t('Als PNG exporteren', 'Export as PNG') ?></h2></div>
    </div>
    <p class="share-description"><?= $shareResourceType === 'order'
        ? t('Sla deze order als een statische PNG op zodat je deze eenvoudig kunt delen of opslaan.', 'Save this order as a static PNG so it can be easily shared or stored.')
        : t('Sla deze batch als een statische PNG op zodat je deze eenvoudig kunt delen of opslaan.', 'Save this batch as a static PNG so it can be easily shared or stored.') ?></p>
    <button class="button button-primary" type="button" data-export-png><?= t('PNG downloaden', 'Download PNG') ?></button>
    <p class="share-status" data-share-status role="status" hidden></p>
    <?= $shareExportHtml ?>
</section>