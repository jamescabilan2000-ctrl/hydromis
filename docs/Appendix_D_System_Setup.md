# Appendix D

## System Setup

HydroMIS is an online water refilling management system accessed through a web browser on computers, smartphones, and laptops. It uses HTML, CSS, JavaScript, PHP, and MySQL. The system is hosted on a web server with PHP and MySQL support, allowing authorized customers, staff members, riders, and administrators to access their respective modules over the internet.

## Requirements

- A web hosting account with PHP and MySQL support, such as Hostinger.
- A registered domain name connected to the hosting account.
- An SSL certificate so the system is available through HTTPS.
- A MySQL database and database user created through the hosting control panel.
- A modern web browser and an active internet connection for end users.
- A code editor, such as Visual Studio Code or Sublime Text, for maintenance and future updates.

## System Installation/Setup

### Step 1: Prepare the Hosting Account

1. Log in to the web hosting control panel.
2. Confirm that the domain points to the hosting account.
3. Enable SSL for the domain so users can access the system through HTTPS.

### Step 2: Upload the Source Code

1. Locate the HydroMIS source code folder.
2. Upload the contents of the project to the domain's public web directory, such as `public_html`, using the hosting file manager or FTP.
3. Preserve the project folder structure, including the `admin`, `api`, `config`, `database`, `rider`, `staff`, `user`, `uploads`, and `qrcodes` folders.

### Step 3: Create and Configure the Database

1. Open the MySQL Databases section of the hosting control panel.
2. Create a database and database user, then assign the user full privileges for that database.
3. Open phpMyAdmin from the hosting control panel and import `database/schema.sql` into the new database.
4. Copy `config/database.local.example.php` to `config/database.local.php` and enter the production database host, port, database name, username, and password.
5. Keep `config/database.local.php` and `config/.encryption_key` private. They contain environment-specific settings and must remain on the web server when the system is updated.

### Step 4: Confirm System Availability

1. Open the deployed domain in a browser using `https://your-domain.com/`.
2. Confirm that the welcome page and sign-in page load without errors.
3. Sign in with authorized accounts and confirm that each role can access its assigned module.

### Step 5: Access the Online System

Replace `your-domain.com` with the registered HydroMIS domain.

- **Welcome page:** `https://your-domain.com/`
- **Sign-in page:** `https://your-domain.com/login.php`
- **Customer portal:** `https://your-domain.com/user/`
- **Staff portal:** `https://your-domain.com/staff/dashboard.php`
- **Rider portal:** `https://your-domain.com/rider/dashboard.php`
- **Admin portal:** `https://your-domain.com/admin/dashboard.php`

The system requires an internet connection and a valid account for the selected user role. HTTPS also supports secure browser location access for the live delivery tracking features.
