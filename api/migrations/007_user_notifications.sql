-- User notification settings + delivery tracking.
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS user_notification_settings (
  user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  weekly_report_enabled BOOLEAN NOT NULL DEFAULT TRUE,
  weekly_report_last_sent_at TIMESTAMPTZ,
  welcome_email_sent_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_user_notification_settings_weekly
  ON user_notification_settings (weekly_report_enabled, weekly_report_last_sent_at);

DROP TRIGGER IF EXISTS trg_user_notification_settings_updated_at ON user_notification_settings;
CREATE TRIGGER trg_user_notification_settings_updated_at
BEFORE UPDATE ON user_notification_settings
FOR EACH ROW
EXECUTE PROCEDURE set_updated_at();

-- Existing users should not receive retroactive welcome emails.
INSERT INTO user_notification_settings (user_id, weekly_report_enabled, welcome_email_sent_at)
SELECT u.id, TRUE, NOW()
FROM users u
WHERE NOT EXISTS (
  SELECT 1
  FROM user_notification_settings s
  WHERE s.user_id = u.id
);

