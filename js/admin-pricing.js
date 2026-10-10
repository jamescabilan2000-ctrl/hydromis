(() => {
    const form = document.getElementById('pricing-form');
    const type = document.getElementById('preview-container');
    const output = document.getElementById('pricing-preview');
    const money = value => 'PHP ' + value.toFixed(2);
    const formula = document.getElementById('pricing-formula');
    const example = document.getElementById('preview-example-total');
    const detail = document.getElementById('preview-example-detail');
    function clearPreview() {
        output.replaceChildren();
        example.textContent = '—';
        detail.textContent = 'Enter valid prices to see the preview.';
        formula.textContent = 'Complete the pricing fields to see your formula.';
    }
    function updatePriceList() {
        const perGallon = document.getElementById('refillPricingMode').value === 'per_gallon';
        document.getElementById('quantity-rule-fields').classList.toggle('is-inactive', perGallon);
        document.querySelectorAll('.refill-field').forEach(field => field.classList.toggle('is-inactive', !perGallon));
        document.getElementById('refill-price-help').textContent = perGallon
            ? 'Regular refill prices are active. Each refill is charged by container type.'
            : 'Regular refill prices are saved for Per gallon mode. Quantity pricing is currently active.';
        const rules = {};
        for (const key of ['flat_max', 'flat_total', 'bulk_unit', 'delivery_unit']) {
            const input = document.getElementById('rule-' + key);
            if (!input.checkValidity() || input.value === '') {
                clearPreview();
                return;
            }
            rules[key] = Number(input.value);
        }
        const priceInput = document.getElementById('price-' + type.value + '-water');
        if (perGallon && (!priceInput.checkValidity() || priceInput.value === '')) {
            clearPreview();
            return;
        }
        const unit = perGallon ? Number(priceInput.value) : null;
        formula.textContent = perGallon
            ? 'Refill total = the selected container’s refill price × quantity.'
            : '1–' + rules.flat_max + ' gallons: ' + money(rules.flat_total) + ' total. ' + (rules.flat_max + 1) + '+ gallons: ' + money(rules.bulk_unit) + ' each.';
        const sampleRefill = waterOrderTotal(5, unit, rules);
        const sampleDelivery = Math.round(rules.delivery_unit * 5 * 100) / 100;
        example.textContent = money(sampleRefill + sampleDelivery);
        detail.textContent = money(sampleRefill) + ' refill + ' + money(sampleDelivery) + ' delivery';
        const rows = document.createDocumentFragment();
        for (let quantity = 1; quantity <= 30; quantity++) {
            const refill = waterOrderTotal(quantity, unit, rules);
            const delivery = Math.round(rules.delivery_unit * quantity * 100) / 100;
            const row = document.createElement('tr');
            if (!perGallon && quantity === rules.flat_max) row.classList.add('range-end');
            for (const value of [quantity, money(refill), money(delivery), money(refill + delivery)]) {
                const cell = document.createElement('td');
                cell.textContent = value;
                row.appendChild(cell);
            }
            rows.appendChild(row);
        }
        output.replaceChildren(rows);
    }
    const initialValues = new URLSearchParams(new FormData(form)).toString();
    function onEdit() {
        const dirty = new URLSearchParams(new FormData(form)).toString() !== initialValues;
        document.querySelector('.save-bar').classList.toggle('is-dirty', dirty);
        document.getElementById('pricing-save-state').textContent = dirty ? 'Unsaved price changes' : 'Prices up to date';
        updatePriceList();
    }
    form.addEventListener('input', onEdit);
    form.addEventListener('change', onEdit);
    form.addEventListener('submit', () => {
        const button = form.querySelector('.btn-save');
        button.disabled = true;
        button.textContent = 'Saving prices…';
    });
    type.addEventListener('change', updatePriceList);
    updatePriceList();
})();
