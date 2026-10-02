<?php
/*
 * Public, read-only view of a batch, piece or order identified by a secret share token.
 * Only the token hash is stored in the database; revoked or expired links return 404.
 * The public order view intentionally selects no customer contact information.
 */

declare(strict_types=1);

// Keep token-bearing pages out of caches, search indexes and outgoing referrer headers.
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

function shareEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$token = $_GET['token'] ?? '';
$share = null;
$resource = null;
$items = [];
$unavailable = false;
$resourceType = '';
$language = 'en';
$tableForType = ['batch' => 'batches', 'piece' => 'individual_pieces', 'order' => 'orders'];

if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
    http_response_code(404);
    $unavailable = true;
} else {
    try {
        require __DIR__ . '/db.php';
        $tokenHash = hash('sha256', $token);
        $findShare = $mysqli->prepare(
            'SELECT id, resource_type, resource_id, language FROM share_links
             WHERE token_hash = ? AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP) LIMIT 1'
        );
        $findShare->bind_param('s', $tokenHash);
        $findShare->execute();
        $share = $findShare->get_result()->fetch_assoc();
        if (!$share) {
            http_response_code(404);
            $unavailable = true;
        } else {
            $resourceType = $share['resource_type'];
            $language = $share['language'] === 'nl' ? 'nl' : 'en';
            $resourceTable = $tableForType[$resourceType] ?? null;
            if ($resourceTable === null) {
                http_response_code(404);
                $unavailable = true;
            } elseif ($resourceType === 'order') {
                // Select a public order summary without its customer identity or contact information.
                $findResource = $mysqli->prepare(
                    'SELECT id, order_number, status, payment_status, total_amount, created_at
                     FROM orders WHERE id = ? LIMIT 1'
                );
                $resourceId = (int) $share['resource_id'];
                $findResource->bind_param('i', $resourceId);
                $findResource->execute();
                $resource = $findResource->get_result()->fetch_assoc();
                if ($resource) {
                    $findItems = $mysqli->prepare(
                        'SELECT sku, material_name, grade, color, thickness, quantity, unit, unit_price, line_total
                         FROM order_items WHERE order_id = ? ORDER BY id'
                    );
                    $findItems->bind_param('i', $resourceId);
                    $findItems->execute();
                    $items = $findItems->get_result()->fetch_all(MYSQLI_ASSOC);
                }
            } else {
                $findResource = $mysqli->prepare(
                    "SELECT id, sku, material_name, grade, color, thickness, stock, unit, sale_price
                     FROM {$resourceTable} WHERE id = ? LIMIT 1"
                );
                $resourceId = (int) $share['resource_id'];
                $findResource->bind_param('i', $resourceId);
                $findResource->execute();
                $resource = $findResource->get_result()->fetch_assoc();
            }

            if (!$resource) {
                http_response_code(404);
                $unavailable = true;
            }
        }
    } catch (Throwable $exception) {
        error_log('Could not load shared resource: ' . $exception->getMessage());
        http_response_code(503);
        $unavailable = true;
    }
}

$t = static fn (string $dutch, string $english): string => shareEscape($language === 'nl' ? $dutch : $english);
$number = static fn ($value): string => number_format((float) $value, 2, $language === 'nl' ? ',' : '.', $language === 'nl' ? '.' : ',');
$title = $unavailable
    ? $t('Link niet beschikbaar', 'Link unavailable')
    : ($resourceType === 'order'
        ? $t('Bestelling ', 'Order ') . shareEscape($resource['order_number'])
        : shareEscape($resource['material_name']));
$statusLabels = [
    'open' => $t('Open', 'Open'),
    'processing' => $t('In verwerking', 'Processing'),
    'shipped' => $t('Verzonden', 'Shipped'),
    'delivered' => $t('Afgeleverd', 'Delivered'),
    'cancelled' => $t('Geannuleerd', 'Cancelled'),
];
?>
<!doctype html>
<html lang="<?= shareEscape($language) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?> | Circuleather</title>
    <link rel="stylesheet" href="style.css?v=<?= hash_file('sha256', __DIR__ . '/style.css') ?>">
</head>
<body data-theme="light" class="public-share-body">
    <header class="public-share-header"><a class="brand" href="#"><span class="brand-mark">CL</span><span><strong>CIRCULEATHER</strong><small><?= $t('Gedeelde informatie', 'Shared information') ?></small></span></a></header>
    <main class="public-share-main">
        <?php if ($unavailable): ?>
            <section class="public-share-content">
                <p class="eyebrow"><?= $t('Gedeelde link', 'Shared link') ?></p>
                <h1><?= $t('Deze link is niet beschikbaar', 'This link is unavailable') ?></h1>
                <p><?= $t('De link is verlopen, ingetrokken of het item bestaat niet meer.', 'The link expired, was revoked, or the item is no longer available.') ?></p>
            </section>
        <?php elseif ($resourceType === 'order'): ?>
            <section class="public-share-content">
                <p class="eyebrow"><?= $t('Bestelling', 'Order') ?></p>
                <h1><?= shareEscape($resource['order_number']) ?></h1>
                <dl class="public-share-facts">
                    <div><dt><?= $t('Datum', 'Date') ?></dt><dd><?= shareEscape(date('d M Y', strtotime($resource['created_at']))) ?></dd></div>
                    <div><dt><?= $t('Status', 'Status') ?></dt><dd><?= $statusLabels[$resource['status']] ?? $t('Open', 'Open') ?></dd></div>
                    <div><dt><?= $t('Betaling', 'Payment') ?></dt><dd><?= $resource['payment_status'] === 'paid' ? $t('Betaald', 'Paid') : $t('Openstaand', 'Unpaid') ?></dd></div>
                </dl>
                <div class="table-wrap public-share-table"><table>
                    <thead><tr><th><?= $t('Artikel', 'Item') ?></th><th><?= $t('Hoeveelheid', 'Quantity') ?></th><th><?= $t('Prijs', 'Price') ?></th><th><?= $t('Totaal', 'Total') ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><strong><?= shareEscape($item['material_name']) ?></strong><small><?= shareEscape(($item['sku'] ?? '') . ' · ' . $item['grade'] . (!empty($item['color']) ? ' · ' . $item['color'] : '') . (!empty($item['thickness']) ? ' · ' . $item['thickness'] : '')) ?></small></td>
                                <td><?= shareEscape($number($item['quantity'])) ?> <?= shareEscape($item['unit']) ?></td>
                                <td>€ <?= shareEscape($number($item['unit_price'])) ?></td>
                                <td>€ <?= shareEscape($number($item['line_total'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <p class="public-share-total"><span><?= $t('Bestellingstotaal', 'Order total') ?></span><strong>€ <?= shareEscape($number($resource['total_amount'])) ?></strong></p>
            </section>
        <?php else: ?>
            <section class="public-share-content">
                <p class="eyebrow"><?= $resourceType === 'piece' ? $t('Leerstuk', 'Leather piece') : $t('Batch', 'Batch') ?></p>
                <h1><?= shareEscape($resource['material_name']) ?></h1>
                <dl class="public-share-facts">
                    <div><dt>SKU</dt><dd><?= shareEscape($resource['sku'] ?? '—') ?></dd></div>
                    <div><dt>Grade</dt><dd><?= shareEscape($resource['grade']) ?></dd></div>
                    <?php if (!empty($resource['color'])): ?><div><dt><?= $t('Kleur', 'Color') ?></dt><dd><?= shareEscape($resource['color']) ?></dd></div><?php endif; ?>
                    <?php if (!empty($resource['thickness'])): ?><div><dt><?= $t('Dikte', 'Thickness') ?></dt><dd><?= shareEscape($resource['thickness']) ?></dd></div><?php endif; ?>
                    <div><dt><?= $t('Voorraad', 'Stock') ?></dt><dd><?= shareEscape($number($resource['stock'])) ?> <?= shareEscape($resource['unit']) ?></dd></div>
                    <div><dt><?= $t('Verkoopprijs', 'Sale price') ?></dt><dd>€ <?= shareEscape($number($resource['sale_price'])) ?> / <?= shareEscape($resource['unit']) ?></dd></div>
                </dl>
            </section>
        <?php endif; ?>
    </main>
    <footer class="public-share-footer">CIRCULEATHER</footer>
</body>
</html>