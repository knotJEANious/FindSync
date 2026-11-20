# FindSync — Smart Campus Lost & Found Data Integration System

FindSync is a **local-first educational web application** for a university lost-and-found office. It consolidates fictional lost reports, found-item records, and a student registry, then uses deterministic rule-based matching to suggest candidate pairs for **human verification**. It is an Enterprise Data Management (EDM) integration demonstration, not a production identity-verification system.

> The original browser-local prototype (`index.html`) is kept only as reference. The working application is the PHP 8+ / MySQL rebuild under `public/` and `app/`.

## Implemented scope

- Student self-registration, login/logout, profile, and role-protected student portal.
- Staff login/logout and role-protected staff portal. Seeded demo staff account.
- Student lost/found submission with identity derived from the session (never from client input).
- Staff can create reports on behalf of a student, or submit an anonymous finder record.
- Per-record authorization: a student can only list/view their own reports, matches, and claims.
- Deterministic matching with same-category prerequisite, explainable score components, threshold, and found-before-loss exclusion policy.
- Redacted student match previews: no finder identity, contact, evidence, or full distinguishing description.
- Student claim requests with private ownership evidence.
- Staff approve/reject workflow with a **separate** physical release confirmation.
- Atomic release transaction with row locks and a `releases` table using unique lost/found/claim constraints to prevent double allocation.
- One active claim per found item via `found_claim_locks`.
- CSV ETL with quoted-field parsing, BOM handling, header mapping, canonical values, known-student validation, strict dates, duplicate detection, row-level rejection reasons, and import batch metadata.
- Unified CSV export with spreadsheet-formula injection neutralization.
- Live MySQL-backed dashboards, claim history, import history, and audit logs.
- Server-side validation, prepared statements, output escaping, CSRF tokens on all writes, password hashes, session ID regeneration, and hardened session cookies.
- Responsive, dependency-free CSS/JS frontend.

See `tests/TEST_PLAN.md` for actual verification outcomes.

## Explicit exclusions / honesty boundary

FindSync does **not** implement a real SIS/LMS integration, institutional SSO, FERPA/GDPR certification, external APIs, email/SMS, image recognition, machine learning, or calibrated match probabilities. Matching is heuristic and must never be treated as ownership proof. All bundled data is fictional.

## Architecture

```text
C:\xampp\htdocs\FindSync\
├─ public/
│  ├─ index.php                 # two-portal SPA shell
│  ├─ api/index.php             # query-string API router
│  └─ assets/{css,js}           # local, bundled frontend assets
├─ app/
│  ├─ bootstrap.php             # config, autoloader, session start
│  ├─ helpers.php               # shared normalization / validation helpers
│  ├─ Config/                   # .env loading and app settings
│  ├─ Database/                 # PDO connection and transaction helpers
│  ├─ Core/                     # Request, Response, Router, exceptions
│  ├─ Middleware/               # Session, CSRF, Auth, Role checks
│  ├─ Controllers/              # HTTP endpoints
│  ├─ Services/                 # Auth, Matching, ETL, Claims, Audit, CSV export
│  └─ Repositories/             # prepared-statement data access
├─ database/{schema.sql,seed.sql}
├─ samples/                     # valid/invalid CSV samples
├─ tests/                       # test plan and smoke scripts
└─ .env.example
```

### EDM concept mapping

| EDM concept | FindSync implementation |
|---|---|
| Data sources | Student registry, lost reports, found items, external CSV |
| Extract | Browser forms and CSV upload |
| Transform | Trim/collapse whitespace, canonical categories/locations, strict date checks, keyword tokenization |
| Validate / DQM | Required fields, unique IDs, foreign keys, known-student checks, duplicate rejection, row-level ETL errors |
| Load | InnoDB tables with provenance (`source`, `created_by_user_id`, timestamps) |
| API integration | JSON endpoints under `public/api/index.php` |
| Governance | Sessions, RBAC, per-record authorization, private evidence, audit log |
| Unified view | Dashboards, match review, claim queue, consolidated CSV export |
| Synchronization | Atomic release transaction updates claim + lost + found + release + audit |

## XAMPP setup (Windows)

1. Install **XAMPP with Apache, PHP 8+, and MySQL/MariaDB**.
2. Put this repository at `C:\xampp\htdocs\FindSync`. If you keep the working copy on the Desktop, a junction is enough:
   ```powershell
   cmd /c mklink /J "C:\xampp\htdocs\FindSync" "C:\Users\Name\Desktop\FindSync"
   ```
3. Start **Apache** and **MySQL** in the XAMPP Control Panel.
4. Create and seed the database:
   ```powershell
   cmd /c "C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\FindSync\database\schema.sql"
   cmd /c "C:\xampp\mysql\bin\mysql.exe -u root findsync_db < C:\xampp\htdocs\FindSync\database\seed.sql"
   ```
   PowerShell does not accept `<` input redirection directly, so use `cmd /c` as shown. If your MySQL root account has a password, add `-p` inside each quoted command and enter it when prompted.
5. Copy `.env.example` to `.env` in the project root. Adjust `DB_USER` / `DB_PASS` if needed. Leave `DB_NAME=findsync_db`.
6. Open the application:
   - **http://localhost/FindSync/public/**
7. API base URL (used internally by the frontend):
   - `http://localhost/FindSync/public/api/index.php?route=...`
8. Work through `tests/TEST_PLAN.md`.

### Optional PHP built-in server

For a quick local check without Apache, serve the **project root** so the `samples/` links resolve exactly like XAMPP:

```powershell
cd C:\xampp\htdocs\FindSync
php -S 127.0.0.1:8000 -t .
```

Then open `http://127.0.0.1:8000/public/`.

## Demo accounts (fictional, local demo only)

| Role | Username | Password |
|---|---|---|
| Staff | `admin` | `Admin@12345` |
| Student | `ana` | `Student@12345` |
| Student | `marco` | `Student@12345` |
| Student | `lia` | `Student@12345` |

`POST /api/auth/register` creates a **student-only** account plus a new fictional student registry entry in one transaction. It never accepts a role from the client. Change all demo passwords outside a local demonstration.

## Matching policy (heuristic, not probability)

Only `open` lost reports and `available` found items are compared, and category must match.

| Factor | Points |
|---|---:|
| Same category (prerequisite) | 45 |
| Same canonical location | 20 |
| Shared description keywords | up to 25 (`min(25, shared_count * 8)`) |
| Event dates within 7 days | 10 |

Pairs scoring at least `MATCH_THRESHOLD` (default 60) are shown. If a found item is dated **more than 7 days before** the loss, the pair is excluded by policy. The UI labels the score as a heuristic, not calibrated probability or proof of ownership.

## Claim state machine

```text
pending ──approve──> approved ──release (separate confirmation)──> released
   └──────────────────────reject───────────────────────────────> rejected
```

- Approval never changes item status and never records a physical release.
- Release locks the claim, lost report, and found item with `SELECT ... FOR UPDATE`, verifies statuses, inserts a unique `releases` row, updates both records, writes an audit entry, and commits atomically.
- Rejection removes the found-item claim lock so another student may submit a claim.
- Duplicate-key/deadlock failures roll back and return HTTP 409.

## API endpoints

All endpoints use `GET/POST/PATCH` against `api/index.php?route=...`. Browser writes require header `X-CSRF-Token`, obtained from `GET auth/csrf`.

| Method | Route | Access | Purpose |
|---|---|---|---|
| GET | `auth/csrf` | public | Issue CSRF token |
| POST | `auth/register` | public | Student-only registration |
| POST | `auth/login` | public | Login |
| POST | `auth/logout` | user | Logout |
| GET | `auth/me` | public | Current identity or null |
| GET | `dashboard` | staff | System-wide counts and recent activity |
| GET | `me/dashboard` | student | Student-owned counts |
| GET/POST | `students` | staff | List / add fictional students |
| GET/PATCH | `me/profile` | student | View / edit own profile |
| GET/POST | `lost` | user | List / create lost reports |
| GET/POST | `found` | user | List / create found items |
| GET | `matches` | user | Candidate matches (redacted for students) |
| GET/POST | `claims` | user | List / request claims |
| POST | `claims/{id}/approve` | staff | Approve after evidence review |
| POST | `claims/{id}/reject` | staff | Reject with reason |
| POST | `claims/{id}/release` | staff | Confirmed physical release |
| POST | `import` | staff | CSV ETL upload |
| GET | `imports` | staff | Import batch history |
| GET | `reports/export` | staff | Unified CSV download |
| GET | `audit` | staff | Audit log |

Responses use `{ "success": true, "data": ... }` or `{ "success": false, "error": { "code": "...", "message": "...", "errors": {...} } }`.

## Security notes

- Passwords use `password_hash()` / `password_verify()`.
- Session ID is regenerated on login; cookies are HttpOnly, SameSite=Lax, and Secure on HTTPS.
- CSRF tokens are session-bound, required on every `POST`/`PATCH`, and rotated at login.
- Role checks are enforced in the router and again in controllers/repositories for record ownership.
- All SQL uses PDO prepared statements. API output is JSON; UI output is escaped.
- Ownership evidence and staff notes are never written to audit logs, exports, dashboards, or match previews.
- CSV export neutralizes cells beginning with `=`, `+`, `-`, or `@`, including after leading whitespace/control characters.

## Configuration

`.env` supports:

```text
APP_NAME=FindSync
APP_TIMEZONE=Asia/Manila
APP_DEBUG=false
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=findsync_db
DB_USER=root
DB_PASS=
CSV_MAX_BYTES=2097152
MATCH_THRESHOLD=60
```

Timestamps are stored in **UTC**. The UI displays timestamps in `APP_TIMEZONE` (default `Asia/Manila`). `event_date` is a plain calendar date.

## Verification status

The two-portal application was implemented and exercised end-to-end against PHP 8.4 and XAMPP MySQL: registration/login, RBAC/CSRF, redacted matching, claim approval/rejection/atomic release, CSV import/export, dashboards, audit, and persistence. Actual pass/fail results and limitations are recorded in `tests/TEST_PLAN.md`.

Residual limitations are intentional for this local EDM demo: no browser click-through automation, no true simultaneous load test, no real SIS/SSO/email/ML integrations, and custom dependency-free responsive CSS is used instead of bundled Bootstrap.
