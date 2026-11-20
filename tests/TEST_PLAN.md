# FindSync test plan and actual results

**Test date:** 2026-11-21  
**Application:** FindSync PHP 8.4 / MySQL (XAMPP MariaDB/MySQL service)  
**Server used for HTTP tests:** PHP built-in server from the project root: `php -S 127.0.0.1:8000 -t .`  
**Application URL used:** `http://127.0.0.1:8000/public/`  
**API base used:** `http://127.0.0.1:8000/public/api/index.php?route=...`

This file records **actual observed results**, not intended behavior. All records and accounts used are fictional demo data.

---

## 1. Static checks

| Check | Command | Result |
|---|---|---|
| PHP syntax | `Get-ChildItem -Recurse -Filter *.php -Path app,public \| ForEach-Object { php -l $_.FullName }` | **PASS** — no syntax errors in any PHP file |
| JavaScript syntax | `node --check public/assets/js/app.js` | **PASS** |
| Static assets over HTTP | `curl.exe -s -o NUL -w '%{http_code}' http://127.0.0.1:8000/public/` plus `/public/assets/css/style.css`, `/public/assets/js/app.js`, `/samples/sample_found.csv` | **PASS** — all returned `200` |
| Page title | Fetched `/public/` and inspected `<title>` | **PASS** — `FindSync · Campus Lost & Found` |

---

## 2. Database setup

| Check | Command / observation | Result |
|---|---|---|
| Schema import | `cmd /c "C:\xampp\mysql\bin\mysql.exe -u root < ...\database\schema.sql"` | **PASS** — no errors |
| Seed import | `cmd /c "C:\xampp\mysql\bin\mysql.exe -u root findsync_db < ...\database\seed.sql"` | **PASS** — no errors |
| Clean seed counts | Direct SQL count | **PASS** — `students=3`, `users=4`, `lost_reports=1`, `found_items=1`, `claim_requests=0`, `releases=0`, `found_claim_locks=0` |

> Note: PowerShell does not accept raw `<` redirection. The README was corrected to use `cmd /c` for both MySQL import commands.

---

## 3. Authentication, sessions, and CSRF

| Check | Observed result | Result |
|---|---|---|
| Staff login `admin` / `Admin@12345` | `{"success":true,... "role":"staff"}` | **PASS** |
| Student login `ana` / `Student@12345` | `{"success":true,... "role":"student","student_id":1}` | **PASS** |
| Login with malformed/non-JSON body | HTTP `422`, `VALIDATION_ERROR` | **PASS** |
| Unauthenticated protected endpoint | HTTP `401`, `UNAUTHENTICATED` | **PASS** |
| Write without `X-CSRF-Token` while authenticated | HTTP `403` for `POST lost` and `POST import` | **PASS** |
| CSRF token route with URL-encoded slash (`auth%2Fcsrf`) | Token issued; encoded `auth%2Flogin` then succeeded | **PASS** |
| Session cookie reuse across requests | Authenticated cookie jar retained access across all subsequent API calls | **PASS** |
| Session ID regeneration | Implemented in `AuthService`; not independently instrumented at cookie level | **NOT DIRECTLY INSTRUMENTED** |

---

## 4. Role protection and per-record authorization

| Check | Observed result | Result |
|---|---|---|
| Student accessing staff dashboard | HTTP `403` | **PASS** |
| Student accessing student registry | HTTP `403` | **PASS** |
| Student accessing imports / audit / export | HTTP `403` for `GET imports`, `GET audit`, `GET reports/export` | **PASS** |
| Student attempting to claim another student's lost report | `FORBIDDEN`: “You may only claim using your own lost report.” | **PASS** |
| Student submitting a lost report with spoofed `student_id: 3` while logged in as Ana | Created report had `student_id: 1` (session identity) | **PASS** |
| Student match route | Returned only the logged-in student's open lost reports | **PASS** |

---

## 5. Matching and privacy

| Check | Observed result | Result |
|---|---|---|
| Staff match generation | Returned 91-point pair for seed wallet, with reasons: same category, same location, shared keywords, dates within 7 days | **PASS** |
| Student match keys | Only `category, description_preview, event_date, found_item_id, lost_report_id, reasons, score` | **PASS** |
| Excluded from student matches | No `finder_student_id`, `finder_number`, `finder_name`, full found description, evidence, or staff notes | **PASS** |
| Staff match fields | Includes finder identity and both descriptions for staff review | **PASS** |
| Deterministic rule policy | Documented and implemented: same category required; 45 + 20 + up to 25 + 10; threshold 60; found more than 7 days before loss excluded | **PASS** |
| No automatic release on score | Match endpoints only read; release required separate authenticated staff write | **PASS** |

---

## 6. Registration and validation

| Check | Observed result | Result |
|---|---|---|
| Registration with client `role: "staff"` | Created account with `role: "student"` and a new fictional registry row | **PASS** |
| Duplicate registration | HTTP `422` | **PASS** |
| Staff creating lost report with unknown `student_id: 999` | HTTP `404` | **PASS** |
| Invalid date `2026-02-30` | HTTP `422` | **PASS** |
| Exact duplicate lost report | HTTP `422` | **PASS** |
| One active claim per found item | Second claim on same found item returned `CLAIM_ACTIVE` | **PASS** |

---

## 7. Claim lifecycle and atomic release

| Check | Observed result | Result |
|---|---|---|
| Student claim with private evidence | Claim created as `pending`; evidence returned only to the requester/staff claim views | **PASS** |
| Staff approve | Claim became `approved`; lost/found statuses unchanged | **PASS** |
| Release without `confirm: true` | HTTP `422` | **PASS** |
| Staff confirmed release | Claim `released`; one `releases` row; lost `resolved`; found `claimed` | **PASS** |
| Direct SQL after release | `claim_status=released`, `lost_status=resolved`, `found_status=claimed`, `releases=1`, `active_locks=0` | **PASS** |
| Duplicate release | HTTP `409` | **PASS** |
| Reject flow | Claim became `rejected`, lock removed, lost/found stayed `open`/`available`, and a later claim on the same found item succeeded | **PASS** |
| Concurrent simultaneous release | Not executed as a true parallel stress test; implementation uses `SELECT ... FOR UPDATE`, status rechecks, unique constraints, and maps duplicate/deadlock errors to `409` | **PARTIALLY TESTED** |

Two defects were found and fixed during this testing:
1. `ClaimController::index()` called a non-static repository method statically, producing HTTP `500` after a claim existed. Fixed to instantiate `ClaimRepository`.
2. A released claim left its `found_claim_locks` row behind. Release now deletes the lock in the same transaction; direct SQL confirmed `active_locks=0`.

---

## 8. CSV ETL

| Check | Observed result | Result |
|---|---|---|
| Valid `samples/sample_found.csv` import | HTTP `201`; `total_rows=3`, `accepted_rows=3`, `rejected_rows=0` | **PASS** |
| Quoted comma field | `"Silver keyring, three house keys"` imported as one description | **PASS** |
| Invalid sample import | HTTP `201`; `total_rows=5`, `accepted_rows=1`, `rejected_rows=4` | **PASS** |
| Row-level reasons | Row 2 unknown student ID; row 3 invalid date (`YYYY-MM-DD`, not in the future); row 4 invalid category; row 5 missing description | **PASS** |
| Import history | Two batches listed with `error_count` 0 and 4 | **PASS** |
| UTF-8 BOM and shuffled header order | BOM-prefixed CSV with columns `date,description,studentId,location,category` imported successfully | **PASS** |
| Authenticated import without CSRF token | HTTP `403` | **PASS** |
| Student accessing `GET imports` | HTTP `403` | **PASS** |
| Audit entries for imports | Two `ETL_IMPORT` actions with batch IDs | **PASS** |

---

## 9. CSV export

| Check | Observed result | Result |
|---|---|---|
| Staff `GET reports/export` | HTTP `200`, `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="findsync_unified_report_....csv"` | **PASS** |
| UTF-8 BOM | First bytes `EF BB BF` | **PASS** |
| Headers | `Source,"Record ID","Student ID",Category,Description,Location,Date,Status` | **PASS** |
| Quoted field | `Black leather wallet` exported quoted correctly | **PASS** |
| Formula injection | Description `=1+1 spreadsheet formula test` exported as `'=1+1 spreadsheet formula test` | **PASS** |
| No PHP warnings in CSV | No `Deprecated` text after fix | **PASS** |
| Student accessing export | HTTP `403` | **PASS** |

A PHP 8.4 defect was found and fixed: `fputcsv()` emitted deprecation warnings into the CSV body because the escape argument was omitted. All `fputcsv()` calls now pass the escape argument explicitly.

---

## 10. Dashboards, audit, and persistence

| Check | Observed result | Result |
|---|---|---|
| Staff dashboard before claim | `open_lost=1`, `available_found=1`, `candidate_matches=1`, `completed_claims=0` | **PASS** |
| Staff dashboard after release | `open_lost=0`, `available_found=0`, `completed_claims=1` | **PASS** |
| Student dashboard | Returned only Ana's open lost report and zero claims before claiming | **PASS** |
| Audit actions observed | `LOGIN`, `LOST_REPORT_CREATED`, `CLAIM_REQUESTED`, `CLAIM_APPROVED`, `CLAIM_RELEASED`, `CLAIM_REJECTED`, `ETL_IMPORT`, `STUDENT_REGISTERED` | **PASS** |
| Sensitive fields in audit | Audit details contained IDs/actions only; no ownership evidence or staff notes | **PASS** |
| Persistence | Data remained queryable via MySQL and subsequent API requests after import/release; server-side state replaced browser localStorage | **PASS** |

---

## 11. Independent security review follow-up

An independent read-only review was run after the first full test pass. Its results were triaged as follows.

| Review finding | Disposition |
|---|---|
| High: CSV import contract mismatch causes 500 on every row | **NOT REPRODUCIBLE / stale review source.** The current `EtlService::validateRow()` returns `['reasons' => ..., 'record' => ...]` and repeated live imports after the review succeeded: valid sample `3/3 accepted`, invalid sample `1 accepted / 4 rejected`, BOM/shuffled headers accepted. No code change needed. |
| Medium: multiple active claims can use the same lost report | **FIXED.** Added `ClaimRepository::existsActiveClaimForLost()` and a transaction check in `ClaimService::create()`. Regression: second claim on the same lost report returned `CLAIM_ACTIVE`. |
| Medium: approve/reject read-modify-write race | **FIXED.** `approve()` and `reject()` now lock the claim with `SELECT ... FOR UPDATE` inside the transaction. `updateStatus()` supports an expected-status guard; `release()` also uses it. Regression: second approve returned `409`; reject after release returned `409`. True parallel load was not run. |
| Low: CSRF token not rotated at login | **FIXED.** `AuthService::login()` rotates the CSRF token after `session_regenerate_id(true)`. Regression: token before login differed from token after login; frontend already refetches `auth/csrf` after login. |
| Low: stale `found_claim_locks` row after release | **FIXED before the review completed.** Release deletes the lock in the same transaction. Direct SQL after the final regression showed `releases=1`, `active_locks=0`. |
| Low: student match reasons contain finder-description keywords | **FIXED.** Student reasons are now fixed codes (`same_category`, `same_location`, `shared_keywords`, `dates_near`); staff keep detailed reasons. Regression response contained exactly those four codes. |
| Low: TOCTOU on registration/report duplicate checks | **ACCEPTED RESIDUAL RISK** for this local demo. Sequential duplicate checks were verified (duplicate registration/report returned `422`); a true simultaneous insert race was not stress-tested. |
| Low: no upper bound on `event_date` | **FIXED.** Added `valid_event_date()` and applied it to lost/found web forms and CSV rows. Regression: `2099-01-01` returned `422`. |

---

## 12. Not run / limitations

- **No browser click-through automation** was executed. The UI files were syntax-checked and served over HTTP with `200` responses; interactive navigation should be manually confirmed in a browser.
- **Apache inside XAMPP** was not used for the HTTP tests; the PHP built-in server was used against XAMPP's MySQL. XAMPP launch instructions are in `README.md`.
- **True simultaneous concurrency** was not load-tested. Sequential duplicate release, row locks, status rechecks, unique constraints, and stale-status conflicts were verified.
- **Simultaneous duplicate username/student/report insert races** were not stress-tested. Sequential checks and schema unique keys for users/students were verified; a DB-level unique index for lost/found deduplication was not added.
- **No real SIS/LMS, SSO, email, ML, image recognition, or compliance certification** is implemented or claimed.

---

## 13. Demonstration script (fictional demo data)

1. Reset the demo database using the two `cmd /c` MySQL commands in `README.md`.
2. Start Apache and MySQL in XAMPP, then open `http://localhost/FindSync/public/`.
3. Sign in as student `ana` / `Student@12345`.
4. Open **Matches**: observe the redacted wallet match and the heuristic score explanation.
5. Open **Lost reports** and try to submit a report with another student's ID in the payload; the saved record remains Ana's.
6. Submit a claim from the redacted match with ownership evidence.
7. Sign out; sign in as staff `admin` / `Admin@12345`.
8. Open **Matches** to see full staff-only details, then **Claims**.
9. Approve the claim. Confirm the item is *not* released yet.
10. Use **Release** and confirm explicitly. Verify lost becomes `resolved` and found becomes `claimed`.
11. Try releasing the same claim again: expect HTTP `409`.
12. Open **CSV import**, upload `samples/sample_found.csv`, then `samples/sample_invalid.csv`; review accepted/rejected rows.
13. Open **Export** and confirm the downloaded CSV has a UTF-8 BOM and no spreadsheet formula execution.
14. Open **Audit** and confirm actions are logged without ownership evidence.
15. Reload the page and sign in again; all state is still present in MySQL.

---

## 14. Final state

After verification, the database was re-imported from `database/schema.sql` and `database/seed.sql` so the delivered demo starts with the clean fictional seed dataset.
