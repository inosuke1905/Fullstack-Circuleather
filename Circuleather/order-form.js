const itemsList = document.querySelector('#order-items');
const itemTemplate = document.querySelector('#order-item-template');
const addItemButton = document.querySelector('#add-order-item');
const totalOutput = document.querySelector('#order-total');

if (itemsList && itemTemplate && addItemButton && totalOutput) {
    const language = document.documentElement.lang;
    const currencyFormatter = new Intl.NumberFormat(language === 'en' ? 'en-GB' : 'nl-NL', {
        style: 'currency',
        currency: 'EUR',
    });
    let nextItemIndex = 1;

    const updateRow = (row) => {
        const select = row.querySelector('.order-batch-select');
        const quantityInput = row.querySelector('.order-quantity');
        const option = select.selectedOptions[0];
        const quantity = Number(quantityInput.value) || 0;

        if (!select.value) {
            quantityInput.removeAttribute('max');
            quantityInput.setCustomValidity('');
            row.querySelector('.order-item-thickness').textContent = language === 'en' ? 'Thickness: —' : 'Dikte: —';
            row.querySelector('.order-item-stock').textContent = language === 'en' ? 'Available: —' : 'Beschikbaar: —';
            row.querySelector('.order-item-price').textContent = language === 'en' ? 'Price: —' : 'Prijs: —';
            row.querySelector('.order-item-total').textContent = language === 'en' ? 'Line total: —' : 'Regeltotaal: —';
            return 0;
        }

        const unit = option.dataset.unit;
        const unitLabel = unit === 'piece' ? (language === 'en' ? 'pieces' : 'stuks') : 'kg';
        const priceUnitLabel = unit === 'piece' ? (language === 'en' ? 'piece' : 'stuk') : 'kg';
        const availableStock = Number(option.dataset.stock);
        const unitPrice = Number(option.dataset.price);
        const thickness = option.dataset.thickness || '—';
        quantityInput.min = unit === 'piece' ? '1' : '0.01';
        quantityInput.step = unit === 'piece' ? '1' : '0.01';
        quantityInput.max = unit === 'piece' ? Math.floor(availableStock) : availableStock;
        quantityInput.setCustomValidity(quantity > availableStock
            ? (language === 'en' ? 'Quantity exceeds available stock.' : 'De hoeveelheid is groter dan de beschikbare voorraad.')
            : '');

        row.querySelector('.order-item-thickness').textContent = `${language === 'en' ? 'Thickness' : 'Dikte'}: ${thickness}`;
        row.querySelector('.order-item-stock').textContent = `${language === 'en' ? 'Available' : 'Beschikbaar'}: ${availableStock} ${unitLabel}`;
        row.querySelector('.order-item-price').textContent = `${language === 'en' ? 'Price' : 'Prijs'}: ${currencyFormatter.format(unitPrice)} / ${priceUnitLabel}`;
        const lineTotal = quantity * unitPrice;
        row.querySelector('.order-item-total').textContent = `${language === 'en' ? 'Line total' : 'Regeltotaal'}: ${currencyFormatter.format(lineTotal)}`;
        return lineTotal;
    };

    const updateForm = () => {
        const rows = [...itemsList.querySelectorAll('[data-order-item]')];
        const total = rows.reduce((sum, row) => sum + updateRow(row), 0);
        totalOutput.textContent = currencyFormatter.format(total);
        rows.forEach((row, index) => {
            const heading = row.querySelector('[data-item-heading]');
            if (heading) {
                heading.textContent = `${heading.dataset.itemLabel} ${index + 1}`;
            }
            row.querySelector('.remove-order-item').disabled = rows.length === 1;
        });
    };

    addItemButton.addEventListener('click', () => {
        const row = itemTemplate.content.firstElementChild.cloneNode(true);
        const batchSelect = row.querySelector('[data-batch-select]');
        const batchLabel = row.querySelector('[data-batch-label]');
        const quantityInput = row.querySelector('[data-quantity-input]');
        const quantityLabel = row.querySelector('[data-quantity-label]');
        const batchId = `order-batch-${nextItemIndex}`;
        const quantityId = `order-quantity-${nextItemIndex}`;

        batchSelect.id = batchId;
        batchSelect.name = `items[${nextItemIndex}][inventory_id]`;
        batchLabel.htmlFor = batchId;
        quantityInput.id = quantityId;
        quantityInput.name = `items[${nextItemIndex}][quantity]`;
        quantityLabel.htmlFor = quantityId;
        nextItemIndex += 1;
        itemsList.append(row);
        updateForm();
        batchSelect.focus();
    });

    itemsList.addEventListener('change', updateForm);
    itemsList.addEventListener('input', updateForm);
    itemsList.addEventListener('click', (event) => {
        const removeButton = event.target.closest('.remove-order-item');
        if (removeButton && itemsList.querySelectorAll('[data-order-item]').length > 1) {
            removeButton.closest('[data-order-item]').remove();
            updateForm();
        }
    });

    updateForm();
}
