# Optional gallon cap request

The order review screen shows **Request a gallon cap** below the gallon quantity. This is an optional request to include a cap if available. There is no cap quantity, unit price, subtotal, or additional charge.

The checkbox selection passes through checkout and is added to the existing customer instructions as:

> Please include a gallon cap if available.

Staff, administrators, and riders can read the request with the order instructions. Editing a pending order restores the checkbox from those instructions. Unchecking it removes the generated request when the edited order is saved.

No database migration or new database fields are required. The earlier cap pricing migration is no longer needed. If it was already applied, leave its unused columns in place to preserve historical data; this revision does not delete or rewrite existing orders.
