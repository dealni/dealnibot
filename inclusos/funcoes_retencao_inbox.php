<?php

declare(strict_types=1);

/** @return array{processados: int, falhas: int} */
function limparInboxWebhookRetida(bdTelegram $bd, int $agora, int $tamanhoLote = 1000, int $maximoLotes = 1000): array
{
    if ($tamanhoLote < 1 || $tamanhoLote > 1000 || $maximoLotes < 1 || $maximoLotes > 1000) {
        throw new InvalidArgumentException('Limites de retenção fora da faixa de 1 a 1000.');
    }
    $fim = microtime(true) + 20;
    return [
        'processados' => limparLotesDaInbox($bd, true, $agora - 7 * 86400, $tamanhoLote, $maximoLotes, $fim),
        'falhas' => limparLotesDaInbox($bd, false, $agora - 30 * 86400, $tamanhoLote, $maximoLotes, $fim),
    ];
}

function limparLotesDaInbox(bdTelegram $bd, bool $processados, int $limite, int $tamanhoLote, int $maximoLotes, float $fim): int
{
    // SQLs fixos, apoiados por índices parciais diferentes. Cada lote confirma
    // sozinho; SKIP LOCKED preserva updates em uso sem parar o recebimento.
    $sqlProcessados = <<<'SQL'
        WITH vencidos AS (
            SELECT update_id FROM webhook_inbox
            WHERE status = 'processado' AND processado_em < :limite
            ORDER BY processado_em, update_id LIMIT :lote FOR UPDATE SKIP LOCKED
        ) DELETE FROM webhook_inbox AS inbox USING vencidos
        WHERE inbox.update_id = vencidos.update_id
        SQL;
    $sqlFalhas = <<<'SQL'
        WITH vencidos AS (
            SELECT update_id FROM webhook_inbox
            WHERE status = 'erro' AND recebido_em < :limite
                AND entrega_ia_estado IS DISTINCT FROM 'pendente'
            ORDER BY recebido_em, update_id LIMIT :lote FOR UPDATE SKIP LOCKED
        ) DELETE FROM webhook_inbox AS inbox USING vencidos
        WHERE inbox.update_id = vencidos.update_id
        SQL;
    $sql = $processados ? $sqlProcessados : $sqlFalhas;
    $total = 0;
    for ($lote = 0; $lote < $maximoLotes && microtime(true) < $fim; $lote++) {
        $consulta = $bd->queryExec($sql, [':limite' => $limite, ':lote' => $tamanhoLote]);
        if (!$consulta instanceof PDOStatement) {
            throw new RuntimeException('Falha na retenção da inbox; lotes já concluídos permanecem confirmados.');
        }
        $removidos = $consulta->rowCount();
        $total += $removidos;
        if ($removidos < $tamanhoLote) {
            break;
        }
    }
    return $total;
}
