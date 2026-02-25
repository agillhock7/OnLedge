-- AI extraction fields for richer receipt profiles.
-- Safe to re-run.

ALTER TABLE receipts
  ADD COLUMN IF NOT EXISTS merchant_address TEXT,
  ADD COLUMN IF NOT EXISTS receipt_number TEXT,
  ADD COLUMN IF NOT EXISTS purchased_time TEXT,
  ADD COLUMN IF NOT EXISTS subtotal NUMERIC(12,2),
  ADD COLUMN IF NOT EXISTS tax NUMERIC(12,2),
  ADD COLUMN IF NOT EXISTS tip NUMERIC(12,2),
  ADD COLUMN IF NOT EXISTS line_items JSONB NOT NULL DEFAULT '[]'::jsonb,
  ADD COLUMN IF NOT EXISTS payment_method TEXT,
  ADD COLUMN IF NOT EXISTS payment_last4 TEXT,
  ADD COLUMN IF NOT EXISTS ai_confidence NUMERIC(6,4),
  ADD COLUMN IF NOT EXISTS ai_model TEXT,
  ADD COLUMN IF NOT EXISTS processed_at TIMESTAMPTZ;

