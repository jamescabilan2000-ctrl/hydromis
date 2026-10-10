(() => {
    const form = document.querySelector('form');
    const type = document.getElementById('preview-container');
    const output = document.getElementById('pricing-preview');
    const money = value => 'PHP ' + value.toFixed(2);
    function updatePriceList() {
        const rules = {};
        for (const key of ['flat_max', 'flat_total', 'bulk_unit', 'delivery_unit']) {
            const input = document.getElementById('rule-' + key);
            if (!input.checkValidity() || input.value === '') {
                output.replaceChildren();
                return;
            }
            rules[key] = Number(input.value);
        }
        const priceInput = document.getElementById('price-' + type.value + '-water');
        const perGallon = document.getElementById('refillPricingMode').value === 'per_gallon';
        if (perGallon && (!priceInput.checkValidity() || priceInput.value === '')) {
            output.replaceChildren();
            return;
        }
        const unit = perGallon ? Number(priceInput.value) : null;
        const rows = document.createDocumentFragment();
        for (let quantity = 1; quantity <= 30; quantity++) {
            const refill = waterOrderTotal(quantity, unit, rules);
            const delivery = Math.round(rules.delivery_unit * quantity * 100) / 100;
            const row = document.createElement('tr');
            for (const value of [quantity, money(refill), money(delivery), money(refill + delivery)]) {
                const cell = document.createElement('td');
                cell.style.cssText = 'padding:12px 8px;border-bottom:1px solid var(--border)';
                cell.textContent = value;
                row.appendChild(cell);
            }
            rows.appendChild(row);
        }
        output.replaceChildren(rows);
    }
    form.addEventListener('input', updatePriceList);
    form.addEventListener('change', updatePriceList);
    type.addEventListener('change', updatePriceList);
    updatePriceList();
})();
