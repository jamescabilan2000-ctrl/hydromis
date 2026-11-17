# Shopping cart

The homepage plus buttons and the refill/new-gallon setup pages add products to
the same session cart. Matching size/container combinations merge; distinct
products stay separate. Each product supports quantities from 1 to 99.

`Choose products -> Add to cart -> Review cart -> Enter registered mobile number and delivery destination -> Place order -> Receipt`

Open `user/cart.php` to update quantities, remove products, choose delivery or
pickup, and review prices. Checkout matches the entered mobile number to an
existing registered customer without creating or switching a customer login.
Unregistered numbers must register first; registration from the cart returns
directly to checkout. Ordinary customer login still opens the dashboard.

An optional delivery address or map pin is stored on the transaction. When both
are omitted, the registered address is used. A pin without an address is treated
as the chosen destination. Customer profile addresses are never overwritten.

All prices come from system settings. Quantity-based 19L refill pricing uses the
combined eligible quantity across gallon shapes. Half-slim refills use their own
rate, and new gallons use the configured bundle price. Delivery is charged per
gallon; pickup has no delivery fee. An approved free-delivery reward is applied
when available. Changed prices/reward availability require another total review.

Checkout creates one `transactions` row, one pending `payments` row and a
`transaction_items` row per product. Inventory is locked and reserved as one
transaction, including generic new-container stock. Shortages or save failures
roll back all writes. One active order per customer remains the rule.

GCash and Maya checkout show the configured station QR and account details and
require the wallet mobile number plus a JPG/PNG/WEBP proof (maximum 5MB) before
placing an order. Proof is saved with the transaction and payment, both marked
`processing` for staff verification. A missing proof does not create an order.
Cash payments remain pending until collected.

All placed orders open a printable receipt showing the matched customer name,
number, products, destination and total. Receipts are accessible only from the
session that placed the order. Wallet receipts confirm that payment proof was
submitted and awaits staff verification.
Cart checkout does not automatically collect or verify wallet payments.

Staff see every product in the order description. Customer order details show
individual lines. Cancellation releases the exact stock reservations once.
Cart orders cannot enter the legacy single-product editor; cancel a pending cart
order and rebuild it when changes are needed after placement.

Files: `config/cart_service.php`, `api/cart.php`, `js/shopping-cart.js`,
`user/cart.php`, and `config/order_items.php`. The existing transaction-items
migration is applied by cart initialization. No hosting deployment was performed.

Validation: `php tests/cart_workflow_test.php` (local `hydromis_development` only).
It verifies mixed products, quantity limits, pricing, atomic checkout, pending
payments, duplicate prevention, approval, cancellation, stock rollback and removal.
HTTP checks cover persistence, registration/login return and CSRF rejection.
`php tests/guest_checkout_test.php` additionally checks mobile matching, saved
destination, receipt privacy, no automatic login and order-scoped wallet access.

## Validation

Browser and server checks cover mobile formats, registered/available accounts,
known products, integer quantities (1-99), payment method, current totals,
500-character text limits, paired location coordinates and coordinate bounds.
Errors appear beside fields and in an accessible summary. Changed quantity and
delivery selections must be updated before placing the order.

Wallet proof must be a nonempty JPG/PNG/WEBP image no larger than 5MB. The server
checks actual image content. Failed checkout removes temporary proofs and keeps
the cart; screenshots must be selected again after a server error.

Run `php tests/cart_validation_test.php` for invalid-input cases. Guest HTTP
tests also send malformed fields, invalid CSRF, fake images and oversized files,
and confirm that rejected requests create no order.
