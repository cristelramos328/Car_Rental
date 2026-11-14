# Car Rental System

A server-rendered car rental web application built with PHP and MySQL. Customers can browse vehicles and submit rental requests; owners can manage the fleet, review requests, and record payments.

> **Prototype notice:** This project is intended for learning, demonstration, and early-stage development. Review the security, privacy, payment, and legal requirements before using it with real customers or production data.

## Table of contents

- [Roles and features](#roles-and-features)
- [Rental and payment workflow](#rental-and-payment-workflow)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Clone the project](#clone-the-project)
- [Install and run](#install-and-run)
- [Configure email](#configure-email)
- [Configure PayMongo test checkout](#configure-paymongo-test-checkout)
- [Data, privacy, and security notes](#data-privacy-and-security-notes)
- [Troubleshooting](#troubleshooting)

## Roles and features

The application has two main roles:

| Role | Main capabilities |
|---|---|
| Customer | Browse vehicles, create an account, request rentals, view rental history, and follow booking status |
| Owner (administrator) | Manage vehicles, review and approve or reject rental requests, create bookings for customers, and manage rental operations |

Implemented features include:

- Public vehicle catalog with vehicle-type filtering, image carousels, details, pricing, and availability.
- Customer registration, sign-in, and password reset.
- Six-digit email one-time password (OTP) at customer sign-in. Owners sign in with their password and do not use the customer OTP step. No SMS is used.
- Rental requests with customer contact details, driver's-license details, dates, and optional location information.
- Owner dashboard for fleet management, rental approvals, booking creation, and rental status management.
- Customer rental history and optional location sharing/tracking for supported rentals.
- Cash-on-pickup and PayMongo-hosted e-wallet checkout in test mode.
- Private driver's-license and receipt document access.
- Privacy Policy and Terms & Conditions pages linked from the footer. The terms describe a ₱400 late-return charge per overdue day; the application does not automatically add that charge to a transaction.
- Responsive interface using Bootstrap 5.

## Rental and payment workflow

1. A customer selects a vehicle and provides rental details.
2. With cash, the request and pending transaction are recorded for owner review. With e-wallet, the request remains a private draft until PayMongo confirms payment; only then are the rental and completed transaction recorded.
3. The owner reviews the resulting rental request and approves or rejects it. Owners can also create a pending booking for a customer.
4. Approved rentals proceed through their rental period and can later be marked completed.
5. Rental status and payment status are separate. Approval does not, by itself, mean the rental is paid.
   - For cash, the owner records receipt after the customer pays.
   - For e-wallet checkout, the rental and completed transaction are recorded only after PayMongo confirms payment.
6. If an e-wallet checkout is explicitly cancelled, the app expires the session and removes the private draft and its uploaded license image. Closing the checkout tab does not notify the app immediately, so an internal draft can remain until the customer returns through the cancellation flow; it is not shown as a rental or transaction.

New e-wallet bookings use PayMongo Hosted Checkout with test credentials only. The configured methods are GCash, Maya, and GrabPay. Existing legacy GCash or bank-transfer records may still use the receipt-review flow.

## Architecture

The application uses a lightweight MVC-style structure with PHP entry-point scripts:

```text
Browser
  │
  ▼
Root PHP routes (index.php, login.php, dashboard.php, rent_process.php, ...)
  │
  ├── config.php: environment, MySQL connection, schema creation and migrations
  ▼
app/Controllers: request handling and workflow coordination
  ├── app/Models: users, cars, rentals, transactions, and security data
  ├── app/Services: email delivery and PayMongo API integration
  └── app/Views: server-rendered pages and shared layout
          │
          ├── MySQL / MariaDB
          ├── SMTP email server
          └── PayMongo test API
```

Important project paths:

```text
Car_Rental/
├── app/
│   ├── Controllers/       Request and workflow handlers
│   ├── Models/            Database and domain operations
│   ├── Services/          SMTP and PayMongo integrations
│   └── Views/             Authentication, dashboards, cars, rentals, shared layout
├── admin/                 Admin routes and bundled PHPMailer library
├── assets/css/            Application styles
├── uploads/               Vehicle images and private rental documents
├── config.php             Environment loading, database connection, schema setup
├── database.sql           Fresh-install schema and default owner account
├── paymongo_*.php         PayMongo checkout, return, and webhook endpoints
├── document.php           Authenticated private-document delivery
├── privacy_policy.php     Public privacy policy
├── terms.php              Public terms and conditions
└── README.md
```

## Requirements

- Git.
- Apache with PHP 8.x and MySQLi enabled.
- MySQL or MariaDB with InnoDB support.
- PHP cURL enabled for PayMongo checkout; OpenSSL is needed for secure SMTP/HTTPS connections.
- A modern browser and an internet connection to load the Bootstrap and icon libraries from their CDNs.
- An SMTP account for customer OTPs, password-reset codes, and email notifications.
- PayMongo test credentials only, if testing e-wallet checkout.

XAMPP is the simplest local setup on Windows, macOS, or Linux. A separate Composer install is not required for the currently bundled PHPMailer library.

## Clone the project

Choose a directory served by Apache, then clone the repository.

### Windows (XAMPP)

In PowerShell:

```powershell
Set-Location C:\xampp\htdocs
git clone https://github.com/cristelramos328/Car_Rental.git
Set-Location .\Car_Rental
```

### macOS (XAMPP)

In Terminal:

```bash
cd /Applications/XAMPP/htdocs
git clone https://github.com/cristelramos328/Car_Rental.git
cd Car_Rental
```

### Linux (XAMPP or Apache)

Use your Apache document root. Common locations are `/opt/lampp/htdocs` for XAMPP and `/var/www/html` for distribution-packaged Apache:

```bash
cd /opt/lampp/htdocs
git clone https://github.com/cristelramos328/Car_Rental.git
cd Car_Rental
```

If the Apache document root is not writable by your account, clone into a user-owned directory and configure an Apache virtual host, or have an administrator set appropriate ownership. Avoid making the project world-writable.

## Install and run

1. **Start Apache and MySQL.** In XAMPP, start both services from the control panel.

2. **Create the local environment file.** From the project root:

   ```powershell
   Copy-Item .env.example .env
   ```

   On macOS or Linux, use `cp .env.example .env`. Keep `.env` private; it is ignored by Git.

3. **Configure database access.** By default, the application connects to `127.0.0.1:3306` as `root` with an empty password and uses the `car_rental` database. To override these defaults, add settings to `.env`:

   ```ini
   DB_SERVER=127.0.0.1
   DB_PORT=3306
   DB_NAME=car_rental
   DB_USERNAME=root
   DB_PASSWORD=
   ```

   If you change `DB_NAME`, make sure the database selected by `database.sql` matches it too.

4. **Initialize the database.** For a fresh install, use phpMyAdmin to import `database.sql`. It creates the schema and seeds the default owner account. The application also creates the database and applies schema updates when `config.php` runs, but it does not seed the default account by itself. The MySQL account must have permission to create/alter the database and tables.

   Do not re-import `database.sql` into an existing installation without a backup. Its seed statement updates the default owner's account if that email already exists.

5. **Configure email** if customers need OTP login or password reset. See [Configure email](#configure-email).

6. **Set the application URL.** The current `BASE_URL` in `config.php` is `http://localhost/Car_Rental/`. If your host, protocol, or folder name differs, update it to the full URL for your installation, including the trailing slash.

7. **Open the application:**

   ```text
   http://localhost/Car_Rental/
   ```

   The seeded owner account is:

   ```text
   Email:    admin@carental.com
   Password: admin123
   ```

   Change this predictable password immediately after the first sign-in. Never use the seeded credentials on a public or production server.

## Configure email

Customer sign-in codes and password-reset codes are sent through SMTP using the settings in `.env`. Registration does not require an email-verification step. The example configuration is:

```ini
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your-address@gmail.com
SMTP_PASSWORD=your-app-password
SMTP_ENCRYPTION=tls
SMTP_FROM_EMAIL=your-address@gmail.com
SMTP_FROM_NAME="Vehicle Rental Management System"
```

For Gmail, use an app password rather than your regular account password. Set the sender address to an address permitted by your mail provider. Do not commit real SMTP credentials.

Customer OTPs expire after five minutes and can be resent after 60 seconds. Password-reset codes also expire after five minutes; reset emails are limited to one request per email/IP combination every five minutes. Owner sign-in does not require an OTP. If email delivery fails, verify the SMTP values and check the Apache error log; in XAMPP on Windows, it is commonly `C:\xampp\apache\logs\error.log`.

## Configure PayMongo test checkout

PayMongo is optional and is restricted by the application to test mode. Add your own test keys to the ignored `.env` file:

```ini
PAYMONGO_PUBLIC_KEY=pk_test_your_public_key
PAYMONGO_SECRET_KEY=sk_test_your_secret_key
PAYMONGO_WEBHOOK_SECRET=your_test_webhook_signing_secret
```

Never put credentials in PHP source, README files, screenshots, or commits. The application rejects live secret keys. To receive PayMongo webhook events, configure the test webhook for `checkout_session.payment.paid` to call:

```text
https://your-public-https-host/Car_Rental/paymongo_webhook.php
```

A local `localhost` address is not publicly reachable by PayMongo. The customer return flow also verifies the checkout session with PayMongo; webhook delivery is needed to reliably handle a payment when the customer closes the browser before returning.

## Data, privacy, and security notes

- `config.php` creates the database if needed and applies schema updates for existing installations. Back up the database before deploying updates.
- Rental status and transaction/payment status are independent values; an approved rental is not automatically paid.
- Driver's-license and receipt documents are stored under `uploads/private_documents/`. Access is checked by `document.php`; Apache rules deny direct access to the private folder. If using a non-Apache server such as Nginx, configure equivalent access denial before deployment.
- The application has no automatic fixed-schedule deletion for private license images. See the [Privacy Policy](privacy_policy.php) for the current retention statement.
- The ₱400 late-return charge is described in the [Terms & Conditions](terms.php), but the application does not calculate or automatically add it to payments.
- PayMongo is test-only. Do not use this prototype to collect live payments.
- Change seeded credentials, use strong database and SMTP passwords, use HTTPS for public deployments, restrict access to `.env`, and review authorization, backups, logging, privacy, and local legal requirements before production use.

## Troubleshooting

| Problem | Check |
|---|---|
| MySQL connection or schema initialization fails | Start MySQL; confirm `DB_SERVER`, `DB_PORT`, credentials, and database privileges in `.env` |
| Links point to the wrong host or folder | Update `BASE_URL` in `config.php` and reload the application |
| Customer OTP or reset email is not delivered | Configure SMTP, verify the sender and app password, and inspect the Apache error log |
| PayMongo checkout cannot start | Confirm cURL is enabled and `PAYMONGO_SECRET_KEY` is a valid test secret key |
| PayMongo webhook is not received | Use a publicly reachable HTTPS URL and verify the configured endpoint and test webhook secret |
| Private documents cannot be opened | Sign in as the owning customer or owner; confirm Apache `.htaccess` rules are enabled and PHP can access the document directory |
