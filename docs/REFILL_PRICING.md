# Regular refill pricing

Open **Container Pricing** from the admin sidebar, select **Per gallon** under **Regular refill pricing**, and use the **Regular refill per gallon (PHP)** fields for each container type. Click **Save Prices** to apply changes. For example, set 19 Liters Round (5 Gallon) to PHP 25: one refill costs PHP 25 and three cost PHP 75, before delivery and caps.

The **Quantity pricing** formula is editable: set the last quantity in the fixed-price range, that range's total refill price, and the per-gallon rate above that quantity. Defaults remain PHP 80 total for 1–4 containers and PHP 15 each for 5 or more. Per-gallon fields apply only when Per gallon is selected. New-container prices include water and remain separate from refill prices.

Set **Delivery fee per gallon (PHP)** to change delivery charges (default PHP 10 per container). Pickup and approved free-delivery rewards have no delivery charge. A live price list shows all quantities from 1 through 30, with refill, delivery, and combined totals for the selected container. The formula also applies above 30; 30 is the preview length, not an order limit.

Customer product labels, purchase estimates, order review, checkout, and server totals use the selected method. Existing saved orders keep their charged amounts; new and resubmitted orders use current settings. Prices accept zero through PHP 99,999.99 with at most two decimal places.

Settings use the existing `system_settings` table (`container_bundle_prices`, `refill_pricing_mode`, and `order_pricing_rules`). No migration is required.
