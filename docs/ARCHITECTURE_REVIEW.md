# HydroMIS architecture and verification

## Application boundaries

Keep the current PHP entry point URLs stable. Customer, staff, administrator,
and rider pages own presentation and HTTP input. Shared services in `config/`
own reusable business rules. The database connection belongs to
`config/database.php`; credentials belong to the ignored local configuration.

Existing service responsibilities:

| Component | Responsibility |
| --- | --- |
| `customer_order_access.php` | Customer session ownership and customer CSRF token initialization |
| `delivery_access.php` | Delivery ownership, authoritative rider assignment, GPS coordinate validation |
| `inventory_service.php` | Inventory reservation/release, order approval and denial, customer notifications |
| `system_settings.php` | Configured prices and station settings |
| `loyalty_service.php` | Annual loyalty cycle |
| `storage_service.php` | Upload storage |
| `api/delivery_tracker.php` | JSON delivery operations, authenticated dispatch and state checks |

## Workflow invariants

Customer registration activates access immediately without admin approval.
Explicitly disabled accounts remain blocked. Checkout calculates prices
on the server and reserves stock. Staff approve or deny through the inventory
service. Pickup orders stay separate from rider delivery orders.

Delivery proceeds from assignment to `on_way` to `delivered`. The tracking API
accepts GPS writes and completion only from the active assigned rider of an
approved delivery that is on the way. `rider_id` is authoritative; an empty value
falls back to the legacy `assigned_rider`. Customers may read only their own
deliveries; verified staff and administrators may read operational delivery data.

Submitting a payment creates a pending or processing record. Staff verification
is responsible for marking it paid. Customer payment submission checks ownership
and CSRF, locks the transaction, and commits the payment and order together.
Customers cannot replace a paid payment or submit against a resolved order.

GPS absence is represented by null, rather than fabricated coordinates. The
delivery map waits for GPS, shows the recorded update time, retries temporary
failures, and stops polling after completion or session expiry.

## Verification completed

- PHP syntax checks for every project PHP file.
- `php tests/delivery_access_test.php`: ownership, assignment precedence,
  fulfillment restrictions, and GPS boundary cases.
- JavaScript parsing for the API helper, tracking component, and delivery map.
- API helper checks for GET parameters, subfolder installations, and JSON POST bodies.

## Runtime prerequisite and remaining review

Local Apache and MySQL were initially stopped. After starting XAMPP, the
configured hosting database account returned MySQL error 1045. No HydroMIS
database exists in local MySQL, and the repository contains schema files but no
data backup. Import the user-selected existing backup into an isolated local
database and configure local credentials before running workflow tests. Preserve
the encryption key belonging to the backup: encrypted account fields require it.

End-to-end verification is still required for registration, checkout, stock
exhaustion, payment submission and verification, assignment, GPS updates,
completion, cancellation, rewards, and reports. These checks have not passed yet.

Further architecture work should move request-time schema changes and seed
account creation out of database connection initialization into explicit,
versioned installation/migration commands. Consolidate duplicate payment
verification and delivery transitions into shared services, and test concurrency
against an isolated restored database before changing these paths. The current
changes do not constitute a full application rewrite or a production certification.
