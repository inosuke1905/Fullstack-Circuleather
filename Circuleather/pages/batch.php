<?php
/*
 * Add batch form, included by index.php after any save action has been handled.
 * The Excel panel copies one selected spreadsheet row into this form; it does not save automatically.
 * Submission is validated by actions/save_batch.php, including uploaded photos.
 */

if (!defined('CIRCULEATHER_APP')) {
    http_response_code(404);
    exit;
}
?>
<section class="page-section batch-page" aria-labelledby="batch-title">
    <div class="page-heading">
        <div><p class="eyebrow"><?= t('Voorraad / Nieuwe batch', 'Inventory / New batch') ?></p><h1 id="batch-title"><?= t('Leermateriaal toevoegen', 'Add leather') ?></h1></div>
    </div>
    <?php if ($batchError !== null): ?>
        <p class="form-error" role="alert"><?= $batchError ?></p>
    <?php elseif (isset($_GET['saved'])): ?>
        <p role="status"><?= t('Batch opgeslagen. Je kunt direct een volgende batch toevoegen.', 'Batch saved. You can add another batch now.') ?></p>
    <?php endif; ?>

    <section class="excel-import-panel" data-excel-import="batch" aria-labelledby="batch-import-title">
        <div class="excel-import-heading">
            <div>
                <p class="eyebrow"><?= t('Gegevens overnemen', 'Import data') ?></p>
                <h2 id="batch-import-title"><?= t('Importeren vanuit Excel', 'Import from Excel') ?></h2>
                <p><?= t('Kies een bestand, controleer de kolommen en selecteer de batchrij.', 'Choose a file, check the columns, and select the batch row.') ?></p>
            </div>
            <div class="excel-import-actions">
                <a class="excel-import-template" href="#" download="circuleather-batch-template.csv" data-import-template><?= t('Sjabloon downloaden', 'Download template') ?></a>
                <label class="button button-secondary" for="batch-import-file"><?= t('Excelbestand kiezen', 'Choose Excel file') ?></label>
                <input id="batch-import-file" class="excel-import-file" type="file" accept=".xlsx,.xls,.csv" data-import-file>
            </div>
        </div>
        <div class="excel-import-workspace" data-import-workspace hidden>
            <div class="excel-import-row-select">
                <label for="batch-import-row"><?= t('Rij om over te nemen', 'Row to import') ?></label>
                <select id="batch-import-row" data-import-row></select>
            </div>
            <details class="excel-import-mapping" open>
                <summary><?= t('Kolommen controleren en koppelen', 'Review and match columns') ?></summary>
                <div class="excel-import-fields" data-import-fields></div>
            </details>
            <dl class="excel-import-preview" data-import-preview></dl>
            <button class="button button-secondary" type="button" data-import-apply><?= t('Gegevens naar formulier overnemen', 'Fill form with imported data') ?></button>
        </div>
        <p class="excel-import-status" data-import-status role="status" aria-live="polite"><?= t('Excelbestanden worden lokaal in je browser gelezen.', 'Excel files are read locally in your browser.') ?></p>
    </section>

    <form class="batch-form" action="?page=batch" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
        <fieldset class="batch-material">
            <legend><span class="batch-step">01</span><?= t('Materiaalgegevens', 'Material details') ?></legend>
            <p>
                <label for="material-name"><?= t('Materiaalnaam', 'Material name') ?> *</label><br>
                <input id="material-name" name="material_name" type="text" required placeholder="<?= t('Bijv. Meubleer Grade A - Zwart', 'E.g. Upholstery leather Grade A - Black') ?>">
            </p>
            <p>
                <label for="sku">SKU / Batchnummer</label><br>
                <input id="sku" name="sku" type="text" placeholder="<?= t('Wordt automatisch gegenereerd', 'Generated automatically') ?>">
            </p>
            <p>
                <label for="grade"><?= t('Grade', 'Grade') ?> *</label><br>
                <select id="grade" name="grade" required>
                    <option value=""><?= t('Selecteer grade', 'Select a grade') ?></option>
                    <option value="A">Grade A</option>
                    <option value="B">Grade B</option>
                    <option value="C">Grade C</option>
                    <option value="snippers">Snippers</option>
                </select>
            </p>
            <p>
                <label for="color"><?= t('Kleur', 'Color') ?></label><br>
                <input id="color" name="color" type="text" placeholder="<?= t('Bijv. zwart', 'E.g. black') ?>">
            </p>
            <p>
                <label for="thickness"><?= t('Dikte (mm)', 'Thickness (mm)') ?></label><br>
                <input id="thickness" name="thickness" type="text" placeholder="<?= t('Bijv. 2-3 mm', 'E.g. 2-3 mm') ?>">
            </p>
        </fieldset>

        <fieldset class="batch-pricing">
            <legend><span class="batch-step">02</span><?= t('Prijs & marge', 'Pricing & margin') ?></legend>
            <p>
                <label for="sale-price"><?= t('Verkoopprijs per kg (€)', 'Sale price per kg (€)') ?> *</label><br>
                <input id="sale-price" name="sale_price" type="number" min="0" step="0.01" required placeholder="0.00">
            </p>
            <p>
                <label for="cost-price"><?= t('Inkoopkosten per kg (€)', 'Cost per kg (€)') ?> *</label><br>
                <input id="cost-price" name="cost_price" type="number" min="0" step="0.01" required placeholder="0.00">
            </p>
        </fieldset>

        <fieldset class="batch-quantity">
            <legend><span class="batch-step">03</span><?= t('Hoeveelheid', 'Quantity') ?></legend>
            <p>
                <label for="unit"><?= t('Eenheid', 'Unit') ?> *</label><br>
                <select id="unit" name="unit" required>
                    <option value="kg"><?= t('Kilogram (kg)', 'Kilogram (kg)') ?></option>
                    <option value="piece"><?= t('Stuk', 'Piece') ?></option>
                </select>
            </p>
            <p>
                <label for="stock"><?= t('Beginvoorraad', 'Starting stock') ?> *</label><br>
                <input id="stock" name="stock" type="number" min="0" step="0.01" required placeholder="0">
            </p>
            <p>
                <label for="minimum-stock"><?= t('Minimale voorraad (melding)', 'Minimum stock alert') ?></label><br>
                <input id="minimum-stock" name="minimum_stock" type="number" min="0" step="0.01" placeholder="20">
            </p>
        </fieldset>

        <fieldset class="batch-photos">
            <legend><span class="batch-step">04</span><?= t('Foto\'s & documentatie', 'Photos & documents') ?></legend>
            <p>
                <label for="batch-photo"><?= t('Materiaalbatchfoto', 'Material batch photo') ?></label><br>
                <input id="batch-photo" name="batch_photo" type="file" accept="image/*">
            </p>
            <p>
                <label for="inspection-photo"><?= t('Kwaliteitsinspectiefoto', 'Quality inspection photo') ?></label><br>
                <input id="inspection-photo" name="inspection_photo" type="file" accept="image/*">
            </p>
        </fieldset>

        <fieldset class="batch-origin">
            <legend><span class="batch-step">05</span><?= t('Herkomst & batch', 'Source & batch') ?></legend>
            <p>
                <label for="supplier"><?= t('Batchbron / leverancier', 'Batch source / supplier') ?> *</label><br>
                <input id="supplier" name="supplier" type="text" required>
            </p>
            <p>
                <label for="arrival-date"><?= t('Inzamedatum', 'Collection date') ?></label><br>
                <input id="arrival-date" name="arrival_date" type="date">
            </p>
            <p class="batch-field-wide">
                <label for="origin"><?= t('Herkomst / Batchbeschrijving', 'Origin / Batch description') ?></label><br>
                <textarea id="origin" name="origin" rows="4" placeholder="<?= t('Beschrijf de herkomst van deze batch', 'Describe where this batch came from') ?>"></textarea>
            </p>
        </fieldset>

        <div class="form-actions">
            <p>* <?= t('Verplichte velden', 'Required fields') ?></p>
            <div class="form-action-buttons">
                <a href="?page=inventory"><?= t('Annuleren', 'Cancel') ?></a>
                <button class="button button-secondary" type="submit" name="save_and_new" value="1"><?= t('Opslaan & nieuwe batch', 'Save & add another') ?></button>
                <button class="button button-primary" type="submit"><?= t('Batch opslaan', 'Save batch') ?></button>
            </div>
        </div>
    </form>
</section>
