# Regular refill pricing

In Admin Settings → Container Pricing, select **Per gallon** under **Regular refill pricing** to use the **Regular refill per gallon (PHP)** fields for each container type. For example, set 5 Gallon Round to PHP 25: one refill costs PHP 25 and three cost PHP 75, before delivery and caps.

The default **Quantity pricing** method keeps the existing PHP 80 total for 1–4 containers and PHP 15 each for 5 or more. Per-gallon fields apply only when Per gallon is selected. New-container prices include water and remain separate from refill prices.

Customer product labels, purchase estimates, order review, checkout, and server totals use the selected method. Existing saved orders keep their charged amounts; new and resubmitted orders use current settings. Prices accept zero through PHP 99,999.99 with at most two decimal places.

Settings use the existing `system_settings` table (`container_bundle_prices` and `refill_pricing_mode`). No migration is required.
