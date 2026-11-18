-- Estado de contato independente de contas, saldo e histórico financeiro.
CREATE TABLE IF NOT EXISTS telegram_contatos (
    id_telegram BIGINT PRIMARY KEY CHECK (id_telegram > 0),
    estado TEXT NOT NULL CHECK (estado IN ('disponivel', 'bloqueado', 'desativado', 'nao_encontrado', 'bot')),
    motivo TEXT,
    codigo_erro INTEGER,
    observado_em BIGINT NOT NULL CHECK (observado_em > 0),
    ultima_interacao_em BIGINT NOT NULL DEFAULT 0 CHECK (ultima_interacao_em >= 0)
);
CREATE INDEX IF NOT EXISTS idx_telegram_contatos_indisponiveis
    ON telegram_contatos (id_telegram) WHERE estado <> 'disponivel';
