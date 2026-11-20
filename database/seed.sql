-- Fictional demo data only. Demo passwords are documented in README.md and must be changed outside local demos.
USE findsync_db;

-- Password hashes generated with password_hash(..., PASSWORD_DEFAULT).
-- admin / Admin@12345
-- ana, marco, lia / Student@12345
INSERT IGNORE INTO students (student_number, full_name, program, created_at) VALUES
  ('2026-1001', 'Ana Reyes', 'BSIT', '2026-11-01 00:00:00'),
  ('2026-1002', 'Marco Santos', 'BSHM', '2026-11-01 00:00:00'),
  ('2026-1003', 'Lia Cruz', 'BSIT', '2026-11-01 00:00:00');

INSERT IGNORE INTO users (student_id, username, password_hash, role, created_at)
SELECT id, 'ana', '$2y$12$yO1BLGzUo3.kK.jYCLUdLOERk2zdQKMW14B7oTF4rrepmlHjOD45m', 'student', '2026-11-01 00:00:00'
FROM students WHERE student_number = '2026-1001';

INSERT IGNORE INTO users (student_id, username, password_hash, role, created_at)
SELECT id, 'marco', '$2y$12$yO1BLGzUo3.kK.jYCLUdLOERk2zdQKMW14B7oTF4rrepmlHjOD45m', 'student', '2026-11-01 00:00:00'
FROM students WHERE student_number = '2026-1002';

INSERT IGNORE INTO users (student_id, username, password_hash, role, created_at)
SELECT id, 'lia', '$2y$12$yO1BLGzUo3.kK.jYCLUdLOERk2zdQKMW14B7oTF4rrepmlHjOD45m', 'student', '2026-11-01 00:00:00'
FROM students WHERE student_number = '2026-1003';

INSERT IGNORE INTO users (student_id, username, password_hash, role, created_at)
VALUES (NULL, 'admin', '$2y$12$Z2qyNHhF4QiBgmb3ISZ4pu5c/d5pEReeNQ7wa.ZCLPzKvQF6f2ziu', 'staff', '2026-11-01 00:00:00');

INSERT INTO lost_reports (student_id, category, description, location, event_date, status, source, created_by_user_id, created_at, updated_at)
SELECT s.id, 'Wallet', 'Black leather wallet', 'Library', '2026-11-19', 'open', 'web', u.id, '2026-11-19 09:00:00', '2026-11-19 09:00:00'
FROM students s
JOIN users u ON u.student_id = s.id AND u.username = 'ana'
WHERE s.student_number = '2026-1001'
  AND NOT EXISTS (
    SELECT 1 FROM lost_reports l
    WHERE l.student_id = s.id AND l.description = 'Black leather wallet' AND l.event_date = '2026-11-19'
  );

INSERT INTO found_items (finder_student_id, category, description, location, event_date, status, source, created_by_user_id, created_at, updated_at)
SELECT s.id, 'Wallet', 'Black wallet with zipper', 'Library', '2026-11-20', 'available', 'web', u.id, '2026-11-20 11:30:00', '2026-11-20 11:30:00'
FROM students s
JOIN users u ON u.student_id = s.id AND u.username = 'marco'
WHERE s.student_number = '2026-1002'
  AND NOT EXISTS (
    SELECT 1 FROM found_items f
    WHERE f.finder_student_id = s.id AND f.description = 'Black wallet with zipper' AND f.event_date = '2026-11-20'
  );

INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details, created_at)
SELECT NULL, 'DEMO_SEEDED', 'database', 'findsync_db', 'Fictional students, demo accounts and seed records created', '2026-11-01 00:00:00'
WHERE NOT EXISTS (SELECT 1 FROM audit_logs WHERE action = 'DEMO_SEEDED');
