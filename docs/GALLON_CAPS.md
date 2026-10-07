# Gallon cap requests and pricing

Customers select **Request a gallon cap** and enter a positive whole-number quantity on order review. The configured cap price and subtotal appear in the receipt and confirmation; checkout totals and server totals include the caps.

Administrators set **Gallon cap price (PHP)** in Settings under Container pricing. Zero means free caps. Prices are validated to two decimal places. The setting uses the existing `system_settings` table; no new columns or migration are needed.

Each order saves the cap quantity, charged unit price, and subtotal as generated lines in its existing customer instructions. Staff and riders see these instructions. Later price changes do not alter saved order amounts or cap monitoring; resubmitting an edited pending order uses current prices. Earlier requests without a saved price count as free.

Admin Transactions shows gallons, caps requested, the saved cap subtotal and unit price, and the order total. Date range, status and cap-request filters are available. Summary quantities and sales use the selected dates/payment method, independently of the status and cap filters. Cancelled/denied orders and reward/demo transactions are excluded. Fulfilled quantities use delivered/completed status; cap sales require fulfillment and paid status. Summaries read each transaction once.

No schema migration is required. Previously added cap columns may remain unused to preserve existing data.
