<?php

declare(strict_types=1);

require_once __DIR__ . '/funcoes_config_telegram.php';

/**
 * Interpretação da resposta do deleteWebhook, separada de
 * config/config_remover.php.
 *
 * O script irmão NÃO pode ser incluído por um teste — incluí-lo REMOVE o
 * webhook de verdade e o bot para de receber updates. Este arquivo existe para
 * que a decisão inteira (o que imprimir e com qual código sair) seja carregada
 * com um `require_once` de verdade, e não por tokenização + eval(): código
 * avaliado dentro de eval() não é atribuído a arquivo nenhum, então a linha
 * ficaria contando como descoberta mesmo com o teste passando.
 *
 * Incluir este arquivo não pode fazer NADA além de declarar a função — o
 * contrato está travado no tokenizer por
 * tests/Unit/FuncoesDeComandoSaoInertesTest.
 */

/**
 * Interpreta o que voltou do deleteWebhook: decide o que imprimir e com qual
 * código sair.
 *
 * Está em função nomeada (e não solta no topo) para que os testes exercitem
 * ESTE código, e não uma cópia dele.
 *
 * @param mixed $response Corpo devolvido pelo curl_exec (string) ou false
 * @param string $erroCurl Mensagem do curl_error, '' quando não houve erro
 * @param int $httpCode Status HTTP devolvido pela Bot API
 * @return array{saida: string, codigo: int} Texto a imprimir e status de saída
 */
function config_remover_resultado(mixed $response, string $erroCurl, int $httpCode): array
{
    return config_telegram_resultado($response, $erroCurl, $httpCode, 'remover', 'Webhook removido com sucesso.');
}
