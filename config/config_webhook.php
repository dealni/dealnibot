<?php

declare(strict_types=1);

/**
 * Registra no Telegram o webhook desta instalação (setWebhook).
 *
 * Executar este arquivo registra o webhook do token configurado e muda o
 * destino dos updates. A URL vem de PUBLIC_BASE_URL, definida em dados_bot.php.
 *
 * Toda a interpretação da resposta mora em config_webhook_resultado(), no
 * irmão funcoes_config_webhook.php — que os testes carregam com require_once
 * de verdade, sem executar uma única linha do topo deste arquivo.
 */

require_once __DIR__ . '/funcoes_config_webhook.php';

// Carrega TOKEN_BOT, TELEGRAM_WEBHOOK_SECRET e PUBLIC_BASE_URL.
require __DIR__ . '/../inclusos/dados_bot.php';

$url = 'https://api.telegram.org/bot' . TOKEN_BOT . '/setWebhook';

// A URL pública vem de constante — nunca de literal neste arquivo.
$publicBaseUrl = defined('PUBLIC_BASE_URL') ? constant('PUBLIC_BASE_URL') : null;
if (!is_string($publicBaseUrl) || trim($publicBaseUrl) === '') {
    fwrite(STDERR, 'Configure DEALNI_PUBLIC_BASE_URL com a URL HTTPS desta instalação.' . PHP_EOL);
    exit(1);
}
$webhook_url = rtrim(trim($publicBaseUrl), '/') . '/bot_web.php';

// Campos do setWebhook. O secret_token faz o Telegram devolver o header
// X-Telegram-Bot-Api-Secret-Token em TODO update — é o que bot_web.php confere
// para provar que o update veio mesmo do Telegram (a URL é pública e toda a
// autorização sai do corpo do update). A carga da configuração exige um valor
// válido, por isso o campo é enviado em toda chamada de registro.
// Ver webhookTelegramAutorizado() em inclusos/funcoes_webhook.php.
// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
$campos = [
    'url' => $webhook_url,
    'secret_token' => constant('TELEGRAM_WEBHOOK_SECRET'),
];
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

['response' => $response, 'httpCode' => $httpCode, 'erro_curl' => $erro_curl] = config_telegram_requisicao($url, $campos);

$resultado = config_webhook_resultado($response, $erro_curl, $httpCode, $webhook_url);
if ($resultado['codigo'] === 0) {
    echo $resultado['saida'];
} else {
    fwrite(STDERR, $resultado['saida']);
}
exit($resultado['codigo']);
