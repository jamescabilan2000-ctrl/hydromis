function waterOrderTotal(quantity) {
    if (quantity < 1) return 0;
    return quantity <= 4 ? 80 : 15 * quantity;
}
