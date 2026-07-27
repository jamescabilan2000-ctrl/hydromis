# HydroMIS

HydroMIS is organized by application module so a developer can quickly find the
screen or service they need.

## Start here

- [System module map](PROJECT_STRUCTURE.md) - every feature grouped by module
- [Quick start](QUICK_START.md) - local setup and first run
- [Database schema](database/schema.sql) - primary database structure

## Main application modules

| Module | Folder / entry point | Purpose |
|---|---|---|
| Public website | `index.php`, `home.php` | Landing page and public storefront |
| Customer portal | `user/` | Ordering, checkout, payment, rewards, and tracking |
| Staff portal | `staff/` | Approvals, payments, deliveries, history, and rewards |
| Rider portal | `rider/` | Rider login, assigned deliveries, and live GPS |
| Admin portal | `admin/` | Dashboard, users, riders, transactions, and reports |
| API | `api/` | Delivery-tracking endpoints |
| Configuration | `config/` | Database and service connections |
| Database | `database/` | Schema and database migrations |
| Front-end assets | `css/`, `js/`, `imagess/` | Styles, scripts, and images |
| Runtime storage | `uploads/`, `qrcodes/`, `data/` | User-generated and generated files |

> Keep public PHP entry points in their current folders. Existing links use these
> paths, so moving them requires a coordinated routing change.
