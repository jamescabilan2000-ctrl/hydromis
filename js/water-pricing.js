function waterOrderTotal(quantity, unitPrice = null) {
    if (quantity < 1) return 0;
    if (unitPrice !== null) return Math.round(unitPrice * quantity * 100) / 100;
    return quantity <= 4 ? 80 : 15 * quantity;
}
