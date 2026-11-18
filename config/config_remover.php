<?php

declare(strict_types=1);

/**
 * Remove o webhook desta instalação no Telegram (deleteWebhook).
 *
 * NUNCA inclua nem rode este arquivo a partir de um teste: incluí-lo REMOVE o
 * webhook de verdade e o bot para de receber updates até alguém rodar
 * config/config_webhook.php de novo.
 *
 * Toda a interpretação da resposta mora em config_remover_resultado(), no
 * irmão funcoes_config_remover.php — que os testes carregam com require_once
 * de verdade, sem executar uma única linha do topo deste arquivo.
 */

require_once __DIR__ . '/funcoes_config_remover.php';

// Carrega TOKEN_BOT.
require __DIR__ . '/../inclusos/dados_bot.php';

$url = 'https://api.telegram.org/bot' . TOKEN_BOT . '/deleteWebhook';

['response' => $response, 'httpCode' => $httpCode, 'erro_curl' => $erro_curl] = config_telegram_requisicao($url);

$resultado = config_remover_resultado($response, $erro_curl, $httpCode);
if ($resultado['codigo'] === 0) {
    echo $resultado['saida'];
} else {
    fwrite(STDERR, $resultado['saida']);
}
exit($resultado['codigo']);
