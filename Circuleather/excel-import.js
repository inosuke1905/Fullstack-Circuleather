/*
 * Shared browser-side Excel/CSV importer for batch and order forms.
 * Matches Dutch/English headings, previews one selected row and copies values on request.
 * Reading a spreadsheet never submits the form; normal server validation still applies when saving.
 */

(() => {
    const english = document.documentElement.lang === 'en';
    const messages = english
        ? {
            chooseFile: 'Choose an Excel or CSV file.',
            parserMissing: 'The spreadsheet reader could not load. Check your internet connection and try again.',
            fileTooLarge: 'Choose a file smaller than 10 MB.',
            unsupported: 'Choose an .xlsx, .xls, or .csv file.',
            noSheet: 'No worksheet was found in this file.',
            noRows: 'The first worksheet needs column headings in the first row and at least one data row.',
            loaded: (count) => `Loaded ${count} data row(s). Review the column matches and choose a row.`,
            applied: 'Imported values were copied into the form. Review them before saving.',
            missingMappings: (fields) => ` Required columns not matched: ${fields}.`,
            unmatchedItem: ' The imported SKU did not match an available inventory item; choose the item manually.',
            noValues: 'No values were found for the selected row. Check the column matches.',
            rowLabel: (number, value) => `Row ${number}${value ? `: ${value}` : ''}`,
            skip: 'Do not import',
            selectRow: 'Choose a row',
        }
        : {
            chooseFile: 'Kies een Excel- of CSV-bestand.',
            parserMissing: 'De spreadsheetlezer kon niet laden. Controleer je internetverbinding en probeer opnieuw.',
            fileTooLarge: 'Kies een bestand kleiner dan 10 MB.',
            unsupported: 'Kies een .xlsx-, .xls- of .csv-bestand.',
            noSheet: 'Er is geen werkblad gevonden in dit bestand.',
            noRows: 'Het eerste werkblad moet kolomnamen op de eerste rij en minimaal een gegevensrij bevatten.',
            loaded: (count) => `${count} gegevensrij(en) geladen. Controleer de kolommen en kies een rij.`,
            applied: 'Geimporteerde waarden zijn naar het formulier overgenomen. Controleer ze voor het opslaan.',
            missingMappings: (fields) => ` Niet gekoppelde verplichte kolommen: ${fields}.`,
            unmatchedItem: ' De geimporteerde SKU komt niet overeen met een beschikbaar voorraadartikel; kies het artikel handmatig.',
            noValues: 'Geen waarden gevonden voor deze rij. Controleer de kolomkoppelingen.',
            rowLabel: (number, value) => `Rij ${number}${value ? `: ${value}` : ''}`,
            skip: 'Niet importeren',
            selectRow: 'Kies een rij',
        };

    // Aliases describe spreadsheet headings; keys match form controls or the order-item special fields.
    const fieldSets = {
        batch: [
            { key: 'material_name', label: english ? 'Material name' : 'Materiaalnaam', aliases: ['material name', 'material_name', 'materiaalnaam', 'materiaal', 'leather'], required: true },
            { key: 'sku', label: 'SKU / Batchnummer', aliases: ['sku', 'batch number', 'batchnummer', 'batch no', 'artikelnummer'] },
            { key: 'grade', label: 'Grade', aliases: ['grade', 'kwaliteit'] , required: true },
            { key: 'color', label: english ? 'Color' : 'Kleur', aliases: ['color', 'colour', 'kleur'] },
            { key: 'thickness', label: english ? 'Thickness' : 'Dikte', aliases: ['thickness', 'dikte'] },
            { key: 'sale_price', label: english ? 'Sale price' : 'Verkoopprijs', aliases: ['sale price', 'sale price per kg', 'selling price', 'verkoopprijs', 'verkoopprijs per kg'] , required: true },
            { key: 'cost_price', label: english ? 'Cost price' : 'Inkoopkosten', aliases: ['cost price', 'purchase price', 'inkoopprijs', 'inkoopkosten', 'inkoopkosten per kg'], required: true },
            { key: 'unit', label: english ? 'Unit' : 'Eenheid', aliases: ['unit', 'eenheid'] },
            { key: 'stock', label: english ? 'Starting stock' : 'Beginvoorraad', aliases: ['stock', 'voorraad', 'quantity', 'hoeveelheid', 'beginvoorraad', 'begin voorraad'], required: true },
            { key: 'minimum_stock', label: english ? 'Minimum stock' : 'Minimale voorraad', aliases: ['minimum stock', 'minimum_stock', 'min stock', 'minimale voorraad'] },
            { key: 'origin', label: english ? 'Origin / description' : 'Herkomst / beschrijving', aliases: ['origin', 'herkomst', 'batch description', 'batchbeschrijving'] },
            { key: 'supplier', label: english ? 'Supplier' : 'Leverancier', aliases: ['supplier', 'leverancier', 'batch source', 'batchbron'], required: true },
            { key: 'arrival_date', label: english ? 'Arrival date' : 'Inzamedatum', aliases: ['arrival date', 'collection date', 'aankomstdatum', 'inzamedatum'] },
        ],
        order: [
            { key: 'client_name', label: english ? 'Client name' : 'Klantnaam', aliases: ['client name', 'customer name', 'client', 'customer', 'klantnaam', 'klant', 'naam'], required: true },
            { key: 'email', label: english ? 'Email address' : 'E-mailadres', aliases: ['email', 'email address', 'e-mail', 'e-mailadres', 'mail'], required: true },
            { key: 'phone', label: english ? 'Phone number' : 'Telefoonnummer', aliases: ['phone', 'phone number', 'telephone', 'telefoon', 'telefoonnummer'], required: true },
            { key: 'street_address', label: english ? 'Street address' : 'Adres', aliases: ['street address', 'address', 'straat', 'straatadres', 'adres'], required: true },
            { key: 'postal_code', label: english ? 'Postal code' : 'Postcode', aliases: ['postal code', 'postcode', 'zip code'], required: true },
            { key: 'city', label: english ? 'City' : 'Plaats', aliases: ['city', 'plaats', 'stad'], required: true },
            { key: 'item_sku', label: english ? 'Inventory SKU / item' : 'Voorraad-SKU / artikel', aliases: ['sku', 'item sku', 'product sku', 'batch sku', 'inventory item', 'artikelnummer', 'batchnummer', 'materiaal sku', 'material sku', 'product', 'artikel'], required: true },
            { key: 'quantity', label: english ? 'Quantity' : 'Hoeveelheid', aliases: ['quantity', 'qty', 'order quantity', 'ordered quantity', 'hoeveelheid', 'aantal', 'bestelde hoeveelheid'], required: true },
        ],
    };

    const templateHeaders = {
        batch: ['Materiaalnaam', 'SKU', 'Grade', 'Kleur', 'Dikte', 'Verkoopprijs per kg', 'Inkoopkosten per kg', 'Eenheid', 'Beginvoorraad', 'Minimale voorraad', 'Herkomst', 'Leverancier', 'Inzamedatum'],
        order: ['Klantnaam', 'E-mailadres', 'Telefoonnummer', 'Adres', 'Postcode', 'Plaats', 'SKU', 'Hoeveelheid'],
    };

    const normalize = (value) => String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();

    const cellText = (value) => value instanceof Date
        ? `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`
        : String(value ?? '').trim();

    // Accept both decimal separators and currency prefixes before assigning browser number inputs.
    const numberText = (value) => {
        if (typeof value === 'number') {
            return Number.isFinite(value) ? String(value) : '';
        }
        let text = String(value ?? '').trim().replace(/[\s€$£]/g, '');
        if (text.includes(',') && text.includes('.')) {
            if (text.lastIndexOf(',') > text.lastIndexOf('.')) {
                text = text.replace(/\./g, '').replace(',', '.');
            } else {
                text = text.replace(/,/g, '');
            }
        } else if (text.includes(',')) {
            text = text.replace(',', '.');
        }
        return /^\d+(?:\.\d+)?$/.test(text) ? text : '';
    };

    const dateText = (value) => {
        if (value instanceof Date && !Number.isNaN(value.getTime())) {
            return cellText(value);
        }
        const text = String(value ?? '').trim();
        const isoMatch = text.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/);
        if (isoMatch) {
            return `${isoMatch[1]}-${isoMatch[2].padStart(2, '0')}-${isoMatch[3].padStart(2, '0')}`;
        }
        const localMatch = text.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/);
        if (localMatch) {
            return `${localMatch[3]}-${localMatch[2].padStart(2, '0')}-${localMatch[1].padStart(2, '0')}`;
        }
        return '';
    };

    // Dispatch normal form events so existing price and stock calculations react to imported values.
    const setControlValue = (form, name, value) => {
        const control = form.elements.namedItem(name);
        if (!control || value === '') {
            return false;
        }
        if (control instanceof HTMLSelectElement && ![...control.options].some((option) => option.value === value)) {
            return false;
        }
        control.value = value;
        control.dispatchEvent(new Event('input', { bubbles: true }));
        control.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    };

    document.querySelectorAll('[data-excel-import]').forEach((panel) => {
        const type = panel.dataset.excelImport;
        const fields = fieldSets[type];
        const fileInput = panel.querySelector('[data-import-file]');
        const workspace = panel.querySelector('[data-import-workspace]');
        const rowSelect = panel.querySelector('[data-import-row]');
        const fieldsContainer = panel.querySelector('[data-import-fields]');
        const preview = panel.querySelector('[data-import-preview]');
        const applyButton = panel.querySelector('[data-import-apply]');
        const status = panel.querySelector('[data-import-status]');
        const templateLink = panel.querySelector('[data-import-template]');
        const form = document.querySelector(type === 'batch' ? '.batch-form' : '.order-form');
        let headers = [];
        let rows = [];

        if (!fields || !fileInput || !workspace || !rowSelect || !fieldsContainer || !preview || !applyButton || !status || !form) {
            return;
        }

        const templateCsv = `${templateHeaders[type].map((header) => `"${header.replace(/"/g, '""')}"`).join(';')}\r\n`;
        templateLink.href = URL.createObjectURL(new Blob(['\uFEFF', templateCsv], { type: 'text/csv;charset=utf-8' }));

        const selectedRow = () => rows.find((row) => String(row.sourceRow) === rowSelect.value) ?? null;
        // Read the chosen row through the current column mapping, preserving untouched fields when cells are empty.
        const selectedValues = () => {
            const row = selectedRow();
            if (!row) {
                return [];
            }
            return fields.map((field) => {
                const column = fieldsContainer.querySelector(`[data-import-map="${field.key}"]`)?.value ?? '';
                return { field, value: column === '' ? '' : cellText(row.values[Number(column)]) };
            });
        };

        const renderPreview = () => {
            preview.replaceChildren();
            selectedValues().forEach(({ field, value }) => {
                if (value === '') {
                    return;
                }
                const term = document.createElement('dt');
                const definition = document.createElement('dd');
                term.textContent = field.label;
                definition.textContent = value;
                preview.append(term, definition);
            });
        };

        const renderMappings = () => {
            fieldsContainer.replaceChildren();
            fields.forEach((field) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'excel-import-field';
                const label = document.createElement('label');
                const select = document.createElement('select');
                const id = `excel-map-${type}-${field.key}`;
                label.htmlFor = id;
                label.textContent = `${field.label}${field.required ? ' *' : ''}`;
                select.id = id;
                select.dataset.importMap = field.key;
                select.add(new Option(messages.skip, ''));
                headers.forEach((header, index) => {
                    if (cellText(header) !== '') {
                        select.add(new Option(cellText(header), String(index)));
                    }
                });
                const aliases = field.aliases.map(normalize);
                const matchIndex = headers.findIndex((header) => aliases.includes(normalize(header)));
                if (matchIndex >= 0) {
                    select.value = String(matchIndex);
                }
                select.addEventListener('change', renderPreview);
                wrapper.append(label, select);
                fieldsContainer.append(wrapper);
            });
            renderPreview();
        };

        rowSelect.addEventListener('change', renderPreview);
        templateLink.addEventListener('click', (event) => {
            if (!templateLink.href || templateLink.getAttribute('href') === '#') {
                event.preventDefault();
            }
        });

        fileInput.addEventListener('change', async () => {
            const file = fileInput.files?.[0];
            if (!file) {
                status.textContent = messages.chooseFile;
                return;
            }
            if (!window.XLSX) {
                status.textContent = messages.parserMissing;
                return;
            }
            if (file.size > 10 * 1024 * 1024) {
                status.textContent = messages.fileTooLarge;
                fileInput.value = '';
                return;
            }
            if (!/\.(xlsx|xls|csv)$/i.test(file.name)) {
                status.textContent = messages.unsupported;
                fileInput.value = '';
                return;
            }

            status.textContent = english ? 'Reading spreadsheet...' : 'Spreadsheet wordt gelezen...';
            try {
                // raw keeps CSV text intact so Dutch decimal commas and leading zeros survive parser inference.
                const workbook = XLSX.read(await file.arrayBuffer(), { type: 'array', raw: true, cellDates: true, sheetRows: 501 });
                const sheetName = workbook.SheetNames[0];
                if (!sheetName) {
                    throw new Error('no_sheet');
                }
                const matrix = XLSX.utils.sheet_to_json(workbook.Sheets[sheetName], { header: 1, defval: '', raw: true });
                headers = (matrix[0] ?? []).map((header) => cellText(header).replace(/^\uFEFF/, ''));
                if (headers.length > 80) {
                    throw new Error('too_many_columns');
                }
                rows = matrix.slice(1).map((values, index) => ({ sourceRow: index + 2, values }))
                    .filter(({ values }) => values.some((value) => cellText(value) !== ''));
                if (headers.every((header) => header === '') || rows.length === 0) {
                    throw new Error('no_rows');
                }

                rowSelect.replaceChildren(new Option(messages.selectRow, ''));
                rows.forEach((row) => {
                    const previewCell = row.values.find((value) => cellText(value) !== '');
                    rowSelect.add(new Option(messages.rowLabel(row.sourceRow, cellText(previewCell).slice(0, 50)), String(row.sourceRow)));
                });
                rowSelect.value = String(rows[0].sourceRow);
                renderMappings();
                workspace.hidden = false;
                const missing = fields.filter((field) => field.required
                    && !fieldsContainer.querySelector(`[data-import-map="${field.key}"]`)?.value)
                    .map((field) => field.label);
                status.textContent = messages.loaded(rows.length) + (missing.length ? messages.missingMappings(missing.join(', ')) : '');
            } catch (error) {
                console.error('Spreadsheet import failed:', error);
                workspace.hidden = true;
                status.textContent = error.message === 'no_sheet' ? messages.noSheet : messages.noRows;
            }
        });

        applyButton.addEventListener('click', () => {
            const values = selectedValues();
            if (!values.some(({ value }) => value !== '')) {
                status.textContent = messages.noValues;
                return;
            }

            const warnings = [];
            if (type === 'batch') {
                values.forEach(({ field, value }) => {
                    if (value === '') {
                        return;
                    }
                    let normalizedValue = value;
                    if (['sale_price', 'cost_price', 'stock', 'minimum_stock'].includes(field.key)) {
                        normalizedValue = numberText(value);
                    } else if (field.key === 'arrival_date') {
                        normalizedValue = dateText(value);
                    } else if (field.key === 'grade') {
                        const grade = normalize(value);
                        normalizedValue = /snipper|scrap|offcut/.test(grade) ? 'snippers' : (/\b(a|b|c)\b/.exec(grade)?.[1]?.toUpperCase() ?? value.toUpperCase());
                    } else if (field.key === 'unit') {
                        const unit = normalize(value);
                        normalizedValue = /piece|stuk/.test(unit) ? 'piece' : (/kg|kilo/.test(unit) ? 'kg' : value);
                    }
                    if (!setControlValue(form, field.key, normalizedValue)) {
                        warnings.push(field.label);
                    }
                });
            } else {
                values.filter(({ field }) => field.key !== 'item_sku' && field.key !== 'quantity')
                    .forEach(({ field, value }) => setControlValue(form, field.key, value));
                const itemValue = values.find(({ field }) => field.key === 'item_sku')?.value ?? '';
                const quantityValue = values.find(({ field }) => field.key === 'quantity')?.value ?? '';
                const select = form.querySelector('.order-batch-select');
                const quantityInput = form.querySelector('.order-quantity');
                const wanted = normalize(itemValue);
                // Prefer a unique exact SKU; ambiguous partial matches require manual material selection.
                const options = select ? [...select.options].filter((option) => option.value) : [];
                const exactMatches = itemValue === '' ? [] : options.filter((option) =>
                    (option.dataset.sku ?? '').trim().toLowerCase() === itemValue.trim().toLowerCase()
                    || normalize(option.textContent) === wanted);
                const partialMatches = itemValue === '' || exactMatches.length ? [] : options.filter((option) =>
                    normalize(option.textContent).includes(wanted));
                const matches = exactMatches.length ? exactMatches : partialMatches;
                const matchedOption = matches.length === 1 ? matches[0] : null;
                if (matchedOption) {
                    select.value = matchedOption.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                } else if (itemValue !== '') {
                    warnings.push(messages.unmatchedItem);
                }
                const normalizedQuantity = numberText(quantityValue);
                if (quantityInput && normalizedQuantity !== '') {
                    quantityInput.value = normalizedQuantity;
                    quantityInput.dispatchEvent(new Event('input', { bubbles: true }));
                    quantityInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            const warningText = warnings.length
                ? ` ${english ? 'Check:' : 'Controleer:'} ${warnings.join(', ')}`
                : '';
            status.textContent = messages.applied + warningText;
        });
    });
})();
