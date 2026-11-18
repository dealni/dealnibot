<?php

declare(strict_types=1);

require_once __DIR__ . '/../inclusos/limites_requisicoes.php';
require_once __DIR__ . '/funcoes_ver_audio.php';
// Só fiação: a guarda de contexto e a chamada. O fluxo inteiro mora em
// executarComandoVerAudio(), no irmão — script de topo executa no include e
// nenhum teste pode incluí-lo, então código aqui é código sem cobertura.
//
// A função encerra os fluxos com `return`, nunca `exit`: em bot.php o roteador
// roda dentro do laço sobre o lote de updates já confirmado no Telegram, e
// encerrar o processo descartaria em silêncio as demais mensagens do lote.
// Ver InfraRoteadorPollingTest e ComandosMidiaTerminadoresTest.
if (!isset($telegram, $redis) || !$telegram instanceof Telegram || !$redis instanceof Redis) {
    throw new LogicException('Contexto inválido para o comando de áudio.');
}

executarComandoVerAudio($telegram, $redis);
