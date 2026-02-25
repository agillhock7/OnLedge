-- OAuth identity linking for GitHub/Discord social login

CREATE TABLE IF NOT EXISTS oauth_identities (
  id BIGSERIAL PRIMARY KEY,
  provider TEXT NOT NULL,
  provider_user_id TEXT NOT NULL,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (provider, provider_user_id)
);

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint
    WHERE conname = 'oauth_identities_provider_check'
  ) THEN
    ALTER TABLE oauth_identities
      ADD CONSTRAINT oauth_identities_provider_check CHECK (provider IN ('github', 'discord'));
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_oauth_identities_user ON oauth_identities (user_id);

DROP TRIGGER IF EXISTS trg_oauth_identities_updated_at ON oauth_identities;
CREATE TRIGGER trg_oauth_identities_updated_at
BEFORE UPDATE ON oauth_identities
FOR EACH ROW
EXECUTE PROCEDURE set_updated_at();
