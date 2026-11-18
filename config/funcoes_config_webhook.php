<?php

declare(strict_types=1);

require_once __DIR__ . '/funcoes_config_telegram.php';

/**
 * Interpretação da resposta do setWebhook, separada de
 * config/config_webhook.php.
 *
 * O script irmão NÃO pode ser incluído por um teste — incluí-lo REGISTRA o
 * webhook de verdade e passa a entregar os updates de produção para a URL
 * montada lá. Este arquivo existe para que a decisão inteira (o que imprimir e
 * com qual código sair) seja carregada com um `require_once` de verdade, e não
 * por tokenização + eval(): código avaliado dentro de eval() não é atribuído a
 * arquivo nenhum, então a linha ficaria contando como descoberta mesmo com o
 * teste passando.
 *
 * Incluir este arquivo não pode fazer NADA além de declarar a função — o
 * contrato está travado no tokenizer por
 * tests/Unit/FuncoesDeComandoSaoInertesTest.
 */

/**
 * Interpreta o que voltou do setWebhook: decide o que imprimir e com qual
 * código sair.
 *
 * Está em função nomeada (e não solta no topo) para que os testes exercitem
 * ESTE código, e não uma cópia dele.
 *
 * @param mixed $response Corpo devolvido pelo curl_exec (string) ou false
 * @param string $erroCurl Mensagem do curl_error, '' quando não houve erro
 * @param int $httpCode Status HTTP devolvido pela Bot API
 * @param string $webhookUrl URL que foi registrada
 * @return array{saida: string, codigo: int} Texto a imprimir e status de saída
 */
function config_webhook_resultado(mixed $response, string $erroCurl, int $httpCode, string $webhookUrl): array
{
    return config_telegram_resultado($response, $erroCurl, $httpCode, 'configurar', "Webhook configurado com sucesso: {$webhookUrl}");
}
