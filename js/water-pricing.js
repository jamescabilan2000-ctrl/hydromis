function waterOrderTotal(quantity, unitPrice = null, rules = {flat_max: 4, flat_total: 80, bulk_unit: 15}) {
    if (quantity < 1) return 0;
    if (unitPrice !== null) return Math.round(unitPrice * quantity * 100) / 100;
    const unit = quantity <= rules.flat_max ? rules.flat_total / rules.flat_max : rules.bulk_unit;
    return Math.round(unit * quantity * 100) / 100;
}
