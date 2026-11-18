BEGIN;

CREATE TABLE IF NOT EXISTS webhook_inbox (
    update_id BIGINT PRIMARY KEY,
    payload_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pendente',
    recebido_em INTEGER NOT NULL,
    tentativa_em INTEGER,
    disponivel_em INTEGER NOT NULL,
    processado_em INTEGER,
    tentativas INTEGER NOT NULL DEFAULT 0,
    ultimo_erro TEXT
);

CREATE INDEX IF NOT EXISTS idx_webhook_inbox_disponivel
    ON webhook_inbox (status, disponivel_em, update_id);

CREATE TABLE IF NOT EXISTS broadcast_entregas (
    campanha TEXT NOT NULL,
    id_telegram BIGINT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pendente',
    tentativa_em INTEGER,
    disponivel_em INTEGER NOT NULL,
    enviado_em INTEGER,
    tentativas INTEGER NOT NULL DEFAULT 0,
    ultimo_erro TEXT,
    PRIMARY KEY (campanha, id_telegram)
);

CREATE INDEX IF NOT EXISTS idx_broadcast_entregas_fila
    ON broadcast_entregas (campanha, status, disponivel_em, id_telegram);

COMMIT;
