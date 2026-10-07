# Optional gallon caps

The existing workflow remains QR/mobile account entry ? container selection ? review ? checkout ? staff approval ? delivery or pickup. The cap checkbox is below the gallon quantity editor on checkout. Cap quantities must be positive whole numbers (1?99,999). Unchecking the box clears and disables the quantity and makes its subtotal zero.

## Deployment and migration

1. Back up the existing database.
2. For an existing database, run `database/20261007_gallon_caps.sql` once in phpMyAdmin or your MySQL/MariaDB client before deploying the updated PHP files. It adds `cap_quantity`, `cap_unit_price`, `cap_subtotal`, and nullable `order_water_subtotal`. Existing orders receive zero caps; their original amounts are not changed.
3. The application's existing schema bootstrap also adds these columns on MariaDB installations supporting `ADD COLUMN IF NOT EXISTS`. If bootstrap has already added them, do not run the one-time migration again. Check the transactions columns first. The standalone migration does not require `IF NOT EXISTS` support and works with MySQL.
4. Fresh installations include the columns in `database/schema.sql`.
5. Open **Admin ? Settings ? Container pricing** and set **Gallon cap unit price (PHP)**. No sample price is seeded. Blank disables new cap requests; zero is a configured free price. Prices accept up to two decimal places, from 0 through 99,999.99.

Saved cap prices and subtotals are used for receipts, operational details, tracking, and reports. Changes to pricing settings do not recalculate stored orders. An explicitly edited pending order is recalculated at current configured prices when resubmitted, matching the existing edit workflow. The server ignores client-supplied cap prices and subtotals. Caps are an optional charged line item; this feature does not reserve cap inventory.

`order_water_subtotal` preserves the exact water/container bundle charge. Existing volume pricing can yield a rounded average water unit price (for example, a fixed water charge divided across three gallons). For older orders without this snapshot, details use the saved quantity and unit price. The final saved order amount is always authoritative; delivery and discount appear separately.

## Operational views and reporting

Staff approval tables, delivery cards, pickup cards, and rider delivery cards show gallon quantities and caps requested. Expanding **Order Details** shows saved water and cap unit prices, quantities, subtotals, delivery, discount, and total. Zero requests explicitly say **No caps requested**. Rider details also show the price breakdown.

**Admin ? Transactions** shows gallons, caps, total amount, and expandable details. Filters include inclusive order date ranges, status, cap requests, and the existing payment-method filter. Cap summary totals use the selected order creation date range, independently of status, cap, and payment-method filters:

- Requested: caps on eligible orders in the range.
- Fulfilled: caps on orders with `delivery_status` delivered/completed or order `status` completed. This includes collected pickup orders because the existing workflow marks them delivered.
- Cap sales: saved cap subtotal only for fulfilled orders with `payment_status = paid`.
- Cancelled/canceled/denied orders (including delivery cancellation), reward transactions, and demo transactions are excluded from cap summaries.
- Each transaction is counted once; payment and rider-location records are not joined to the summary query.

Existing administrator, staff, and rider role checks remain in use. Customer order pages now bind ownership to the customer established by the existing QR/mobile entry session. Customers with an old session must enter through `user/scan_qr.php` again. Checkout submission requires the customer session CSRF token. Tracking queries and customer actions are scoped to that same account.

## Verification

Run from the project directory:

```powershell
C:/xampp/php/php.exe tests/gallon_caps_test.php
python tests/gallon_caps_workflow_test.py
```

The PHP checks cover validation, disabled/unconfigured caps, free caps, centavo calculations, quantity changes, historical prices, legacy orders, exact water subtotals, and rendered details. When the configured database is available, they also exercise report SQL against a connection-local temporary table without changing actual orders.

The Python checks execute the actual report SQL against SQLite fixtures, the actual admin filter-building code through PHP, and PHP-rendered checkout JavaScript through Node with a simulated DOM. They cover date boundaries, cancellations, paid completion, duplicate payment records, empty ranges, combined filters, new/refill containers, water and cap quantity changes, unchecking, pickup, and session ownership.

The configured database was unavailable during implementation. PHP syntax and the isolated calculation, filter, reporting, JavaScript, and ownership checks passed; live MySQL migration, order persistence, and browser verification still need a connected environment. After migration, use test customer accounts to check delivery/pickup with and without caps, staff approval, payment, rider delivery or pickup collection, admin totals, and a price change followed by viewing an older order.
