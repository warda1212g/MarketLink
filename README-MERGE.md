# MarketLink (merged: Vendor + Admin)

Base project: Vendor. The Admin dashboard now runs on the same config, database,
session, login and helpers.

## Setup (XAMPP)
1. Copy the `MarketLink` folder to `htdocs/`.
2. phpMyAdmin > Import, in this order:
   `database/schema.sql`, `database/admin-migration.sql`, `database/approval-google-migration.sql`,
   `database/markets-migration.sql`, `database/favorites-migration.sql`, `database/marketplace-upgrade-migration.sql`
   (optional demo data: `database/seed_demo.php`, run it BEFORE the last migration
   so demo accounts come out approved).
3. DB settings live in `config/config.php` only.

## Logins
- Customers and vendors: `auth/login.php` and `auth/register.php`
  ("Sign in as Customer / Vendor", email + password or Continue with Google).
- Admin: `admin/login.php` (password only, no Google)
  admin@marketlink.local / Admin@123

## Approval flow
- Customers (form or Google) are active immediately - no admin approval. Admins can still suspend them.
- Vendors start as `pending`: they can sign in but land on `auth/pending.php` until an admin approves
  them in Admin > Manage Farmers/Vendors. Approved vendors become visible to customers.

## Marketplace flow (marketplace-upgrade-migration.sql)
- Admin creates market categories (Admin > Manage Market Categories; name, description, icon/image, status - no location).
- Vendor picks a category on sign-up / My Profile and runs their stall there.
- Customer: Browse Markets > category > stall > Add to cart > cart.php > checkout.php > order.php (tracking,
  pickup slot after the vendor accepts, modify/cancel while pending/accepted, message vendor, review when completed).
- Vendor order flow: New > Accepted > Preparing > Ready for Pickup > Completed (or Rejected / Cancelled).
- Messaging: messages.php (customer) and vendor/messages.php, only between customers and stalls they ordered from.
- Notifications: bell on every signed-in page, polled every 20 s via api/account/notifications.php; centres at
  notifications.php, vendor/notifications.php, admin/notifications.php.
- Moderation: Admin > Content & Moderation (products, reviews, vendors, reports, moderation log). Customers report
  products/reviews/stalls; vendors report customer messages/reviews.

## Google Sign-In setup
- Client ID and secret are in `config/config.php` > `google`.
- In Google Cloud Console > Credentials > your OAuth client, add this Authorized
  redirect URI (adjust the folder if yours is different):
  `http://localhost/MarketLink/auth/google-callback.php`
- PHP needs the curl extension (enabled by default in XAMPP).

## What changed from the Admin project
- Removed: its own login.php, logout.php, config/db.php, config/env.php, .env,
  includes/auth.php, includes/activity.php, index.php, style.css, auth.css.
- Added `includes/admin.php` (require_admin_page, require_admin_api, log_activity),
  loaded by `includes/bootstrap.php`.
- Admin queries rewritten for the vendor schema:
  farmers -> vendors, farmer_id -> vendor_id, role 'farmer' -> 'vendor',
  users.status -> users.is_active, approve/suspend -> vendors.is_active 1/0,
  pickup_slot -> pickup_start/pickup_end, deleted products excluded.
- "Markets" KPI = active vendors with a map location (no markets table exists).
- Logout is POST + CSRF everywhere.
- Passwords are bcrypt (plain text admin passwords removed).
