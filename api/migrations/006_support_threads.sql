-- Threaded support conversations for OnLedge.
-- Safe to re-run.

ALTER TABLE support_tickets
  ADD COLUMN IF NOT EXISTS last_message_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  ADD COLUMN IF NOT EXISTS last_message_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS closed_at TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS support_ticket_messages (
  id BIGSERIAL PRIMARY KEY,
  ticket_id BIGINT NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
  author_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  body TEXT NOT NULL,
  is_internal BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_support_ticket_messages_ticket_created
  ON support_ticket_messages (ticket_id, created_at ASC, id ASC);
CREATE INDEX IF NOT EXISTS idx_support_ticket_messages_author_created
  ON support_ticket_messages (author_user_id, created_at DESC);

DROP TRIGGER IF EXISTS trg_support_ticket_messages_updated_at ON support_ticket_messages;
CREATE TRIGGER trg_support_ticket_messages_updated_at
BEFORE UPDATE ON support_ticket_messages
FOR EACH ROW
EXECUTE PROCEDURE set_updated_at();

-- Backfill thread history for existing tickets if no messages exist.
INSERT INTO support_ticket_messages (ticket_id, author_user_id, body, is_internal, created_at, updated_at)
SELECT
  t.id,
  t.user_id,
  COALESCE(NULLIF(trim(t.message), ''), '(ticket created)'),
  FALSE,
  t.created_at,
  COALESCE(t.updated_at, t.created_at)
FROM support_tickets t
WHERE NOT EXISTS (
  SELECT 1
  FROM support_ticket_messages m
  WHERE m.ticket_id = t.id
);

-- Align conversation metadata with latest message state.
WITH latest AS (
  SELECT DISTINCT ON (m.ticket_id)
    m.ticket_id,
    m.author_user_id,
    m.created_at
  FROM support_ticket_messages m
  ORDER BY m.ticket_id, m.created_at DESC, m.id DESC
)
UPDATE support_tickets t
SET
  last_message_at = COALESCE(latest.created_at, t.updated_at, t.created_at),
  last_message_by_user_id = latest.author_user_id,
  closed_at = CASE
    WHEN t.status IN ('resolved', 'closed') THEN COALESCE(t.closed_at, t.updated_at, t.created_at)
    ELSE NULL
  END
FROM latest
WHERE latest.ticket_id = t.id;

