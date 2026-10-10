<?php
if (!isset($containerPrices, $capPrice, $refillMode)) {
    http_response_code(404);
    exit;
}
?>
        <div class="pricing-panel">
            <div class="settings-section">
                <div class="settings-section-title">Container pricing</div><div class="settings-row"><label class="settings-row-info" for="capPrice"><span class="settings-row-label">Gallon cap price (PHP)</span><span class="settings-row-desc">Per cap. Price changes apply to new or resubmitted orders.</span></label><input name="capPrice" id="capPrice" class="settings-select" type="number" min="0" max="99999.99" step="0.01" value="<?= number_format($capPrice,2,'.','') ?>" style="width:112px"></div>
                <div class="settings-row">
                    <label class="settings-row-info" for="refillPricingMode"><span class="settings-row-label">Regular refill pricing</span><span class="settings-row-desc" style="display:block">Choose Per gallon to use the refill prices below.</span></label>
                    <select name="refillPricingMode" id="refillPricingMode" class="settings-select">
                        <option value="quantity" <?= $refillMode === 'quantity' ? 'selected' : '' ?>>Quantity pricing</option>
                        <option value="per_gallon" <?= $refillMode === 'per_gallon' ? 'selected' : '' ?>>Per gallon</option>
                    </select>
                </div>
                <p class="settings-row-desc">Edit the quantity formula below, or choose Per gallon to use each container's refill price. New container prices include water. Changes apply to new or resubmitted orders.</p>
                <?php foreach (['flat_max' => 'Fixed price applies through this quantity', 'flat_total' => 'Total refill price for the fixed-price range (PHP)', 'bulk_unit' => 'Refill price per gallon above that quantity (PHP)', 'delivery_unit' => 'Delivery fee per gallon (PHP)'] as $key => $label): ?>
                <div class="settings-row">
                    <label class="settings-row-info" for="rule-<?= $key ?>"><span class="settings-row-label"><?= $label ?></span></label>
                    <input class="settings-select" id="rule-<?= $key ?>" name="rules[<?= $key ?>]" type="number" min="<?= $key === 'flat_max' ? '1' : '0' ?>" max="<?= $key === 'flat_max' ? '30' : '99999.99' ?>" step="<?= $key === 'flat_max' ? '1' : '0.01' ?>" required value="<?= $key === 'flat_max' ? $pricingRules[$key] : number_format($pricingRules[$key], 2, '.', '') ?>">
                </div>
                <?php endforeach; ?>
                <?php foreach (['2.5gal-slim' => '9.5 Liters Half Slim (2.5 Gallon)', '5gal-slim' => '19 Liters Slim (5 Gallon)', '5gal-round' => '19 Liters Round (5 Gallon)'] as $size => $label): ?>
                    <?php foreach (['water' => 'Regular refill per gallon', 'container' => 'New container including water'] as $kind => $priceLabel): ?>
                    <div class="settings-row">
                        <label class="settings-row-info" for="price-<?php echo $size . '-' . $kind; ?>">
                            <span class="settings-row-label"><?php echo $label; ?></span>
                            <span class="settings-row-desc" style="display:block"><?php echo $priceLabel; ?> (PHP)</span>
                        </label>
                        <input name="prices[<?php echo $size; ?>][<?php echo $kind; ?>]" class="settings-select container-price-input" id="price-<?php echo $size . '-' . $kind; ?>" data-size="<?php echo $size; ?>" data-kind="<?php echo $kind; ?>" type="number" min="0" max="99999.99" step="0.01" required value="<?php echo number_format($containerPrices[$size][$kind], 2, '.', ''); ?>" style="width:112px">
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>

        </div>
