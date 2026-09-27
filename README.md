# Kakebe Tech Camp 2026 — Website, Payments & Control Panel

This is a one-page website for Kakebe Tech Camp 2026 (14th – 23rd December, Kitgum). It includes online registration, ioTec Pay payments, PDF receipts, a participant portal and an admin control panel.

It is built with HTML, CSS and JavaScript, plus PHP 8.1+ and MySQL/MariaDB. There are no external PHP libraries.

## What it does

- **Registration**: collects the applicant's details and up to **2 learning tracks** (out of 6). Each applicant also gets a jersey size, a free camp shirt, an optional **Aruu Falls visit** and **free Mentorship & Digital Bridge enrolment** (October – November).
- **Package pricing**: camp fee UGX 100,000 plus sports jersey UGX 20,000 (both required) = **UGX 120,000**. With Aruu Falls (UGX 20,000) the total is **UGX 140,000**. You can change these prices in Admin → Settings → Pricing.
- **Checkout**: after registering, people choose **Pay now** or **Pay later**.
  - Paying **50%** or more books their slot. They can clear the balance any time.
  - Paying later works through the link in their email, or through **Pay / My account**, where they find their registration with their email and phone number.
- **Payments**: Mobile Money (MTN/Airtel) and Visa/Mastercard, through **ioTec Pay**.
  - Each payment emails a **PDF receipt** showing the amount paid and the balance.
  - Once the balance is cleared, the participant gets a **camp ticket** with their photo and a QR code.
- **Sponsor a child**: supporters pay UGX 120,000 per child, or any amount they choose, and get a PDF receipt.
- **Participant portal** (`/portal`): participants log in with a code sent to their email. From there they can pay, download receipts, open their ticket, and edit their profile and photo.
- **Emails** (sent from Gmail kakebetech.comms@gmail.com, in the ObiFunds design with Kakebe colours):
  - to applicants: registration confirmations, payment receipts with the PDF attached, and balance reminders
  - to the team: an alert for every registration, payment and contact message
- **Control panel** (`/admin`):
  - **Dashboard**: numbers, charts, seat capacity, tracks, jersey sizes and a referrers leaderboard.
  - **Participants**: filters, balances and bulk reminders. Each participant's page has a payments ledger, lets you record cash/bank payments, waive a balance, edit details and send emails.
  - **Finance**: expected vs collected money, outstanding balances, a breakdown by item and by payment method, and a "remind everyone" button.
  - **Payments** and **Sponsorships**: lists with re-check buttons and CSV export.
  - **Core team**: upload photos, which appear on the website.
  - **Also**: email log, settings and admin users.

## Run locally (XAMPP)

1. Start **Apache** and **MySQL**. Then open <http://localhost/kakebetechcamp/>. The database is created and upgraded automatically.
2. Create your admin account at <http://localhost/kakebetechcamp/admin/setup.php>.
3. Go to **Core team** and upload each team member's photo.

## Payments (ioTec)

- The keys are in the `.env` file, which is kept out of git. They are copied from ObiFunds.
- **Localhost uses the ioTec sandbox**, so no real money moves.
  - Test number `0111777771` → payment succeeds.
  - Test number `0111777991` → payment fails.
- **Any live domain always uses the LIVE wallet.**
- Payments are confirmed by asking ioTec directly: the browser checks while the person waits, and admins can press "Re-check". For payments where the person closed the page, schedule `php cron/sync-payments.php` every 5–10 minutes.
- An IPN endpoint exists at `/api/iotec-ipn.php`. Register it in the ioTec portal if Kakebe gets its own wallet.

## Email

Gmail SMTP (App Password) is configured in **Admin → Settings → Email**, and the password is stored encrypted. Every email sent is listed in **Admin → Email log**.

## Deploy to a live server

1. Upload everything except `imgs/`, which holds the original photos.
2. Create the database, then edit `includes/config.php`:
   - enter the database details
   - set `app.url` to your live address, e.g. `https://techcamp.kakebe.tech`
3. Copy `.env` to the server.
4. Make sure `storage/` and `uploads/team/` are writable by PHP.
5. Enable HTTPS. Add the cron job. Visit `/admin/setup.php`.

## Project structure

```
index.php  pay.php  payment-return.php  receipt.php  ticket.php  photo.php
api/        register, pay, payment-status, lookup, donate, contact, iotec-ipn
portal/     participant portal (login code, dashboard, profile)
admin/      control panel
includes/   config, db (auto-migrations), payments (ioTec), pdf (receipts), emails, mailer
assets/     css, js, img
cron/       sync-payments.php
storage/    applicant photos + logs (not web-accessible)
uploads/team/  core team photos (public images)
```
