<?php
/*
 * Validates and creates an inventory record from the Add batch form.
 * The selected unit determines storage: kilograms in batches, pieces in individual_pieces.
 * Database changes are transactional; newly uploaded photos are removed when a save fails.
 */

if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

$batchError = null;
$postedToken = $_POST['csrf_token'] ?? '';
if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
    $batchError = t('Het formulier is verlopen. Vernieuw de pagina en probeer opnieuw.', 'The form expired. Refresh the page and try again.');
    return;
}

$getValue = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
};

$sku = $getValue('sku');
$materialName = $getValue('material_name');
$grade = $getValue('grade');
$color = $getValue('color');
$thickness = $getValue('thickness');
$salePrice = $getValue('sale_price');
$costPrice = $getValue('cost_price');
$unit = $getValue('unit');
$stock = $getValue('stock');
$minimumStock = $getValue('minimum_stock');
$origin = $getValue('origin');
$supplier = $getValue('supplier');
$arrivalDate = $getValue('arrival_date');

// Accept bounded decimal amounts and real calendar dates before opening a transaction.
$validAmount = static fn (string $value): bool => preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value) === 1;
$validLengths = mb_strlen($sku) <= 64
    && mb_strlen($materialName) <= 150
    && mb_strlen($color) <= 80
    && mb_strlen($thickness) <= 40
    && mb_strlen($supplier) <= 150;
$validDate = $arrivalDate === '';
if ($arrivalDate !== '') {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $arrivalDate);
    $validDate = $parsedDate !== false && $parsedDate->format('Y-m-d') === $arrivalDate;
}

if (
    $materialName === ''
    || $supplier === ''
    || !in_array($grade, ['A', 'B', 'C', 'snippers'], true)
    || !in_array($unit, ['kg', 'piece'], true)
    || !$validAmount($salePrice)
    || !$validAmount($costPrice)
    || !$validAmount($stock)
    || ($minimumStock !== '' && !$validAmount($minimumStock))
    || !$validLengths
    || !$validDate
) {
    $batchError = t('Controleer de verplichte velden, getallen en datums.', 'Check the required fields, numbers, and dates.');
    return;
}

$minimumStock = $minimumStock === '' ? '0' : $minimumStock;
// The unit-to-table choice is fixed in code, not supplied as a SQL identifier by the form.
$storageTable = $unit === 'piece' ? 'individual_pieces' : 'batches';
$sku = $sku === '' ? null : $sku;
$color = $color === '' ? null : $color;
$thickness = $thickness === '' ? null : $thickness;
$origin = $origin === '' ? null : $origin;
$arrivalDate = $arrivalDate === '' ? null : $arrivalDate;
$storedUploads = [];
$transactionOpen = false;

$cleanupUploads = static function () use (&$storedUploads): void {
    foreach ($storedUploads as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
};

// Check upload status, size and actual MIME type, then store images under random filenames.
$saveUpload = static function (string $fieldName) use (&$storedUploads): ?string {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }

    $file = $_FILES[$fieldName];
    $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($uploadError !== UPLOAD_ERR_OK || !isset($file['tmp_name'], $file['size']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('upload_failed');
    }
    if ((int) $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('file_too_large');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
        throw new RuntimeException('invalid_image');
    }

    $uploadDirectory = dirname(__DIR__) . '/uploads/batches';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('upload_failed');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    $destination = $uploadDirectory . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('upload_failed');
    }

    $storedUploads[] = $destination;
    return 'uploads/batches/' . $filename;
};

try {
    $batchPhotoPath = $saveUpload('batch_photo');
    $inspectionPhotoPath = $saveUpload('inspection_photo');

    require __DIR__ . '/../db.php';
    require_once __DIR__ . '/../lib/notifications.php';
    // Photos cannot roll back with SQL; track new files separately for cleanup on failure.
    $mysqli->begin_transaction();
    $transactionOpen = true;
    $createdByUserId = (int) ($_SESSION['user_id'] ?? 0);
    $createdByName = (string) ($_SESSION['full_name'] ?? 'Unknown');

    $statement = $mysqli->prepare(
        "INSERT INTO {$storageTable} (
            sku, material_name, grade, color, thickness, sale_price, cost_price,
            unit, created_by_user_id, created_by_name, stock, minimum_stock, origin, supplier, arrival_date,
            batch_photo_path, inspection_photo_path
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $statement->bind_param(
        'ssssssssissssssss',
        $sku,
        $materialName,
        $grade,
        $color,
        $thickness,
        $salePrice,
        $costPrice,
        $unit,
        $createdByUserId,
        $createdByName,
        $stock,
        $minimumStock,
        $origin,
        $supplier,
        $arrivalDate,
        $batchPhotoPath,
        $inspectionPhotoPath
    );
    $statement->execute();
    $batchId = $mysqli->insert_id;

    if ($sku === null) {
        $generatedSku = ($unit === 'piece' ? 'CL-P-' : 'CL-') . str_pad((string) $batchId, 6, '0', STR_PAD_LEFT);
        $updateSku = $mysqli->prepare("UPDATE {$storageTable} SET sku = ? WHERE id = ?");
        $updateSku->bind_param('si', $generatedSku, $batchId);
        $updateSku->execute();
        $sku = $generatedSku;
    }

    createInventoryActivityNotifications(
        $mysqli,
        'created',
        $materialName,
        $unit === 'piece' ? 'piece' : 'batch',
        (int) $batchId,
        (int) ($_SESSION['user_id'] ?? 0),
        (string) ($_SESSION['full_name'] ?? 'Unknown'),
        null,
        (string) $sku
    );

    if ((float) $stock <= (float) $minimumStock) {
        createLowStockNotifications(
            $mysqli,
            $materialName,
            $unit === 'piece' ? 'piece' : 'batch',
            (int) $batchId,
            (float) $stock,
            (float) $minimumStock,
            $unit,
            (string) ($_SESSION['full_name'] ?? 'System')
        );
    }

    $mysqli->commit();
    $transactionOpen = false;
    $destination = isset($_POST['save_and_new']) ? '?page=batch&saved=1' : '?page=inventory&view=' . ($unit === 'piece' ? 'pieces' : 'batches') . '&saved=1';
    header('Location: ' . $destination);
    exit;
} catch (mysqli_sql_exception $exception) {
    if ($transactionOpen && isset($mysqli)) {
        $mysqli->rollback();
    }
    $cleanupUploads();
    error_log('Batch save failed: ' . $exception->getMessage());
    $batchError = $exception->getCode() === 1062
        ? t('Deze SKU bestaat al. Vul een andere SKU in.', 'That SKU already exists. Enter a different SKU.')
        : t('Opslaan is mislukt. Controleer de databaseverbinding en probeer opnieuw.', 'The save failed. Check the database connection and try again.');
} catch (RuntimeException $exception) {
    if ($transactionOpen && isset($mysqli)) {
        $mysqli->rollback();
    }
    $cleanupUploads();
    $batchError = match ($exception->getMessage()) {
        'file_too_large' => t('Een foto is groter dan 5 MB.', 'A photo is larger than 5 MB.'),
        'invalid_image' => t('Upload een JPG-, PNG-, WebP- of GIF-afbeelding.', 'Upload a JPG, PNG, WebP, or GIF image.'),
        default => t('Een foto kon niet worden opgeslagen. Probeer het opnieuw.', 'A photo could not be saved. Please try again.'),
    };
} catch (Throwable $exception) {
    if ($transactionOpen && isset($mysqli)) {
        $mysqli->rollback();
    }
    $cleanupUploads();
    error_log('Batch save failed: ' . $exception->getMessage());
    $batchError = t('Opslaan is mislukt. Probeer het opnieuw.', 'The save failed. Please try again.');
}
