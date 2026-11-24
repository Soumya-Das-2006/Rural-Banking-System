# 🏦 Bank Of Soumya

A responsive account-management application for rural and semi-urban communities, with three role-based portals: **Customer**, **Staff** and **Administrator**.

**Designed & developed by Soumya Das** · © 2026

---

## ✨ Features

| Portal | What you can do |
|---|---|
| **Customer** | Register & sign in · open accounts · check balances · withdraw · transfer to an account · transaction history · update profile & password · credit health and score estimates · virtual card records and service requests · QR profiles and internal payments · notifications · loan/FD/RD requests · budgets, savings goals and rewards · beneficiary, cheque-book and support requests · sign-in alerts and session revocation |
| **Staff** | Onboard customers · open & manage accounts · record deposits · assist withdrawals/transfers · review ATM card service requests · view balances and reports |
| **Admin** | Everything staff can do, plus customer/account and staff management · configurable staff service permissions · system settings · email outbox and announcements · QR/payment monitoring · customer-reported credit profiles and card-request review · loan/repayment and FD/RD management · branch/agent, beneficiary, cheque-book, KYC and support-ticket workflows · fraud/risk review · customer budgets, savings goals and rewards oversight · security/session review · financial analytics and filtered transaction exports |

**Highlights**

- Responsive, custom-themed interface with the optimized Bank Of Soumya wordmark across the landing page, auth screens and portals
- One audited money engine: server-side validation, ownership checks, row-locked atomic transactions, correct balances (incoming transfers are credited)
- Salted bcrypt passwords (old hashes upgrade automatically on first login)
- Aadhaar input validation checks 12-digit format and the Verhoeff checksum (it does not verify identity with UIDAI)
- Customer credit health with saved score estimates, score history, utilization, recommendations and loan affordability tools
- ATM and virtual-card records, limits, temporary freeze, replacement requests, expiry reminders and authorization history
- Local QR profiles and virtual IDs with dynamic amounts and camera/manual scanning; payments use the application ledger
- Per-customer notification history linked to email-outbox delivery status; no SMS
- Email transaction notices queued through an admin-managed SMTP outbox (no SMS)
- CSRF protection on every form and destructive link, output escaping, login rate-limiting, session hardening, signup honeypot and per-IP rate limiting
- Administrator login and authenticated-request activity history with IP, browser, timestamp, role and trusted-proxy country data
- Admin-managed customer testimonials with draft, publish and hide controls; public quotes require recorded customer permission
- HTTPS redirect, privacy and terms links, consent-gated optional analytics, SEO metadata, social preview, favicon, sitemap, robots rules, and a branded 404 page
- Configuration through environment variables · Docker ready · Apache/cPanel friendly · 13-language interface powered by Groq · online Send Money with receipt · voice-enabled AI assistant · fraud alerts · AI staff desk

---

## 🚀 Quick start

### Option A: Docker (recommended)

```bash
docker compose up --build
# open http://localhost:8080
```

Set real secrets first: `DB_PASS=... DB_ROOT_PASS=... docker compose up --build`.
To load demo customers, uncomment the `demo_data.sql` line in `docker-compose.yml`.

### Option B: XAMPP / WAMP / shared hosting (PHP 7.4+, MySQL 5.7+ or MariaDB 10.3+)

1. Copy this folder into `htdocs` (or `public_html`).
2. Create a database user, then import **`database/schema.sql`** (optionally `database/demo_data.sql`) with phpMyAdmin.
3. Copy `.env.example` → `.env` and fill in your database details.
4. Open the site in your browser.

> Required PHP extensions: `mysqli`, `pdo_mysql`, `mbstring`.
> For email delivery, configure authenticated SMTP in the private `.env`; do not store credentials in source code or a public ZIP.

### InfinityFree deployment

1. In the hosting control panel, create a MySQL database and assign the account user to it. Use the exact database name
   shown by the control panel (normally prefixed with `if0_...`).
2. Open phpMyAdmin for that database and import `database/schema.sql`. Import `database/demo_data.sql` only for testing;
   do not import demo data on a production system.
3. Upload the project to `htdocs` (the directory containing `index.php` should be at `htdocs/index.php`).
4. Copy `.env.example` to `.env` in the project root and set:

```dotenv
APP_ENV=production
APP_TIMEZONE=Asia/Kolkata
DB_HOST=sql208.infinityfree.com
DB_PORT=3306
DB_NAME=<exact MySQL database name>
DB_USER=if0_43515773
DB_PASS=<your cPanel account password>
PUBLIC_BASE_URL=https://bankofsoumya.xo.je
GA4_MEASUREMENT_ID=
TRUSTED_PROXY_IPS=
GROQ_API_KEY=
```

The FTP password is not necessarily the database password. For InfinityFree, use the password shown for the hosting
account/cPanel unless the database password has been set separately. Never upload `.env` through a public ZIP or
commit it to source control.

The site redirects non-local HTTP requests to `https://bankofsoumya.xo.je`; enable a valid SSL certificate for this
domain before deployment. Session cookies are marked Secure. Change `PUBLIC_BASE_URL` if the canonical domain changes.
The QR scanner also requires a trusted HTTPS page and explicit browser camera permission; its QR decoding runs locally.
Do not bypass browser security warnings to test camera access or banking features.

For an **existing database**, select the Rural Banking System database in phpMyAdmin and import
`database/update-all-migrations.sql`. This single additive file combines the credit, demo-card, email,
QR/UPI, customer notification, loan/deposit, budget, savings-goal, rewards, operations, login/activity-history,
public signup rate-limit and customer-testimonial tables.
It preserves existing customer, account, and transaction records; it does not run the starter-data inserts
from `schema.sql`. The QR transaction column and index are checked before being added, so this combined file
can also be imported if the individual migrations were already applied. The default system name is changed
to **Bank Of Soumya** only when it is still the shipped `Rural Banking System` value; custom names are preserved.
The legacy default logo setting resolves to the supplied Bank Of Soumya wordmark; custom uploaded logos remain supported.
The card archive columns are added idempotently; archiving retains both card and authorization records.

The original individual migration files remain available for staged upgrades. For new installations, import
`database/schema.sql` instead; it already includes these structures. Do not import `schema.sql` or
`demo_data.sql` over an existing production database.
If card-management tables are already installed and you are applying only the reversible archive feature, import
`database/card-archive-migration.sql`.

The customer **Loans & Deposits** page submits loan and deposit requests. Admin loan approval records a credit
through the existing application-ledger transaction engine and creates a repayment schedule; reconciliation checks
the existing ledger reference before completing a schedule, avoiding duplicate credits. FD/RD maturity amounts are
estimates: activating or marking a product matured does not move funds. Budget and savings-goal entries are planning
records, and reward points have no cash value.

Customer **Account Services** supports beneficiaries, cheque-book requests, and support tickets.
Administrators can review cheque requests, manage branch and rural-agent profiles, update a KYC review status,
and respond to support tickets; replies are queued through the email outbox. **Security Center** records customer
sign-ins, queues email alerts, and lets customers revoke sessions; revocation is enforced on the next authenticated
request. Customer security-session records retain one-way IP hashes. The separate admin activity view stores exact
sign-in IP addresses and browser details for up to 180 days; country is displayed only when a configured trusted
proxy supplies it. No third-party IP geolocation lookup is made. KYC status is a workflow label only and does not
verify Aadhaar or identity documents.

Administrators can configure per-staff permissions for customer/account management, card requests, deposits,
withdrawals, transfers, transaction history/corrections, reports, and risk alerts. Access checks are also enforced
on the mapped staff pages and account/transfer AJAX lookups. Existing staff retain access until a custom permission set is saved.
The admin **Customer Insights & Security** page reviews customer budgets, savings goals, rewards, and sign-in sessions. It can revoke a customer session (effective on their next authenticated request) and award or deduct points with an audit record; it never exposes IP/session hashes or changes account funds. **Beneficiary Management** supports search, status filtering, pagination, masked account numbers, and audited suspension/restoration. **Financial Analytics & Reports** provides date/type/status/channel/customer filters, paginated results, totals, full filtered CSV export (Excel-compatible), and print-to-PDF. Completed ledger rows are immutable; the admin, staff, and customer history pages do not delete transactions. Announcement drafts can be explicitly queued to up to 500 valid customer email addresses; backup status entries remain manual records and do not create or validate a backup.

## Service scope and integration status

The committed PHP tests can be run with `php tests/aadhaar-validation.php`,
`php tests/account-type-validation.php`, `php tests/flash-message-validation.php`,
`php tests/card-management.php`, `php tests/credit-simulation.php`, and
`php tests/groq-model-validation.php`, and `php tests/privacy-activity-validation.php`. SQL migrations must be imported into a **separate test database** before
testing database-backed pages. Do not validate by executing deposits/transfers, sending announcements, or changing
customer records on the live site.

This application is not itself a production banking or compliance service. Credit estimates are not CIBIL reports;
QR profiles and transfers use the internal application ledger; KYC statuses do not verify identity; card records do
not authorize payment-network transactions; FD/RD amounts are estimates without automatic maturity payout; rural
agent activity is not a cash collection rail; backups are not performed by the status page; and scheduled transfers
are schema-only and are not executed automatically. External banking, payment rails and identity verification
require appropriately authorized providers and documented integrations.

The customer-facing **Service Information** page at `InternetBanking/service_information.php` summarizes product
scope, Terms & Conditions, the Privacy Policy, activity-log retention and data handling. Update it if integrations
or provider configuration change. Set `PUBLIC_BASE_URL` to the canonical HTTPS origin for metadata and public links.
GA4 is optional and is loaded only after explicit analytics consent and a valid `GA4_MEASUREMENT_ID` is configured;
it is inactive by default. Country is taken only from supported country headers when the request comes from an IP
listed in `TRUSTED_PROXY_IPS`; leave that setting blank to show location as unavailable. The supplied `.env.example`
contains placeholders only and no production credentials.
To activate analytics, place the GA4 Measurement ID in `GA4_MEASUREMENT_ID` in the private `.env`; visitors must
still accept optional analytics before the Google script loads. `TRUSTED_PROXY_IPS` accepts exact proxy IP addresses
that populate the supported country headers; it does not accept CIDR ranges.

Credit estimates use the established `ib_credit_profiles` / `ib_credit_score_history` tables, and card records use
`ib_demo_cards` / `ib_demo_card_transactions` (legacy schema names). Keep these tables rather than creating duplicate
`ib_credit_scores`, `ib_credit_history`, `ib_debit_cards`, or `ib_card_limits` tables.

To enable email-only notifications, set `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`,
`MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` and (optionally) `MAIL_FROM_NAME` in the private `.env`. Use a TLS-capable SMTP
provider; never put the password in `.env.example`, a ZIP, or chat. Successful ledger transactions queue
notices for the account holder(s). Customer sign-in alerts include the recorded IP and available trusted-proxy
country. An administrator can review delivery status and manually send pending mail from
**Admin → Email Outbox**. This is intentionally manual so free hosting does not depend on a background scheduler.
No SMS integration or external payment rail is configured.

QR profiles generate codes locally with the MIT-licensed QRCode.js asset in
`InternetBanking/assets/js/`; camera scanning uses the browser's `BarcodeDetector` when available. QR values contain
only an opaque application token and optional amount. Camera access generally requires HTTPS; a manual virtual-ID entry is
available as a fallback. QR transfers are re-authorized through the existing password-confirmed Send Money flow and
recorded in the same internal ledger with a `upi_qr` channel. They are not valid UPI addresses and cannot be paid
outside this application.

---

## 🔑 First login

| Portal | URL | Email | Password |
|---|---|---|---|
| Admin | `/InternetBanking/admin/pages_index.php` | `admin@mail.com` | `Admin@12345` |
| Staff | `/InternetBanking/staff/pages_staff_index.php` | `staff@mail.com` | `Staff@12345` |
| Customer (demo data only) | `/InternetBanking/client/pages_client_index.php` | `ramesh@example.com` | `Client@12345` |

**Change the admin and staff passwords immediately** (My Profile), and ideally change the email addresses too.

### Recovering admin access

Passwords can't be reset from the website (that would be a security hole). From the server:

```bash
php bin/reset-admin-password.php admin@mail.com 'NewStrongPass1'
```

---

## 🌐 Multilingual support (Groq AI)

The whole site (landing page, sign-in pages and every portal's menus, labels and buttons) can be switched between **English + 12 Indian languages**: Hindi, Gujarati, Bengali, Tamil, Telugu, Marathi, Kannada, Malayalam, Punjabi, Odia, Urdu and Assamese. A floating 🌐 button also offers **Listen** (read-aloud).

**Set up (2 minutes)**
1. Create a free key at <https://console.groq.com/keys>.
2. Put it in `.env`: `GROQ_API_KEY=gsk_...` (Docker: `GROQ_API_KEY=... docker compose up`).
3. Use a current model in `.env`: `GROQ_MODEL=openai/gpt-oss-20b`. Older Llama model IDs are automatically replaced with this default.
4. Make sure the tables from `database/schema.sql` exist (`ib_translation_cache`, `ib_api_usage`).

**How it works:** the browser sends interface text to `InternetBanking/api/translate.php`; the server asks Groq, saves each translation in the database, and reuses it for every later visitor, so each phrase is paid for once. The key never reaches the browser.

**Privacy rules built in:** only interface text is translated. Tables, names, balances and anything containing 3+ digits or an email/URL is never sent. Rate-limited per IP. If no key is set, the site simply stays in English.

---

## 💸 How the online transaction system works

Money lives in **one place**: the `ib_transactions` table. Nothing stores a "balance" you can edit; the balance of an account is always calculated:

```
balance = deposits − withdrawals − transfers sent + transfers received
```

| Action | Who does it | How |
|---|---|---|
| **Deposit** (cash in) | Staff / admin | Staff sign in → Finances → Deposits → *Deposit Money* on the customer's account. Customers cannot credit themselves. |
| **Withdrawal** | Customer, staff, admin | Allowed only if the balance covers it. |
| **Transfer** | Customer (online), staff, admin | Customer: **Finances → Send Money**. |

**Customer "Send Money" flow (3 steps)**
1. **Details**: pick *your* account, type the receiving account number (the holder's name appears as you type so you can check it), enter the amount.
2. **Confirm**: review the summary and enter your **password** to authorise.
3. **Receipt**: a reference number (e.g. `TXN260923A1B2C3D4E5F6`) with date, amount and recipient, printable.

**Safety rules the server enforces on every transfer:** amount must be positive with at most 2 decimals · you can only use your own active accounts · the receiving account must exist and be active · insufficient balance is rejected · daily online limit (`ONLINE_DAILY_LIMIT`, default ₹50,000) · each request works only once (no double-sending on refresh) · the whole transfer is one database transaction, so it either fully happens or not at all.

**Try it (with demo data loaded)**
1. Sign in as staff and deposit ₹10,000 into a customer's account.
2. Sign in as that customer (`ramesh@example.com`) → *Send Money* → send ₹500 to another demo account number (see Finances → Balance Enquiries).
3. Sign in as the receiver: their balance went up by ₹500.

> Want real payments from outside (UPI, cards, net banking)? That needs a licensed payment gateway such as Razorpay or Cashfree and a business registration. The transfer engine here is the internal ledger that such a gateway would plug into.

---

## 📊 Credit health simulator

Customers can open **Credit Health** from their portal to save self-reported monthly income, debt payments, credit
limit/balance, on-time payment percentage, credit-history length and recent enquiries. The app uses these inputs to
show a **simulated 300–900 score**, a history graph of changed saved estimates, utilization, general recommendations,
and an improvement simulator. The loan affordability calculator estimates an EMI using an illustrative 40% income
budget. It does not create a loan or submit an application.

**This is not a CIBIL/TransUnion CIBIL score or report.** The app has no credit-bureau integration and no loan-repayment
ledger, so repayment information is not verified. The transparent educational formula weights reported repayment
history (40%), utilization (30%), history length (20%) and recent enquiries (10%); actual bureau and lender methods
differ. The profile and saved score history are stored per customer in the application database; customers can delete both
from the page. The report button opens the browser print dialog; choose **Save as PDF**.

## 💳 ATM and debit card management

Customers can generate an inactive virtual demo card or request a fictional ATM demo card. Staff/admin approve
physical-card requests; customers then activate, temporarily freeze (1 hour, 24 hours, or 7 days), permanently block,
change limits, and request replacements. Replacement immediately blocks the old card. Expiry reminders appear on the
customer card page. Defaults are ₹25,000 per day for ATM and ₹5,000 per transaction for POS and online; each limit can
be set from ₹0 to ₹100,000.

The card reference is an opaque `DEMO-RB-...` identifier, **not a PAN or usable card number**. The app does not collect
or store PAN, CVV, PIN, or OTP and has no card-network/payment-gateway integration. The authorization tester records
approved/declined sandbox checks and enforces the demo controls, but it does not check or change account balances and
never moves money. Use only these fictional records; real card issuance and payments require an appropriately
certified card/payment provider and compliance work.

---

## 🤖 AI & smart features

| Feature | Where | AI? |
|---|---|---|
| **Help assistant "Mitra"**: answers how-to and product questions in the user's language | 💬 *Help* button on every page | Groq |
| **Voice**: speak a question (🎤) and hear the answer (*Read answers aloud*) | Same chat window; also *Listen* on Monthly Summary | Browser speech (free) + Groq |
| **Monthly Summary** in plain language: "you deposited ₹13,000 and sent ₹4,200" | Customer → *Monthly Summary* | Numbers from the database, wording from Groq |
| **Fraud & anomaly alerts** | Staff / Admin → *Fraud Alerts* (red badge shows open alerts) | **No AI**: transparent rules |
| **Staff AI Desk**: customer activity note + notice/SMS drafter | Staff / Admin → *AI Desk* | Groq (anonymised data only) |

**Fraud rules** (all tunable in `.env`): large transaction · large cash deposit · amount far above the customer's own average (3σ) · many withdrawals/transfers in 10 minutes · most of the balance moved at once · large transfer to a new receiver · big amount at night. Each alert states exactly why it was raised. Alerts never block money; staff mark them *Reviewed* or *Dismissed*.

**Safety design**
- The assistant cannot move money or change anything; it only explains. It never asks for passwords/OTPs and refuses off-topic requests.
- Balances are **not** sent to the AI unless you set `ASSISTANT_SHARE_BALANCES=true`.
- Summaries send only monthly totals, and the staff tools send only anonymised statistics (no names, phone numbers, ID numbers or account numbers).
- Every AI endpoint is same-origin only, rate-limited per user/IP, and whitelists the language. No `GROQ_API_KEY` means the AI features politely switch off while everything else keeps working.
- Voice input needs a browser with speech recognition (Chrome, Edge, Android Chrome). Firefox shows typing only. Voice quality for some languages depends on the device's installed voices.
- AI output can be wrong: staff text is labelled *review before use*, and customer numbers are always computed by the server, never by the AI.

---

## 🌐 Deployment checklist

- [ ] Serve over **HTTPS**
- [ ] `APP_ENV=production` (hides error details)
- [ ] Use a dedicated DB user with a strong password, not `root`
- [ ] Change default admin/staff passwords; don't load `demo_data.sql` in production
- [ ] Back up the database regularly
- [ ] Keep PHP and MariaDB/MySQL updated
- [ ] If behind a reverse proxy, make sure it forwards `X-Forwarded-Proto`

---

## 🗂 Project structure

```
index.php                 Public landing page
database/                 schema.sql, demo_data.sql, credit-simulation-migration.sql, card-management-migration.sql, card-archive-migration.sql
bin/                      CLI tools (admin password recovery)
InternetBanking/
  core/                   bootstrap.php (config, security, auth) · money.php (transactions) · fraud.php · ai.php · summary.php · credit.php · cards.php
  api/                    translate.php, assistant.php, summary.php, staff_ai.php (Groq endpoints)
  assets/                 Shared theme + JS: rb-theme.css, rb-i18n.js (languages), rb-assistant.js (chat + voice)
  admin/ staff/ client/   The three portals; staff/admin review demo card requests
Dockerfile · docker-compose.yml · .htaccess · .env.example
```

---

## ⚠️ Honest notes

- This is a learning/demo-grade banking application. Before handling **real money or real customer data** it needs an independent security review, regulatory compliance work (KYC, audit trails, RBI/local rules), backups and monitoring.
- Interest figures shown on balance sheets are simple illustrations, not a compounding engine.
- Cash deposits are recorded by staff only; customers cannot credit their own accounts.

## 📈 Ideas for the future

SMS/email alerts · UPI integration · loan application and repayment ledger · Aadhaar e-KYC · PDF statements

---

## 📜 License & credits

Copyright © 2026 Soumya Das. See [LICENSE](LICENSE) and [NOTICE](NOTICE). The project started from the open-source *iBanking* system by Martin Mbithi; that origin is credited in `NOTICE`.
