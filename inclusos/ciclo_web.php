<?php

declare(strict_types=1);

/**
 * Abre o ciclo de requisição: fixa o `request_id` e o devolve no header.
 *
 * É a primeira linha de todo entrypoint web deste bot, ANTES da checagem de
 * origem, do ACK e de qualquer `echo`. Duas razões, nesta ordem:
 *
 * 1. o contrato manda ecoar `X-Request-Id` **sempre, inclusive em erro** — e os
 *    erros interessantes daqui (403 de origem, 401 de token) acontecem nas
 *    primeiras linhas, antes de qualquer outra coisa ser carregada;
 * 2. os webhooks fecham a conexão cedo (ACK imediato). Depois do
 *    `ob_end_flush()` os headers já foram; ali não há mais o que ecoar.
 *
 * É um `require` com efeito, e não uma função, de propósito: função precisa ser
 * chamada, e "esqueci de chamar" é a falha que este arquivo evita. Incluí-lo
 * duas vezes é inofensivo — `ContextoDeExecucao` guarda o id do processo.
 *
 * Sem `vendor/` (bot legado, script solto) ele não faz nada e não reclama:
 * logar e correlacionar não podem derrubar um webhook de pagamento.
 */

if (!class_exists(\App\Support\ContextoDeExecucao::class)) {
    $autoloadDoCiclo = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoloadDoCiclo)) {
        require_once $autoloadDoCiclo;
    }
}

if (class_exists(\App\Support\ContextoDeExecucao::class)) {
    \App\Support\ContextoDeExecucao::iniciarCicloWeb();
}
