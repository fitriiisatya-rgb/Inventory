-- Optional reference seed data. Run after database/schema.sql (or
-- `php bin/migrate.php`). The SUPERADMIN account is deliberately NOT
-- created here — no password is ever hardcoded into a SQL file.
-- Run instead: php bin/create_admin.php <username> <password> "<Full Name>"

INSERT INTO locations (code, name, status) VALUES
    ('MAIN', 'Gudang Utama', 'ACTIVE');

INSERT INTO categories (code, name, status) VALUES
    ('BAHAN-BAKU', 'Bahan Baku', 'ACTIVE'),
    ('KEMASAN', 'Kemasan', 'ACTIVE'),
    ('LAIN', 'Lain-lain', 'ACTIVE');
