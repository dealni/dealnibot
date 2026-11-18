<?php

declare(strict_types=1);

// O contrato de inércia (FuncoesDeComandoSaoInertesTest) só deixa um
// funcoes_*.php exigir OUTRO funcoes_*.php irmão. Dependência de
// inclusos/ é carregada por quem tem efeito — este script.
require_once __DIR__ . '/../inclusos/limites_requisicoes.php';
require_once __DIR__ . '/funcoes_chatgpt.php';

// Só fiação: a guarda de contexto e a chamada. O fluxo inteiro mora em
// executarComandoChatgpt(), no irmão. A suíte exercita o fluxo nesse helper;
// a inclusão direta testa somente a recusa de contexto ausente, sem integrações.
//
// A função encerra os fluxos com `return`, nunca `exit`: em bot.php o roteador
// roda dentro do laço sobre o lote de updates já confirmado no Telegram, e
// encerrar o processo descartaria em silêncio as demais mensagens do lote.
// Ver InfraRoteadorPollingTest e ChatgptTerminadoresTest.
if (!isset($telegram, $redis) || !$telegram instanceof Telegram || !$redis instanceof Redis) {
    throw new LogicException('Contexto inválido para o comando chatgpt.');
}

executarComandoChatgpt($telegram, $redis);
