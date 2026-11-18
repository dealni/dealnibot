CREATE INDEX IF NOT EXISTS idx_webhook_inbox_retencao_processados
    ON webhook_inbox (processado_em, update_id) WHERE status = 'processado';
CREATE INDEX IF NOT EXISTS idx_webhook_inbox_retencao_erros
    ON webhook_inbox (recebido_em, update_id) WHERE status = 'erro';
