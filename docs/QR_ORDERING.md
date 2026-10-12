# Staff and public QR ordering

Open **Staff → QR Ordering** (`staff/qr_ordering.php`) to display, print or download
the shop QR. Its URL opens `user/ordering.php` with guest ordering and an account
QR scanner. Staff can use **Create order / scan customer QR** for assisted orders.

Guests enter a validated name and an 11-digit Philippine mobile number beginning
with `09`. Delivery requires a barangay from the registration choices and a
purok/street. Pickup allows both address fields to remain empty. Nonempty optional
fields are validated. Final checkout validates these rules again, including when
customers change fulfillment type during review.

Both customer types use the existing purchase, review, checkout, stock reservation,
payment, approval and fulfillment services. Account QR scans resolve approved
accounts from the database; profile information embedded in QR JSON is ignored.
The scanner supports a camera and QR image upload. Camera access requires HTTPS
or localhost. QR generation and decoding use versioned CDN libraries.

Guest orders earn zero points and cannot consume account rewards. Registered
accounts retain the existing points-on-approval behavior. Entering a registered
phone number as a guest does not attach an account or award points.

## Storage and migration

The first database-backed request runs the idempotent guest schema migration.
All customers are stored in the existing `users` table. `customer_type` distinguishes
`guest` from `registered`; guest rows have no password, account lookup, or account
QR. Contact and address values stay encrypted, and private tracking tokens are
stored as hashes. Admin customer management shows guests with a Guest label and
does not allow account approval or portal access for them.

The read-only `order_customers` compatibility view reads only `users` and stores
no separate data. Existing order/payment/session identifiers remain unchanged.
Account login, account QR scanning and rewards explicitly require registered
customers. Guests remain eligible only for guest ordering and private tracking.

Schema version 2 also migrates any previous `guest_customers` records into `users`,
preserving IDs, encrypted values and tracking tokens. It verifies every copied
contact before removing the old guest table. Existing customer foreign keys remain
intact. The application database user needs its existing schema privileges plus
permission to update the compatibility view and remove the migrated legacy table.

Transactions retain encrypted customer snapshots, customer type, source and staff
creator. Historical orders fall back to their existing account profile. Staff,
admin, rider and tracking queries support guest contacts. Admin Transactions adds
customer/source filters and exposes these details in CSV exports.

Guests receive a private tracking URL. Save this URL to restore the guest session
after closing the browser. The guest contact ID and phone number alone cannot
restore guest access. Guest tracking and checkout responses disable caching and
referrer forwarding.

## Verification

Run `C:/xampp/php/php.exe tests/guest_ordering.php`. The test validates contacts and
creates a disposable local MySQL database to check migration, contact encryption,
guest/account restrictions, migration into shared storage, order queries, approval, stock release, staff attribution
and loyalty. It never migrates the configured application database. Set
`HYDROMIS_TEST_DB_PORT` to test against a separately started disposable local
MySQL instance with an empty-password root account.
