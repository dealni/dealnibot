-- Checkpoint de entrega separado do processamento do update.
ALTER TABLE webhook_inbox ADD COLUMN IF NOT EXISTS resposta_ia_json TEXT;
ALTER TABLE webhook_inbox ADD COLUMN IF NOT EXISTS entrega_ia_estado TEXT
    CHECK (entrega_ia_estado IN ('enviando', 'pendente', 'entregue', 'indeterminada', 'recusada'));
