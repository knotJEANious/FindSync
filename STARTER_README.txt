FindSync - Smart Campus Lost & Found Integration System

HOW TO RUN
1. Extract the ZIP.
2. Double-click index.html and open with Chrome or Edge.
3. No internet, server, npm, or database setup required.

DEMO STEPS
1. Open Dashboard: observe sample lost and found wallet records.
2. Open Smart Matching: review potential match and score.
3. Open Claim Verification: enter verification evidence and release the item.
4. Return to Dashboard: observe updated completed claim and resolved status.
5. Open Student Registry: register a new fictional student.
6. Open Lost Reports / Found Items: create records using that student ID.
7. Open ETL Import: download the sample CSV, then upload it as Found Items.
8. Review loaded and rejected rows, Audit Logs and unified CSV export.

EDM MAPPING
Extract: CSV and separate student, lost and found datasets.
Transform: Trim whitespace, normalize categories/locations, validate student IDs/dates, detect duplicates.
Load: Validated records saved to the browser's persistent localStorage.
Integration: Unified matching across lost and found sources, student validation, linked status updates upon claim.
Governance: Demonstration audit logs and staff-verification workflow (no real authentication).
Data Quality: Required-field checks, duplicate prevention, foreign-key checks, standardized values.
Outputs: Unified dashboard, matches, claims, CSV report.

LIMITATIONS
- Browser-only educational prototype; not a production system.
- No login, actual role-based access, encryption at rest, multi-user synchronization, or real university SIS integration.
- Data lives in localStorage in the current browser profile. Clearing browser storage removes it.
- Matching scores are rules-based estimates, not proof of ownership.
- Use fictional demo data only; do not enter real student personal information.
- This system is based on the Education/University case in the instructor's EDM integration slides, but the slides do not explicitly prescribe a particular implementation.
