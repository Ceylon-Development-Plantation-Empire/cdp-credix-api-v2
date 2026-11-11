# Credix API v2

> **Enterprise Credit, Loan Origination, Servicing & Debt Management Platform (CDP)**

Credix API v2 is a core lending and credit management backend built on **Laravel 12 (PHP 8.2+)**. The system supports the complete lifecycle of microfinance, retail, and corporate credit operations—from multi-tiered customer KYC and loan origination, maker-checker approval workflows, and tokenized customer review portals to automated repayment allocation, loan restructuring, gold loan renewals, recovery agency case management, and an integrated double-entry General Ledger (GL).

---

## Technical Stack

| Layer | Technology |
|---|---|
| **Framework** | Laravel 12 (PHP >= 8.2) |
| **Authentication** | Stateless JWT (`php-open-source-saver/jwt-auth`) with Bearer & HttpOnly cookie support |
| **Two-Factor Auth** | SMS-based OTP 2FA for staff & public portals (Dialog SMS Gateway) |
| **Authorization / RBAC** | Spatie Laravel Permission with module grouping & user type separation (`admin`, `staff`, `agency`) |
| **Database** | MySQL 8.x / MariaDB (Primary), SQLite in-memory (Automated Testing) |
| **Cache, Locks & Queues** | Redis (via `phpredis`) |
| **Testing Engine** | Pest PHP v4 / PHPUnit 11 |
| **Code Standards** | Laravel Pint |

---

## System Architecture & Domain Modules

### 1. Customer 360 & KYC Management
- **Entity Identification**: Public UUID-based entity identifiers (`public_id`) protecting internal sequence IDs from enumeration.
- **Mobile KYC Verification**: Time-limited, rate-throttled SMS OTP challenge flow (`send-otp`, `resend-otp`, `verify`).
- **Financial Profile Analysis**: Full asset, liability, income, and expenditure tracking with staff verification audits.
- **Customer Bank Accounts**: Multi-bank account registry with verification states.
- **Borrower Groups**: Solidarity and joint-liability group lending support with designated chair and secretary roles.
- **CDP Connect**: External credit check and investment lookup bridge (`/v1/cdp/verify-customer`) linking CDP core accounts.

### 2. Product Catalog & Dynamic Pricing Engine
- **Product Hierarchy**: Multi-level product categories supporting standard commercial and Shariah-compliant financing structures.
- **Repayment Methods**: Configurable interest methods (flat, reducing balance, customized installments), grace periods, and compounding frequencies.
- **Charge & Fee Engine**: Dynamic fee types (percentage-based, fixed, upfront deductions, late payment penalties, early settlement charges, and collateral safekeeping fees).
- **Collateral Eligibility Rules**: Product-level collateral constraints (e.g., maximum LTV, required documentation, accepted collateral categories).
- **Real-Time Quoting**: Interactive amortization and quotation calculation endpoint (`/products/{slug}/quote`).

### 3. Loan Origination & Lifecycle State Machine
```
[Draft Application]
       │
       ▼
 [Under Review] ──(Return for Correction)──► [Draft Application]
       │
       ▼
  [Verified]
       │
       ▼
 [Approval Inbox] (Maker-Checker Gate)
  ├── Rejected ──► [Rejected]
  └── Approved
       │
       ▼
 [Offer Generation] ──(Hold / Decline)──► [Withdrawn]
       │
       ▼
 [Customer Review] (Public SMS OTP Portal)
       │
       ▼
  [Disbursement]
       │
       ▼
  [Active Loan] ──► (Servicing: Repayment / Restructure / Gold Loan Renewal)
       │
       ├── Settled / Closed
       └── Delinquent ──► [Recovery / Legal] ──► [Write-Off]
```
- **Guarantors & Co-Borrowers**: Association of secondary parties with financial underwriting.
- **Collateral & Security Perfection**: Property, vehicle, gold, and financial securities valuation, lien registration, document validation, and perfection tracking.
- **Customer Review Portal**: Tokenized, passwordless web link (`/v1/public/loan-review/{token}`) sent via SMS allowing borrowers to preview terms, verify identity via OTP, confirm acceptance, or request corrections before disbursement.
- **Document Management**: Encrypted and access-controlled file storage (`private` disk) with mime validation and verification statuses.

### 4. Servicing, Repayment & Financial Engine
- **Amortization Engine**: Dynamic generation of repayment schedules with full version tracking (`schedule-versions`) across adjustments and restructures.
- **Payment Processing**: Multi-tier repayment allocation (penalties $\rightarrow$ fees $\rightarrow$ past-due interest $\rightarrow$ current interest $\rightarrow$ principal) protected by mandatory `Idempotency-Key` HTTP headers.
- **Settlement & Payoff**: Automated payoff quote generation (`settlement-quote`) with interest rebates and early settlement fees.
- **Restructuring & Rescheduling**: Term adjustments, moratoriums, and payment holidays with real-time preview before committing.
- **Penalty Waivers**: Audit-trailed interest and penalty fee write-offs.
- **Gold Loan Renewals**: Specialized gold loan rollover engine with automated interest settlement and new pawn ticket generation.

### 5. Recovery & Non-Performing Loans (NPL)
- **Arrears Aging & Bucket Analysis**: Delinquency categorization according to standard supervisory classifications.
- **Collection Case Management**: Case assignments, field visit logs, call logs, and Promise-to-Pay (PTP) tracking.
- **Recovery Agency Portal**: Restricted-access API endpoints (`/v1/agency/recovery-cases`) scoped strictly to cases assigned to external debt collection agencies.
- **Legal Escalation**: Legal notice preparation, litigation tracking, and court document generation.
- **Write-Offs & Recovery**: Two-tier write-off workflow with approval gates, preserving post-write-off collection tracking.

### 6. General Ledger (GL) & Accounting Core
- **Double-Entry Accounting**: Real-time journal entry creation for disbursements, collections, fee accruals, write-offs, and recoveries.
- **Chart of Accounts**: Comprehensive asset, liability, equity, income, and expense account hierarchy.
- **Maker-Checker Manual Journals**: Manual adjustments routed through the approval inbox.
- **Financial Reporting**: Trial balance generation, balance sheet, profit and loss, general ledger statements, and subledger-to-GL reconciliation.
- **Automated Period Closures**: Monthly loan loss provisioning and accounting period controls.

### 7. Organization Structure & Branch Scoping
- **Organizational Hierarchy**: Countries $\rightarrow$ Provinces $\rightarrow$ Zonals $\rightarrow$ Regions $\rightarrow$ Branches $\rightarrow$ Departments $\rightarrow$ Designations $\rightarrow$ Groups.
- **Data Scoping**: Branch-isolated data boundaries preventing cross-branch customer or loan visibility unless elevated by global permissions.
- **Temporal Staff Hierarchy**: Reporting manager tracking with historical point-in-time organization chart resolution.

---

## Security & Compliance Architecture

Credix API v2 is designed around strict financial compliance and defense-in-depth security principles:

1. **Authentication & Session Freshness**:
   - Short-lived JWT bearer tokens paired with optional HttpOnly cookie exchange.
   - Staff login 2FA via SMS OTP challenge.
   - Token freshness verification (`token.current`) and forced re-auth after password rotation (`password.changed`).
2. **Access Control & User Segmentation**:
   - Granular RBAC permissions using `Spatie\Permission`.
   - Distinct user types (`admin`, `staff`, `agency`) isolated via dedicated route middleware.
   - Maker-checker segregation enforcing that the author of a loan or journal cannot approve their own submission.
3. **Financial Idempotency**:
   - All state-altering financial endpoints (e.g., payments, disbursements, renewals, recoveries) require a unique client-generated `Idempotency-Key` header to prevent double postings during network retries.
4. **Rate Limiting & Abuse Prevention**:
   - Tiered throttle limits on sensitive endpoints:
     - `throttle:login`: Authentication attempts.
     - `throttle:otp-send` / `throttle:otp-verify`: SMS verification gates.
     - `throttle:review-open` / `throttle:review-confirm`: Public loan review portal.
     - `throttle:password-reset` / `throttle:password-change`: Password workflows.
5. **Private Document Storage**:
   - Customer and loan documents are stored on a private filesystem disk (`storage/app/private`), accessible only via authenticated, streaming download endpoints (`/v1/documents/{publicId}/download`). Direct static web access is prohibited.
6. **Audit Trail**:
   - Every domain write mutation is recorded via `ActivityLogTrait`, capturing user identity, IP address, user agent, timestamps, and before/after attribute diffs.

---

## API Overview & Key Routes

All versioned routes are hosted under `/api/v1`.

### Public & Unauthenticated Endpoints
| Method | Route | Description | Throttling |
|---|---|---|---|
| `POST` | `/api/v1/login` | Staff / Admin credential login (returns JWT or triggers 2FA) | `throttle:login` |
| `POST` | `/api/v1/login/verify-otp` | 2FA verification step | `throttle:login` |
| `POST` | `/api/v1/forgot-password` | Initiates SMS OTP password reset | `throttle:password-reset` |
| `POST` | `/api/v1/reset-password` | Completes password reset using OTP | `throttle:password-reset` |
| `GET` | `/api/health-check` | Liveness health check | Default |
| `GET` | `/health` | Readiness health check with database validation | Default |

### Customer Loan Review Portal (Public with Token)
| Method | Route | Description | Throttling |
|---|---|---|---|
| `GET` | `/api/v1/public/loan-review/{token}` | View terms of proposed loan offer | `throttle:review-open` |
| `POST` | `/api/v1/public/loan-review/{token}/request-otp` | Request OTP challenge on customer phone | `throttle:review-otp` |
| `POST` | `/api/v1/public/loan-review/{token}/confirm` | Submit customer acceptance with OTP | `throttle:review-confirm` |
| `POST` | `/api/v1/public/loan-review/{token}/correction-request` | Submit customer query / correction | `throttle:review-open` |

### External Recovery Agency Portal (`user.type:agency`)
| Method | Route | Description |
|---|---|---|
| `GET` | `/api/v1/agency/recovery-cases` | List recovery cases assigned to the agency |
| `GET` | `/api/v1/agency/recovery-cases/{publicId}` | View recovery case details and debt balance |
| `POST` | `/api/v1/agency/recovery-cases/{publicId}/activities` | Log collection visit, call, or debtor interaction |

### Server-to-Server Integration (`core.key`)
| Method | Route | Description |
|---|---|---|
| `GET` | `/api/v1/external/core/policy-hold` | Core banking policy hold verification |

### Back-Office Core (`user.type:admin,staff`)
| Domain | Key Endpoints |
|---|---|
| **Identity & Access** | `/users`, `/roles`, `/permissions`, `/me`, `/password/change` |
| **Org Structure** | `/countries`, `/provinces`, `/zonals`, `/regions`, `/branches`, `/departments`, `/designations`, `/groups` |
| **Staff Directory** | `/employees`, `/employees/{id}/manager-history`, `/employees/{id}/reassign-manager` |
| **Customer 360** | `/customers`, `/customers/{id}/kyc/*`, `/customers/{id}/financials`, `/customers/{id}/bank-accounts`, `/customers/{id}/eligibility` |
| **Borrower Groups** | `/borrower-groups`, `/borrower-groups/{id}/members` |
| **Products & Fees** | `/product-categories`, `/products`, `/products/{slug}/quote`, `/charge-types`, `/collateral-types` |
| **Loan Origination** | `/loans`, `/loans/{id}/submit`, `/loans/{id}/verify`, `/loans/{id}/approve`, `/loans/{id}/collaterals`, `/loans/{id}/guarantors` |
| **Loan Servicing** | `/loans/{id}/disburse`, `/loans/{id}/schedule`, `/loans/{id}/statement`, `/loans/{id}/settlement-quote`, `/loans/{id}/settle`, `/loans/{id}/restructure` |
| **Gold Loans** | `/loans/{id}/renewal-quote`, `/loans/{id}/renew` |
| **Payments** | `/payments` (`POST` with `Idempotency-Key`), `/payments/{id}/reverse` |
| **Approvals** | `/approval-requests` (Maker-Checker inbox for loans, write-offs, manual journals, payment reversals) |
| **General Ledger** | `/gl/accounts`, `/gl/journals`, `/gl/trial-balance`, `/gl/reconciliation`, `/gl/period/close` |
| **Legal & Templates** | `/legal-document-templates`, `/legal-documents`, `/templates`, `/notification-logs` |
| **Reports & BI** | `/dashboard/overview`, `/reports/portfolio`, `/reports/arrears-aging`, `/reports/collections`, `/reports/disbursements` |
| **System Operations** | `/settings`, `/lookups`, `/import/*`, `/database/export-all-tables` |

### Consistent Response Envelope

All API endpoints return standard JSON envelopes:

```json
{
  "status": "success",
  "message": "Operation completed successfully.",
  "data": {}
}
```

Error responses adhere to:
```json
{
  "status": "error",
  "message": "Validation failed.",
  "errors": {
    "field_name": ["Specific validation error message."]
  }
}
```

---

## Scheduled Background Operations

The application includes an automated financial scheduler running under the business timezone (`Asia/Colombo`):

| Frequency | Time (Colombo) | Command | Purpose |
|---|---|---|---|
| **Daily** | `00:05` | `php artisan eod:run` | **End-of-Day (EOD)**: Daily interest accrual, installment aging, penalty calculations, status transitions. |
| **Daily** | `00:30` | `php artisan kyc:expire` | Flags expired customer KYC profiles requiring re-verification. |
| **Daily** | `01:30` | `php artisan ledger:reconcile` | Verifies that loan subledgers, GL balances, and cached values agree. Alerts on discrepancy. |
| **Monthly** | Last day `23:30` | `php artisan gl:provision` | Calculates and posts month-end loan loss provisions to the GL. |
| **Daily** | `08:00` | `php artisan notifications:reminders due` | Sends customer SMS reminders for installments due today. |
| **Daily** | `08:30` | `php artisan notifications:reminders overdue` | Sends customer SMS reminders for delinquent installments. |

---

## Local Development Setup

### Prerequisites
- PHP 8.2 or 8.3 with extensions: `pdo_mysql`, `mbstring`, `bcmath`, `curl`, `redis`, `gd`, `zip`
- Composer 2.x
- MySQL 8.x / MariaDB
- Redis Server

### Installation Steps

1. **Clone & Install Dependencies**:
   ```bash
   composer install
   ```

2. **Environment Configuration**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   php artisan jwt:secret
   ```

3. **Database Migration & Local Seeding**:
   ```bash
   # Configure your database credentials in .env first
   php artisan migrate --seed
   ```
   > **Note for Local Testing:** The local development seeder creates a default administrator:
   > - **Email:** `dev@localhost.com`
   > - **Password:** `password`

4. **Serve Application & Background Workers**:
   ```bash
   # Option A: Standard Artisan server
   php artisan serve

   # Option B: Concurrently run API, Queue, Logs, and Vite (from composer.json)
   composer run dev
   ```

5. **Run the Test Suite**:
   ```bash
   composer test
   # Or directly with Pest:
   vendor/bin/pest
   ```

---

## Production Deployment & Operations

### 1. Safe Seeding Protocol
> [!IMPORTANT]
> **NEVER run `php artisan db:seed` or `migrate:fresh --seed` in Production.**
> `DatabaseSeeder` contains development test fixtures and hardcoded demo credentials.

For production installations, run **only** the idempotent reference data seeder:
```bash
php artisan migrate --force
php artisan db:seed --class=SystemDataSeeder --force
```
`SystemDataSeeder` exclusively seeds non-destructive foundational metadata:
- Spatie permission registry and roles
- Core Chart of Accounts & GL mapping rules
- Lookup values (document types, relationship categories, bank lists)
- Default system settings and message templates

### 2. Creating the Production Administrator
To safely bootstrap the initial Super Admin account without exposing credentials in command-line arguments or shell history:
```bash
php artisan credix:create-admin <username> <email> --name="Super Administrator"
```
The command will interactively prompt for a secure password with hidden terminal input.

### 3. Server Cron & Daemon Configuration

Add the Laravel scheduler to your server crontab:
```crontab
* * * * * cd /path/to/cdp-credix-api-v2 && php artisan schedule:run >> /dev/null 2>&1
```

Configure a supervisor worker for Redis queues:
```ini
[program:credix-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/cdp-credix-api-v2/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/credix-worker.log
```

---

## Environment Variables Reference

A sanitized guide to core `.env` configurations:

| Parameter | Type / Example | Description |
|---|---|---|
| `APP_TIMEZONE` | `Asia/Colombo` | Business operations timezone. Internal database timestamps remain UTC. |
| `JWT_SECRET` | String | Secret key for signing JWT tokens (`php artisan jwt:secret`). |
| `JWT_TTL` | Integer (`1440`) | Token lifetime in minutes (default 24 hours). |
| `CORS_ALLOWED_ORIGINS` | Comma-separated URLs | Permitted frontend origins (e.g., `https://credix.example.com`). |
| `FRONTEND_URL` | URL | Web portal base URL used to construct verification and customer review links. |
| `DIALOG_SMS_SEND_URL` | URL | Dialog Sri Lanka SMS Gateway endpoint. |
| `DIALOG_SMS_USERNAME` | String | Gateway service account username. |
| `DIALOG_SMS_PASSWORD` | String | Gateway service account password. |
| `DIALOG_SMS_MASK` | String | Sender alphanumeric header mask (e.g., `CREDIX`). |
| `OTP_LOG_CODES_LOCALLY` | `true`/`false` | When `true`, OTP codes are written to application logs instead of dispatched via SMS (local testing only). |
| `CDP_CONNECT_BASE_URL` | URL | Base URL of upstream CDP Core API. |
| `CDP_CONNECT_API_KEY` | String | Bearer token for outbound requests to CDP Core. |
| `CDP_CORE_INBOUND_KEY` | String | Pre-shared token expected in `X-Core-Key` header for inbound system calls. |
| `MYSQL_DUMP_PATH` | File Path | Absolute path to `mysqldump` binary for database export utilities. |

---

## Code Quality & Engineering Standards

- **Code Formatting**: PSR-12 and Laravel styling enforced via Laravel Pint:
  ```bash
  vendor/bin/pint
  ```
- **Form Request Isolation**: All request validation rules reside strictly in `App\Http\Requests\*` classes, never inside controller actions.
- **Audit Logging**: Cross-cutting write operations must trigger `ActivityLogTrait::logActivity()`.
- **Financial Precision**: All monetary values are handled using fixed-scale string representations and `brick/math` to eliminate floating-point rounding errors.

---

## Confidentiality & License

This repository and its codebase are **strictly proprietary and confidential**. Unauthorized distribution, copying, modification, or deployment of this software via any medium is strictly prohibited without explicit written consent from the project owners.
