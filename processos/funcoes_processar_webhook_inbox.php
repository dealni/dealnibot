<?php

declare(strict_types=1);

/**
 * Drena a inbox dentro dos limites de tempo e de itens do cron.
 *
 * @param (callable(): int)|null $agora relógio injetável para os testes
 * @param (callable(bdTelegram, Telegram, Redis, int): string)|null $processador
 * @return array{processados: int, falhas: int}
 */
function drenarInboxWebhook(
    bdTelegram $bd,
    Telegram $telegram,
    Redis $redis,
    int $maxSegundos = 50,
    int $maxItens = 100,
    ?callable $agora = null,
    ?callable $processador = null
): array {
    $relogio = $agora ?? time(...);
    $processar = $processador ?? processarProximoUpdateWebhook(...);
    $inicio = $relogio();
    $processados = 0;
    $falhas = 0;

    while (($relogio() - $inicio) < $maxSegundos && ($processados + $falhas) < $maxItens) {
        try {
            $resultado = $processar($bd, $telegram, $redis, $relogio());
        } catch (Throwable) {
            // A fila continua intacta. Interrompe a rodada em vez de repetir
            // até 100 consultas que já falharam ou chamar isso de fila vazia.
            logarFalhaDaInbox('Falha operacional ao reservar update da inbox.', 0);
            $falhas++;
            break;
        }
        if ($resultado === 'vazio') {
            break;
        }
        $resultado === 'processado' ? $processados++ : $falhas++;
    }

    return ['processados' => $processados, 'falhas' => $falhas];
}
