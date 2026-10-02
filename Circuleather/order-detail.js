/*
 * Client-side editor for existing order lines, status and price estimates.
 * Keeps template-generated input names unique and enforces the visible 30-line limit.
 * Server-side revision checks and stock validation remain authoritative.
 */

const orderEditForm = document.querySelector('#order-edit-form');

if (orderEditForm) {
    const english = document.documentElement.lang === 'en';
    const items = document.querySelector('#order-edit-items');
    const template = document.querySelector('#order-edit-template');
    const addButton = document.querySelector('#order-edit-add');
    const status = document.querySelector('#edit-status');
    const shippingButton = document.querySelector('#order-shipping-button');
    const money = new Intl.NumberFormat(english ? 'en-GB' : 'nl-NL', { style: 'currency', currency: 'EUR' });
    let nextIndex = items.querySelectorAll('[data-edit-item]').length;

    const update = () => {
        const shipped = status.value === 'shipped';
        shippingButton.textContent = shipped
            ? (english ? 'Mark as open' : 'Markeren als open')
            : (english ? 'Mark as shipped' : 'Markeren als verzonden');
        const rows = [...items.querySelectorAll('[data-edit-item]')];
        const selectedKeys = rows.map(row => row.querySelector('[data-edit-material]').value);
        let totalCents = 0;
        rows.forEach((row, index) => {
            const select = row.querySelector('[data-edit-material]');
            const option = select.selectedOptions[0];
            const quantity = row.querySelector('[data-edit-quantity]');
            const price = row.querySelector('[data-edit-price]');
            const isPiece = option?.dataset.unit === 'piece';
            const stock = Number(option?.dataset.stock || 0);
            const amount = Number(quantity.value) || 0;
            const unit = isPiece ? (english ? 'pieces' : 'stuks') : 'kg';
            quantity.min = isPiece ? '1' : '0.01';
            quantity.step = isPiece ? '1' : '0.01';
            quantity.setCustomValidity('');
            if (select.value && status.value !== 'cancelled') {
                quantity.max = isPiece ? Math.floor(stock) : stock;
                if (amount > stock) {
                    quantity.setCustomValidity(english ? 'Quantity exceeds available stock.' : 'De hoeveelheid is groter dan de beschikbare voorraad.');
                }
            } else {
                quantity.removeAttribute('max');
            }
            select.setCustomValidity(select.value && selectedKeys.filter(key => key === select.value).length > 1
                ? (english ? 'Choose each material only once.' : 'Kies ieder materiaal maximaal eenmaal.') : '');
            // Estimate totals in cents to match server rounding rather than adding floating-point money.
            const quantityHundredths = Math.round(amount * 100);
            const priceCents = Math.round((Number(price.value) || 0) * 100);
            const lineCents = Math.round(quantityHundredths * priceCents / 100);
            totalCents += lineCents;
            row.querySelector('[data-edit-line-total]').textContent = `${english ? 'Line total' : 'Regeltotaal'}: ${money.format(lineCents / 100)}`;
            row.querySelector('[data-edit-stock]').textContent = select.value ? `${english ? 'Available for this order' : 'Beschikbaar voor deze order'}: ${stock} ${unit}` : '';
            row.querySelector('[data-edit-heading]').textContent = `${english ? 'Item' : 'Artikel'} ${index + 1}`;
            row.querySelector('[data-edit-remove]').disabled = rows.length === 1;
        });
        document.querySelector('#order-edit-total').textContent = money.format(totalCents / 100);
        document.querySelector('[data-cancel-note]').hidden = status.value !== 'cancelled';
        addButton.disabled = rows.length >= 30;
    };

    // Replace template placeholders in ids, names and labels together to keep rows accessible and distinct.
    addButton.addEventListener('click', () => {
        if (items.querySelectorAll('[data-edit-item]').length >= 30) return;
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[id], [name], [for]').forEach(element => {
            for (const attribute of ['id', 'name', 'for']) {
                if (element.hasAttribute(attribute)) {
                    element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('__INDEX__', nextIndex));
                }
            }
        });
        nextIndex += 1;
        const row = fragment.querySelector('[data-edit-item]');
        items.append(fragment);
        update();
        row.querySelector('[data-edit-material]').focus();
    });
    items.addEventListener('click', event => {
        const remove = event.target.closest('[data-edit-remove]');
        if (remove && items.querySelectorAll('[data-edit-item]').length > 1) {
            remove.closest('[data-edit-item]').remove();
            update();
        }
    });
    items.addEventListener('change', event => {
        if (event.target.matches('[data-edit-material]')) {
            const price = event.target.closest('[data-edit-item]').querySelector('[data-edit-price]');
            price.value = event.target.selectedOptions[0]?.dataset.price || '';
        }
        update();
    });
    items.addEventListener('input', update);
    status.addEventListener('change', update);
    shippingButton.disabled = false;
    shippingButton.addEventListener('click', () => {
        status.value = status.value === 'shipped' ? 'open' : 'shipped';
        update();
        orderEditForm.requestSubmit();
    });
    update();
}

const orderDeleteForm = document.querySelector('#order-delete-form');
orderDeleteForm?.addEventListener('submit', event => {
    if (!window.confirm(orderDeleteForm.dataset.confirm)) {
        event.preventDefault();
    }
});
