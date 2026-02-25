-- Seed an initial owner account.
-- 1) Generate password hash:
--    php -r "echo password_hash('ChangeMe-Strong-Password', PASSWORD_DEFAULT), PHP_EOL;"
-- 2) Replace placeholders below.

INSERT INTO users (email, password_hash, role, is_active, is_seed)
VALUES ('owner@example.com', '$2y$10$REPLACE_WITH_PASSWORD_HASH', 'owner', TRUE, TRUE)
ON CONFLICT (email)
DO UPDATE SET
  role = EXCLUDED.role,
  is_active = TRUE,
  is_seed = TRUE,
  disabled_at = NULL;
