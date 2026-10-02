<?php
/*
 * Updates or deletes the record selected by the batch or piece detail route.
 * The router supplies an allowed table and fixed unit; edits cannot move a record between tables.
 * Photo replacement and notification history follow the outcome of the database save.
 */

if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

if ($batchDetailId < 1) {
    $detailError = t('Ongeldig batchnummer.', 'Invalid batch ID.');
    return;
}

$postedToken = $_POST['csrf_token'] ?? '';
if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
    $detailError = t('Het formulier is verlopen. Vernieuw de pagina en probeer opnieuw.', 'The form expired. Refresh the page and try again.');
    return;
}

$action = $_POST['action'] ?? '';
if (!is_string($action) || !in_array($action, ['update', 'delete'], true)) {
    $detailError = t('Ongeldige actie.', 'Invalid action.');
    return;
}

$storedUploads = [];
$transactionOpen = false;
$cleanupUploads = static function () use (&$storedUploads): void {
    foreach ($storedUploads as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
};

// Resolve the path inside the upload directory before removing an old photo.
$removeStoredPhoto = static function (?string $relativePath): void {
    if (!is_string($relativePath) || !str_starts_with($relativePath, 'uploads/batches/')) {
        return;
    }

    $uploadDirectory = realpath(dirname(__DIR__) . '/uploads/batches');
    $photoPath = realpath(dirname(__DIR__) . '/' . $relativePath);
    if (
        $uploadDirectory !== false
        && $photoPath !== false
        && str_starts_with($photoPath, $uploadDirectory . DIRECTORY_SEPARATOR)
        && is_file($photoPath)
    ) {
        unlink($photoPath);
    }
};

$getValue = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
};

try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../lib/notifications.php';
    $findBatch = $mysqli->prepare("SELECT * FROM {$detailTable} WHERE id = ?");
    $findBatch->bind_param('i', $batchDetailId);
    $findBatch->execute();
    $existingBatch = $findBatch->get_result()->fetch_assoc();
    if (!$existingBatch) {
        $detailError = t('Deze batch bestaat niet meer.', 'This batch no longer exists.');
        return;
    }

    // Foreign keys refuse deletion while an order still references this inventory record.
    if ($action === 'delete') {
        $deleteBatch = $mysqli->prepare("DELETE FROM {$detailTable} WHERE id = ?");
        $deleteBatch->bind_param('i', $batchDetailId);
        $deleteBatch->execute();
        if ($deleteBatch->affected_rows !== 1) {
            $detailError = t('Deze batch bestaat niet meer.', 'This batch no longer exists.');
            return;
        }

        createInventoryActivityNotifications(
            $mysqli,
            'deleted',
            (string) $existingBatch['material_name'],
            $isPieceDetail ? 'piece' : 'batch',
            $batchDetailId,
            (int) ($_SESSION['user_id'] ?? 0),
            (string) ($_SESSION['full_name'] ?? 'Unknown'),
            null,
            (string) ($existingBatch['sku'] ?? '')
        );
        $removeStoredPhoto($existingBatch['batch_photo_path'] ?? null);
        $removeStoredPhoto($existingBatch['inspection_photo_path'] ?? null);
        header('Location: ?page=inventory&view=' . $detailInventoryView . '&deleted=1');
        exit;
    }

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
        || $unit !== $detailUnit
        || !$validAmount($salePrice)
        || !$validAmount($costPrice)
        || !$validAmount($stock)
        || ($minimumStock !== '' && !$validAmount($minimumStock))
        || !$validLengths
        || !$validDate
    ) {
        $detailError = t('Controleer de verplichte velden, getallen en datums.', 'Check the required fields, numbers, and dates.');
        return;
    }

    $sku = $sku === '' ? ($detailUnit === 'piece' ? 'CL-P-' : 'CL-') . str_pad((string) $batchDetailId, 6, '0', STR_PAD_LEFT) : $sku;
    $color = $color === '' ? null : $color;
    $thickness = $thickness === '' ? null : $thickness;
    $minimumStock = $minimumStock === '' ? '0' : $minimumStock;
    $origin = $origin === '' ? null : $origin;
    $arrivalDate = $arrivalDate === '' ? null : $arrivalDate;
    // Normalize numeric values before comparison so formatting alone does not create change history.
    $changePairs = [
        'sku' => [$existingBatch['sku'] ?? null, $sku],
        'material_name' => [$existingBatch['material_name'] ?? null, $materialName],
        'grade' => [$existingBatch['grade'] ?? null, $grade],
        'color' => [$existingBatch['color'] ?? null, $color],
        'thickness' => [$existingBatch['thickness'] ?? null, $thickness],
        'sale_price' => [$existingBatch['sale_price'] ?? null, $salePrice],
        'cost_price' => [$existingBatch['cost_price'] ?? null, $costPrice],
        'unit' => [$existingBatch['unit'] ?? null, $unit],
        'stock' => [$existingBatch['stock'] ?? null, $stock],
        'minimum_stock' => [$existingBatch['minimum_stock'] ?? null, $minimumStock],
        'origin' => [$existingBatch['origin'] ?? null, $origin],
        'supplier' => [$existingBatch['supplier'] ?? null, $supplier],
        'arrival_date' => [$existingBatch['arrival_date'] ?? null, $arrivalDate],
    ];
    $numericChangeFields = ['sale_price', 'cost_price', 'stock', 'minimum_stock'];
    $changes = [];
    foreach ($changePairs as $field => [$before, $after]) {
        if (in_array($field, $numericChangeFields, true)) {
            $before = $before === null || $before === '' ? null : number_format((float) $before, 2, '.', '');
            $after = $after === null || $after === '' ? null : number_format((float) $after, 2, '.', '');
        } else {
            $before = $before === null || $before === '' ? null : (string) $before;
            $after = $after === null || $after === '' ? null : (string) $after;
        }
        if ($before !== $after) {
            $changes[$field] = ['before' => $before, 'after' => $after];
        }
    }
    $batchPhotoPath = $existingBatch['batch_photo_path'];
    $inspectionPhotoPath = $existingBatch['inspection_photo_path'];

    // Retain existing photos unless a validated replacement upload is provided.
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

    $newBatchPhotoPath = $saveUpload('batch_photo');
    $newInspectionPhotoPath = $saveUpload('inspection_photo');
    if ($newBatchPhotoPath !== null) {
        $batchPhotoPath = $newBatchPhotoPath;
    }
    if ($newInspectionPhotoPath !== null) {
        $inspectionPhotoPath = $newInspectionPhotoPath;
    }

    $mysqli->begin_transaction();
    $transactionOpen = true;
    $updateBatch = $mysqli->prepare(
        "UPDATE {$detailTable} SET
            sku = ?, material_name = ?, grade = ?, color = ?, thickness = ?,
            sale_price = ?, cost_price = ?, unit = ?, stock = ?, minimum_stock = ?,
            origin = ?, supplier = ?, arrival_date = ?, batch_photo_path = ?, inspection_photo_path = ?
         WHERE id = ?"
    );
    $updateBatch->bind_param(
        'sssssssssssssssi',
        $sku,
        $materialName,
        $grade,
        $color,
        $thickness,
        $salePrice,
        $costPrice,
        $unit,
        $stock,
        $minimumStock,
        $origin,
        $supplier,
        $arrivalDate,
        $batchPhotoPath,
        $inspectionPhotoPath,
        $batchDetailId
    );
    $updateBatch->execute();
    createInventoryActivityNotifications(
        $mysqli,
        'updated',
        $materialName,
        $isPieceDetail ? 'piece' : 'batch',
        $batchDetailId,
        (int) ($_SESSION['user_id'] ?? 0),
        (string) ($_SESSION['full_name'] ?? 'Unknown'),
            $changes,
            (string) $sku
    );
    $wasLowStock = (float) $existingBatch['stock'] <= (float) $existingBatch['minimum_stock'];
    $isLowStock = (float) $stock <= (float) $minimumStock;
    if (!$wasLowStock && $isLowStock) {
        createLowStockNotifications(
            $mysqli,
            $materialName,
            $isPieceDetail ? 'piece' : 'batch',
            $batchDetailId,
            (float) $stock,
            (float) $minimumStock,
            $unit,
            (string) ($_SESSION['full_name'] ?? 'System')
        );
    }
    $mysqli->commit();
    $transactionOpen = false;

    // Delete replaced photos only after the database commit has succeeded.
    if ($newBatchPhotoPath !== null) {
        $removeStoredPhoto($existingBatch['batch_photo_path'] ?? null);
    }
    if ($newInspectionPhotoPath !== null) {
        $removeStoredPhoto($existingBatch['inspection_photo_path'] ?? null);
    }

    header('Location: ?page=' . $detailRoute . '&id=' . $batchDetailId . '&saved=1');
    exit;
} catch (mysqli_sql_exception $exception) {
    if ($transactionOpen && isset($mysqli)) {
        $mysqli->rollback();
    }
    $cleanupUploads();
    error_log('Batch update failed: ' . $exception->getMessage());
    $detailError = match ($exception->getCode()) {
        1062 => t('Deze SKU bestaat al. Vul een andere SKU in.', 'That SKU already exists. Enter a different SKU.'),
        1451 => t('Dit materiaal wordt gebruikt in een order en kan niet worden verwijderd.', 'This material is used in an order and cannot be deleted.'),
        default => t('Opslaan is mislukt. Probeer het opnieuw.', 'The save failed. Please try again.'),
    };
} catch (RuntimeException $exception) {
    if ($transactionOpen && isset($mysqli)) {
        $mysqli->rollback();
    }
    $cleanupUploads();
    $detailError = match ($exception->getMessage()) {
        'file_too_large' => t('Een foto is groter dan 5 MB.', 'A photo is larger than 5 MB.'),
        'invalid_image' => t('Upload een JPG-, PNG-, WebP- of GIF-afbeelding.', 'Upload a JPG, PNG, WebP, or GIF image.'),
        default => t('Een foto kon niet worden opgeslagen. Probeer het opnieuw.', 'A photo could not be saved. Please try again.'),
    };
} catch (Throwable $exception) {
    if ($transactionOpen && isset($mysqli)) {
        $mysqli->rollback();
    }
    $cleanupUploads();
    error_log('Batch update failed: ' . $exception->getMessage());
    $detailError = t('Opslaan is mislukt. Probeer het opnieuw.', 'The save failed. Please try again.');
}
