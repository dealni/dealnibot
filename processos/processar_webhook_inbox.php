<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inclusos/Telegram.php';
require_once __DIR__ . '/../inclusos/dados_bot.php';
require_once __DIR__ . '/../inclusos/bdTelegram.php';
require_once __DIR__ . '/../inclusos/funcoes.php';
require_once __DIR__ . '/../inclusos/funcoes_webhook_inbox.php';
require_once __DIR__ . '/funcoes_processar_webhook_inbox.php';

iniciarLoggerCli();
$telegram = new Telegram(TOKEN_BOT);
try {
    $bd = new bdTelegram();
    $telegram->acompanharContatos($bd);
} catch (Throwable $e) {
    ecoarEEncerrar($e->getMessage());
}
$redis = conectarRedis();
$resultado = drenarInboxWebhook($bd, $telegram, $redis);
$limpeza = limparInboxWebhookRetida($bd, time());

echo "Inbox: {$resultado['processados']} processado(s), {$resultado['falhas']} falha(s); "
    . "retenção: {$limpeza['processados']} processado(s), {$limpeza['falhas']} falho(s).\n";

if ($resultado['falhas'] > 0) {
    fwrite(STDERR, "Inbox: houve falha operacional; retentativas preservadas.\n");
    exit(1);
}
