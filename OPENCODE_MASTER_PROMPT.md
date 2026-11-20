# OpenCode AI — Master implementation prompt

You are a senior full-stack developer. Build a complete, working, local-first **FindSync — Smart Campus Lost & Found Data Integration System** from the starter project and the accompanying `README.md` specification.

## Read first
1. Read the **entire `README.md`** as the source of truth for requirements and acceptance criteria.
2. Inspect the existing `index.html`, `README.txt`, and `sample_found.csv` before changing code. The starter is a functional **browser-local prototype**, not a PHP/MySQL application.
3. The goal is an academic **Enterprise Data Management (EDM) integration demonstration**: lost reports + found records + student registry + CSV ETL + matching + verification + unified reports + audit logs.
4. This is a proposed implementation inspired by the teacher's slides, not a claim that the slides explicitly prescribe FindSync or PHP.

## Implement
- PHP 8+ backend, MySQL/MariaDB with PDO prepared statements, HTML/CSS/vanilla JS frontend, Bootstrap 5 (bundle assets if offline use is required), XAMPP compatibility.
- Modular code structure: `public/`, `app/controllers`, `app/services`, `app/repositories`, `app/config`, `database/`, `samples/`, `tests/`.
- SQL schema with foreign keys, unique claim constraints, indexes, fictional seed data.
- API endpoints for students, lost, found, dashboard, matching, claims, CSV import, audit, export.
- Input validation, canonical categories/locations, duplicate handling, date validation, escaping, CSRF safeguards, CSV formula injection protection.
- Matching: explainable deterministic scores, same category prerequisite, only open/available records, no automatic release.
- Claim workflow: student submits ownership evidence privately; staff approves/rejects, then separately confirms physical release. Release must atomically synchronize lost/found status and enforce unique allocation with transaction and DB constraints. Never auto-release on matching score.
- CSV ETL: correct quoted-field parsing, BOM, header validation, row-level accepted/rejected report, import audit metadata.
- Clear UI navigation, responsive forms, readable tables, loading/empty/error/success states.
- MANDATORY: student self-registration/login and staff login; two distinct role-protected portals with server-side session authentication, CSRF and per-record authorization. Student can submit lost/found, see own records and safe match summaries, request claims and track statuses. Staff can manage all records, approve/reject claims, separately release items, import CSV, see analytics/audit. Do not deliver admin-only app.
- `.env.example`, `README.md`, `database/schema.sql`, `database/seed.sql`, `tests/TEST_PLAN.md`, valid and invalid CSV samples.

## Workflow
1. Produce a brief file tree and implementation plan; then **write the actual files**. Do not stop at pseudocode.
2. Keep changes small and testable; do not dump everything into one `index.html`.
3. Verify syntax (`php -l` for PHP files, `node --check` for JS if available), schema/import feasibility, and key flows. If runtime services unavailable, state what cannot be tested and give manual commands.
4. Test: register/login student A → student A reports lost → student B reports found → student A sees redacted match and requests claim → staff approves and releases → status sync → CSV import (valid + invalid) → export → audit → reload persistence → confirm student cannot access staff APIs or another student’s records.
5. Update README to reflect what was **actually implemented** and exact XAMPP launch URL.
6. Give me concise step-by-step Windows/XAMPP instructions, test outcomes, remaining issues, and a demonstration script.

## Important safeguards
- Never invent screenshots, passing tests, real integration with a university SIS, or compliance certification.
- Do not use actual student information; fictional seed data only.
- Do not hardcode database passwords or expose sensitive fields in logs.
- Never trust client-side validation or UI-hidden buttons as authorization.
- No real AI/ML claim: matching is heuristic/rule-based.
- If the existing prototype has a bug, fix it rather than preserving the bug.

**Start by reading the README and existing files, then implement the complete TWO-PORTAL MVP. No admin-only shortcut.**
