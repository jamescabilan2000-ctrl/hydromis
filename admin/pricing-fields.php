<?php
if (!isset($containerPrices, $capPrice, $refillMode, $pricingRules)) { http_response_code(404); exit; }
?>
<section class="price-card" aria-labelledby="refill-heading">
    <div class="card-heading"><span class="card-icon"><i class="fas fa-droplet" aria-hidden="true"></i></span><div><h2 id="refill-heading">Refill pricing</h2><p>Edit the rates used for regular water refills.</p></div></div>
    <input type="hidden" name="refillPricingMode" id="refillPricingMode" value="<?= htmlspecialchars($refillMode) ?>">
    <div id="quantity-rule-fields" class="rule-grid">
        <?php foreach (['flat_max' => ['Fixed range ends at', 'gallons'], 'flat_total' => ['Fixed range total', 'PHP'], 'bulk_unit' => ['Above the range', 'PHP / gallon']] as $key => [$label, $unit]): ?>
        <div class="price-field">
            <label class="field-label" for="rule-<?= $key ?>"><?= $label ?></label>
            <div class="input-wrap"><input class="price-input" id="rule-<?= $key ?>" name="rules[<?= $key ?>]" type="number" min="<?= $key === 'flat_max' ? '1' : '0' ?>" max="<?= $key === 'flat_max' ? '30' : '99999.99' ?>" step="<?= $key === 'flat_max' ? '1' : '0.01' ?>" required value="<?= $key === 'flat_max' ? $pricingRules[$key] : number_format($pricingRules[$key], 2, '.', '') ?>"><span class="input-unit"><?= $unit ?></span></div>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="formula-note" id="pricing-formula" role="status"></p>
</section>
<section class="price-card" aria-labelledby="containers-heading">
    <div class="card-heading"><span class="card-icon blue"><i class="fas fa-bottle-water" aria-hidden="true"></i></span><div><h2 id="containers-heading">Container prices</h2><p>New-container prices include water.</p></div></div>
    <div class="container-cards">
        <?php foreach (['2.5gal-slim' => ['9.5 Liters', 'Half Slim ? 2.5 Gallon', 'water3.jpg'], '5gal-slim' => ['19 Liters', 'Slim ? 5 Gallon', 'water4.webp'], '5gal-round' => ['19 Liters', 'Round ? 5 Gallon', 'water5.webp']] as $size => [$capacity, $shape, $image]): ?>
        <div class="container-price-card">
            <div class="container-title"><img src="../imagess/<?= $image ?>" alt="" width="44" height="52"><div><h3><?= $capacity ?></h3><p><?= $shape ?></p></div></div>
            <?php foreach (['water' => 'Regular refill', 'container' => 'New container + water'] as $kind => $label): ?>
            <div class="price-field <?= $kind === 'water' ? 'refill-field' : '' ?>">
                <label class="field-label" for="price-<?= $size . '-' . $kind ?>"><?= $label ?></label>
                <div class="input-wrap"><input name="prices[<?= $size ?>][<?= $kind ?>]" class="price-input container-price-input" id="price-<?= $size . '-' . $kind ?>" data-size="<?= $size ?>" data-kind="<?= $kind ?>" type="number" min="0" max="99999.99" step="0.01" required value="<?= number_format($containerPrices[$size][$kind], 2, '.', '') ?>"><span class="input-unit">PHP</span></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="field-help" id="refill-price-help">Regular refill prices apply when Per gallon is selected.</p>
</section>
<section class="price-card" aria-labelledby="fees-heading">
    <div class="card-heading"><span class="card-icon amber"><i class="fas fa-truck" aria-hidden="true"></i></span><div><h2 id="fees-heading">Delivery &amp; extras</h2><p>Charges added to the order subtotal.</p></div></div>
    <div class="fee-grid">
        <div class="price-field"><label class="field-label" for="rule-delivery_unit">Delivery per gallon</label><div class="input-wrap"><input class="price-input" id="rule-delivery_unit" name="rules[delivery_unit]" type="number" min="0" max="99999.99" step="0.01" required value="<?= number_format($pricingRules['delivery_unit'], 2, '.', '') ?>"><span class="input-unit">PHP</span></div><p class="field-help">Pickup and free-delivery rewards stay free.</p></div>
        <div class="price-field"><label class="field-label" for="capPrice">Price per gallon cap</label><div class="input-wrap"><input name="capPrice" id="capPrice" class="price-input" type="number" min="0" max="99999.99" step="0.01" required value="<?= number_format($capPrice, 2, '.', '') ?>"><span class="input-unit">PHP</span></div><p class="field-help">Set to zero for free caps.</p></div>
    </div>
</section>
