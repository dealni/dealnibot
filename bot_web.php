<?php

declare(strict_types=1);

// ============================================================================
// bot_web.php — webhook (produção). Esqueleto canônico da família.
//
// PONTOS CANÔNICOS OBRIGATÓRIOS (não alterar sem decisão nova):
//   1. ordem dos require, com vendor/autoload.php PRIMEIRO;
//   2. verificação do secret do webhook — NÃO MEXER;
//   3. `new bdTelegram()` dentro de try/catch (o construtor canônico lança
//      RuntimeException em vez de die());
//   4. bloco de resposta rápida ao Telegram aplicado a TODO update;
//   5. as variáveis de contexto ($dados_recebidos, $texto_recebido,
//      $tipo_de_update, $id_do_chat_atual) NÃO são preenchidas aqui: o
//      roteador.php as atribui ele mesmo no topo;
//   6. abertura do request_id ANTES da verificação do secret (contrato de
//      observabilidade, seção 3) — ver o comentário no bloco correspondente.
// ============================================================================

// Início do atendimento, no relógio monotônico: é a base do `duration_ms` do
// evento que fecha o update (registrarFimDoAtendimentoWebhook()).
$inicioDoAtendimento = hrtime(true);

// Inclui os arquivos necessários que contêm as classes e funções usadas no código
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/inclusos/Telegram.php';
require_once __DIR__ . '/inclusos/dados_bot.php';
require_once __DIR__ . '/inclusos/bdTelegram.php';
require_once __DIR__ . '/inclusos/funcoes.php';
require_once __DIR__ . '/inclusos/funcoes_webhook.php';
require_once __DIR__ . '/inclusos/funcoes_webhook_inbox.php';

// Ciclo de log correlacionado: fixa o request_id e ecoa o header ANTES do 403
// de origem — recusa sem correlação é recusa que ninguém consegue explicar.
require_once __DIR__ . '/inclusos/ciclo_web.php';

if (!webhookTelegramAutorizado(
    defined('TELEGRAM_WEBHOOK_SECRET') ? constant('TELEGRAM_WEBHOOK_SECRET') : null,
    $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null
)) {
    http_response_code(403);
    exit;
}

$telegram = new Telegram(TOKEN_BOT);

// Banco de dados. O construtor lança em falha de conexão (já registrou o erro
// no app.log, canal `bd`); aqui só resta devolver a mensagem e encerrar.
try {
    $bd = new bdTelegram();
    $telegram->acompanharContatos($bd);
} catch (Throwable $e) {
    ecoarEEncerrar($e->getMessage());
}

// Redis para cache e controle de estado temporário.
$redis = conectarRedis();

// O payload precisa estar durável antes do ACK. Reentregas são absorvidas pela
// chave primária e falhas posteriores ficam disponíveis para o worker.
$dadosWebhook = $telegram->getData();
$updateIdWebhook = $dadosWebhook['update_id'] ?? null;
if (!is_int($updateIdWebhook)) {
    http_response_code(400);
    exit;
}
if (!registrarUpdateWebhook($bd, $dadosWebhook, time())) {
    http_response_code(503);
    exit;
}

// Responde imediatamente ao Telegram para evitar reenvio enquanto o comando
// consulta banco, Redis ou APIs externas.
header("Connection: close");
header("Content-Encoding: none");
ob_start(); // Iniciar o buffer de saída
$telegram->respondSuccess(); // Responder ao Telegram
$size = ob_get_length(); // Obter o tamanho do buffer
header("Content-Length: $size");
ob_end_flush(); // Enviar a saída para o cliente
flush(); // Forçar o PHP a enviar a saída para o navegador

// Sob php-fpm o par "Connection: close" + flush() NÃO encerra a requisição: o
// Telegram só recebe o 200 quando o script termina, e até lá ele REENVIA o
// mesmo update — o efeito que este bloco existe justamente para evitar
// (comando processado duas vezes = resposta repetida e chamada paga à OpenAI
// cobrada de novo). Só fastcgi_finish_request() devolve a resposta de fato.
// Sob CLI ou outro SAPI a função não existe e nada muda.
//
// O bloco vale para TODO tipo de update, callback_query inclusive: um clique
// em botão também é reenviado pelo Telegram quando a resposta demora.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Processa exatamente a entrada recebida. Exceção ou erro fatal é reagendado;
// `exit` legítimo dos comandos é confirmado por um shutdown handler.
$desfechoDoUpdate = processarProximoUpdateWebhook($bd, $telegram, $redis, updateId: $updateIdWebhook);
registrarFimDoAtendimentoWebhook($updateIdWebhook, $desfechoDoUpdate, $inicioDoAtendimento);
