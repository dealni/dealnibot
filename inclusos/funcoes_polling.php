<?php

declare(strict_types=1);

/** Erros que encerram o processo por conta própria, sem passar pelo `finally`. */
const POLLING_ERROS_FATAIS = [
    E_ERROR,
    E_PARSE,
    E_CORE_ERROR,
    E_COMPILE_ERROR,
    E_USER_ERROR,
    E_RECOVERABLE_ERROR,
];

/**
 * Decide se o encerramento do processo deve confirmar o offset já processado.
 *
 * Chamada no shutdown, quando o laço não retornou normalmente. Dois desfechos
 * chegam aqui e pedem tratamento oposto:
 *
 * - script roteado chamou `exit` (fluxo NORMAL em vários comandos): o update
 *   terminou o que tinha para fazer, então **confirma**. Sem isso o Telegram
 *   reentrega o mesmo update no restart e o efeito colateral (pedido, cobrança,
 *   mensagem) acontece de novo, indefinidamente;
 * - erro fatal no meio do update: **não confirma**, para o Telegram reentregar.
 *
 * `error_get_last()` sozinho não separa os dois — um E_WARNING de minutos atrás
 * continua lá. Só o tipo importa, e erro fatal é necessariamente o último,
 * porque é ele que encerra o processo.
 *
 * @param array{type?: int}|null $ultimoErro devolvido por error_get_last()
 */
function pollingDeveConfirmarNoEncerramento(?int $offsetProcessado, ?array $ultimoErro): bool
{
    if ($offsetProcessado === null) {
        return false;
    }

    if ($ultimoErro === null) {
        return true;
    }

    return !in_array($ultimoErro['type'] ?? null, POLLING_ERROS_FATAIS, true);
}

/**
 * Envia só o offset de confirmação, sem esperar lote novo (timeout 0).
 *
 * É a mesma segunda chamada que `getUpdates($offset, $limit, $timeout, true)`
 * faz internamente, isolada para poder rodar no encerramento do processo.
 */
function pollingConfirmarOffset(Telegram $telegram, int $offset): void
{
    $telegram->getUpdates($offset, 1, 0, false);
}

/**
 * Executa o polling continuamente com semântica "processa antes de confirmar".
 *
 * O offset só avança depois que todo o lote termina sem exceção. A chamada
 * seguinte a getUpdates() envia esse offset ao Telegram, confirmando o lote
 * anterior e aguardando o próximo na mesma requisição. Se o processo cair no
 * meio do lote, o offset maior nunca é enviado e o Telegram pode entregá-lo de
 * novo quando o bot reiniciar.
 *
 * `exit` NÃO é queda. Vários scripts roteados encerram o processo com `exit`
 * em fluxo normal (callback tratado, comando respondido), e nesses casos o
 * update foi processado até o fim. O `finally` abaixo não roda em `exit` — só
 * em retorno normal e em exceção — e é essa diferença que o encerramento usa
 * para separar "terminou e saiu" de "morreu no meio".
 *
 * @param callable(Telegram): void $processarUpdate
 * @param (callable(int): void)|null $esperar
 * @param (callable(callable(): void): void)|null $registrarEncerramento injeção para teste
 */
function executarLongPollingTelegram(
    Telegram $telegram,
    callable $processarUpdate,
    ?callable $esperar = null,
    ?int $maxCiclos = null,
    int $timeoutSegundos = 30,
    ?callable $registrarEncerramento = null
): void {
    if ($maxCiclos !== null && $maxCiclos <= 0) {
        return;
    }

    $aguardar = $esperar ?? static function (int $segundos): void {
        if ($segundos > 0) {
            sleep($segundos);
        }
    };

    // Estado lido pelo encerramento. `vivo` continua true só quando o processo
    // morre sem passar pelo `finally` — isto é, num `exit`. Objeto, e não
    // variável por referência, porque a análise estática congela o tipo inicial
    // de um `use (&$x)` e passaria a tratar o encerramento como código morto.
    $estado = new \App\Support\EstadoPolling();

    pollingRegistrarEncerramento($telegram, $estado, $registrarEncerramento);

    // Precisa ficar abaixo do timeout total de 60s do transporte Telegram.
    $timeout = max(1, min(50, $timeoutSegundos));
    $offset = 0;
    $ciclos = 0;

    try {
        while ($maxCiclos === null || $ciclos < $maxCiclos) {
            // update=false é essencial: getUpdates() não pode confirmar o lote antes
            // de o callback abaixo processar todos os updates.
            $resposta = $telegram->getUpdates($offset, 100, $timeout, false);
            $ciclos++;

            if ($resposta['ok'] !== true) {
                $aguardar(1);
                continue;
            }

            $ultimoUpdateId = pollingProcessarLote($telegram, $processarUpdate, $estado);
            if ($ultimoUpdateId !== null) {
                $offset = $ultimoUpdateId + 1;
            }
        }
    } finally {
        // Retorno normal ou exceção: o encerramento não tem o que confirmar.
        // Em exceção o lote fica sem confirmar de propósito, para reentrega.
        $estado->vivo = false;
    }
}

/**
 * Processa o lote sem confirmar antes do último callback.
 * @param callable(Telegram): void $processarUpdate
 */
function pollingProcessarLote(Telegram $telegram, callable $processarUpdate, \App\Support\EstadoPolling $estado): ?int
{
    $ultimoUpdateId = null;
    $total = $telegram->UpdateCount();
    for ($i = 0; $i < $total; $i++) {
        $telegram->serveUpdate($i);
        $updateId = $telegram->UpdateID();
        if ($updateId === null) {
            continue;
        }

        // ANTES de processar: se o script roteado sair por `exit`, é
        // este o offset que o encerramento confirma. Marcar depois não
        // adiantaria nada — `exit` nunca devolve o controle para cá.
        // Queda anormal não confirma: quem separa é o tipo do erro.
        $estado->offsetProcessado = $estado->offsetProcessado === null
            ? $updateId + 1
            : max($estado->offsetProcessado, $updateId + 1);

        $processarUpdate($telegram);
        $ultimoUpdateId = $ultimoUpdateId === null
            ? $updateId
            : max($ultimoUpdateId, $updateId);
    }

    return $ultimoUpdateId;
}

/**
 * @param (callable(callable(): void): void)|null $registrarEncerramento
 */
function pollingRegistrarEncerramento(Telegram $telegram, \App\Support\EstadoPolling $estado, ?callable $registrarEncerramento): void
{
    $registrar = $registrarEncerramento ?? static function (callable $aoEncerrar): void {
        register_shutdown_function($aoEncerrar);
    };
    $registrar(static function () use ($telegram, $estado): void {
        if (!$estado->vivo) {
            return;
        }

        $offset = $estado->offsetProcessado;
        if ($offset === null || !pollingDeveConfirmarNoEncerramento($offset, error_get_last())) {
            return;
        }

        pollingConfirmarOffset($telegram, $offset);
    });
}
