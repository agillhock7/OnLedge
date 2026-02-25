-- OnLedge initial schema (PostgreSQL)

-- Shared hosting may not allow pgcrypto/uuid-ossp extensions.
-- Use a local UUIDv4-like generator that does not require extensions.
CREATE OR REPLACE FUNCTION onledge_uuid_v4() RETURNS UUID AS $$
SELECT (
  substr(md5(random()::text || clock_timestamp()::text), 1, 8) || '-' ||
  substr(md5(random()::text || clock_timestamp()::text), 9, 4) || '-4' ||
  substr(md5(random()::text || clock_timestamp()::text), 14, 3) || '-' ||
  substr('89ab', (floor(random() * 4)::int + 1), 1) ||
  substr(md5(random()::text || clock_timestamp()::text), 18, 3) || '-' ||
  substr(md5(random()::text || clock_timestamp()::text), 21, 12)
)::uuid;
$$ LANGUAGE SQL VOLATILE;

CREATE TABLE IF NOT EXISTS users (
  id BIGSERIAL PRIMARY KEY,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS password_resets (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL,
  expires_at TIMESTAMPTZ NOT NULL,
  used_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS receipts (
  id UUID PRIMARY KEY DEFAULT onledge_uuid_v4(),
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  merchant TEXT,
  merchant_address TEXT,
  receipt_number TEXT,
  total NUMERIC(12,2),
  currency TEXT NOT NULL DEFAULT 'USD',
  purchased_at DATE,
  purchased_time TEXT,
  subtotal NUMERIC(12,2),
  tax NUMERIC(12,2),
  tip NUMERIC(12,2),
  notes TEXT,
  raw_text TEXT,
  category TEXT,
  tags TEXT[] NOT NULL DEFAULT '{}',
  file_path TEXT,
  line_items JSONB NOT NULL DEFAULT '[]'::jsonb,
  payment_method TEXT,
  payment_last4 TEXT,
  ai_confidence NUMERIC(6,4),
  ai_model TEXT,
  processed_at TIMESTAMPTZ,
  processing_explanation JSONB NOT NULL DEFAULT '[]'::jsonb,
  search_vector TSVECTOR,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS rules (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  priority INT NOT NULL DEFAULT 100,
  conditions JSONB NOT NULL DEFAULT '{}'::jsonb,
  actions JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_receipts_user_created ON receipts (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_rules_user_priority ON rules (user_id, priority ASC, id ASC);
CREATE INDEX IF NOT EXISTS idx_receipts_search_vector ON receipts USING GIN (search_vector);

CREATE OR REPLACE FUNCTION set_updated_at() RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION receipts_search_vector_update() RETURNS TRIGGER AS $$
BEGIN
  NEW.search_vector :=
    setweight(to_tsvector('simple', COALESCE(NEW.merchant, '')), 'A') ||
    setweight(to_tsvector('simple', COALESCE(NEW.notes, '')), 'B') ||
    setweight(to_tsvector('simple', COALESCE(NEW.raw_text, '')), 'B') ||
    setweight(to_tsvector('simple', COALESCE(NEW.category, '')), 'C');
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_receipts_updated_at ON receipts;
CREATE TRIGGER trg_receipts_updated_at
BEFORE UPDATE ON receipts
FOR EACH ROW
EXECUTE PROCEDURE set_updated_at();

DROP TRIGGER IF EXISTS trg_rules_updated_at ON rules;
CREATE TRIGGER trg_rules_updated_at
BEFORE UPDATE ON rules
FOR EACH ROW
EXECUTE PROCEDURE set_updated_at();

DROP TRIGGER IF EXISTS trg_receipts_search_vector_update ON receipts;
CREATE TRIGGER trg_receipts_search_vector_update
BEFORE INSERT OR UPDATE ON receipts
FOR EACH ROW
EXECUTE PROCEDURE receipts_search_vector_update();
