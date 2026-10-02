<?php
/*
 * Reuses the batch detail template for individual leather pieces.
 * index.php has already selected individual_pieces, the piece unit and the piece detail route.
 */

if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/batch-detail.php';
