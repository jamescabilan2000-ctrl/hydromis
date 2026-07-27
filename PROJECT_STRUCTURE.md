# HydroMIS System Module Map

Use this page as the directory for the whole system. Files are grouped by what
they do, not just by their extension.

## 1. Public website and access

| File | What to edit here |
|---|---|
| `index.php` | Public landing page |
| `home.php` | Customer-facing home/store page |
| `login.php` | Main sign-in flow |
| `create_account.php` | Public account-registration entry |
| `onboarding.php` | First-time user introduction |
| `logout.php` | Shared sign-out handler |
| `privacy.php`, `terms.php` | Legal pages |
| `admin_portal.php` | Admin portal entry |

## 2. Customer module (`user/`)

| Area | Files |
|---|---|
| Account and QR | `create_account.php`, `scan_qr.php` |
| Shopping | `purchase.php`, `order_review.php`, `checkout.php` |
| Payment | `payment.php`, `payment_view.php` |
| Orders | `order_details.php`, `track_order.php` |
| Loyalty | `rewards.php` |

Typical customer flow:

`home.php` → `user/purchase.php` → `user/order_review.php` →
`user/checkout.php` → `user/payment.php` → `user/track_order.php`

## 3. Staff module (`staff/`)

| File | Responsibility |
|---|---|
| `dashboard.php` | Staff overview and delivery operations |
| `pending.php` | Pending order approvals |
| `payments.php`, `payments_view.php` | Payment processing and display |
| `history.php` | Completed activity history |
| `rewards.php` | Customer reward claims |
| `sidebar.php` | Shared staff navigation |
| `check_auth.php` | Staff access control |

## 4. Rider module (`rider/`)

| File | Responsibility |
|---|---|
| `login.php` | Rider sign-in |
| `dashboard.php` | Assignments, delivery status, and map |
| `check_auth.php` | Rider access control |

Related scripts: `js/rider-gps-tracker.js` and
`js/order-tracking-map.js`.

## 5. Administration module (`admin/`)

| File | Responsibility |
|---|---|
| `dashboard.php` | Administrative overview and settings |
| `transactions.php` | Order and transaction management |
| `payments.php` | Payment administration |
| `reports.php` | Reports and analytics |
| `users.php` | Customer accounts |
| `manage_riders.php` | Rider accounts |
| `upload_qr.php` | QR/payment asset upload |
| `export_data.php` | Data export |
| `check_auth.php` | Admin access control |

## 6. Services and data

| Folder | Contents |
|---|---|
| `api/` | HTTP endpoints, currently delivery tracking |
| `config/` | Database, portal, and Supabase configuration |
| `database/` | Main schema and incremental SQL migrations |
| `data/` | Application-managed JSON data |

Never place credentials in public PHP, CSS, JavaScript, or documentation files.
Connection settings belong in `config/`.

## 7. Front-end assets

| Folder | Contents |
|---|---|
| `css/` | Page and module stylesheets |
| `js/` | Shared utilities, validation, maps, and GPS tracking |
| `imagess/` | Static interface images and payment logos |

CSS filenames generally match the page or role they style. For example,
`css/admin_reports.css` belongs to `admin/reports.php`.

## 8. Generated/runtime files

| Folder | Contents |
|---|---|
| `uploads/profile_photos/` | Customer profile photos |
| `uploads/payment_proofs/` | Uploaded payment evidence |
| `uploads/avatars/` | Admin/staff avatars |
| `qrcodes/` | Generated customer QR codes |

These are data folders, not source-code modules. They are excluded from normal
VS Code search so code results remain easy to scan.

## 9. Setup, tests, and documentation

| Type | Files |
|---|---|
| Setup | `setup.php`, `clean_setup.php` |
| Verification/tests | `verify_assignment.php`, `test_gps.html` |
| API testing | `HydroMIS_Delivery_Tracker_API.postman_collection.json` |
| Guides | `QUICK_START.md`, `LIVE_GPS_*.md`, `STAFF_MANAGEMENT_GUIDE.md` |

Files named `tmp_*`, `*_out.txt`, or `cookie_test.txt` are temporary diagnostics.
They remain in place to preserve existing work but are hidden in the editor's
default explorer view.

## Finding a file quickly

- Customer feature: open `user/`.
- Staff operation: open `staff/`.
- Admin management/report: open `admin/`.
- Rider delivery/GPS: open `rider/` and then `js/`.
- Database table or migration: open `database/`.
- Styling issue: search `css/` for the matching page or role name.
- Shared connection or environment issue: open `config/`.

In VS Code, press `Ctrl+P` and type part of a filename (for example,
`transactions` or `track_order`) to open it directly.
