<?php

declare(strict_types=1);

require_once __DIR__ . '/funcoes_retencao_inbox.php';
require_once __DIR__ . '/funcoes_duracao.php';

/**
 * Persiste o payload antes do ACK; a chave primária absorve reentregas.
 * @param array<string, mixed> $payload
 */
function registrarUpdateWebhook(bdTelegram $bd, array $payload, int $recebidoEm): bool
{
    $updateId = $payload['update_id'] ?? null;
    // update_id é um inteiro JSON da Bot API. `is_numeric()` aceitaria float,
    // notação científica e strings como "1.5"; o cast seguinte truncaria esses
    // valores (ou saturaria inteiros fora do alcance), misturando updates
    // distintos na chave primária da inbox.
    if (!is_int($updateId) || $recebidoEm <= 0) {
        return false;
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($json)) {
        return false;
    }

    $resultado = $bd->queryExec(
        "INSERT OR IGNORE INTO webhook_inbox "
        . "(update_id, payload_json, status, recebido_em, disponivel_em) "
        . "VALUES (:id, :payload, 'pendente', :recebido, :disponivel)",
        [
            ':id' => $updateId,
            ':payload' => $json,
            ':recebido' => $recebidoEm,
            ':disponivel' => $recebidoEm,
        ]
    );
    if ($resultado instanceof PDOStatement) {
        \App\Support\LoggerFactory::channel('webhook')->info('Update recebido na inbox', ['update_id' => $updateId, 'novo' => $resultado->rowCount() === 1]);
        return true;
    }
    logarFalhaDaInbox('Falha ao persistir update antes do ACK.', $updateId);
    return false;
}

/** @return array{update_id: int, payload: array<string, mixed>, tentativa: int}|null */
function decodificarUpdateWebhook(bdTelegram $bd, PDOStatement $resultado, int $momento): ?array
{
    $row = $resultado->fetch(PDO::FETCH_ASSOC);
    $resultado->closeCursor();
    if (!is_array($row) || !is_int($row['update_id'] ?? null) || !is_string($row['payload_json'] ?? null) || !is_int($row['tentativas'] ?? null)) {
        return null;
    }

    return decodificarPayloadUpdateWebhook($bd, $row['update_id'], $row['payload_json'], $momento, $row['tentativas']);
}

/**
 * Payload ilegível não trava a fila: a linha já está reservada, então ela é
 * marcada como falha e sai da cabeça da fila pelo backoff.
 *
 * @return array{update_id: int, payload: array<string, mixed>, tentativa: int}|null
 */
function decodificarPayloadUpdateWebhook(
    bdTelegram $bd,
    int $updateId,
    string $json,
    int $momento,
    int $tentativa
): ?array {
    try {
        $payload = json_decode(
            $json,
            true,
            512,
            JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR,
        );
    } catch (JsonException) {
        marcarUpdateWebhookFalho($bd, $updateId, 'Payload JSON inválido ou profundo.', $momento, $tentativa);

        return null;
    }
    if (!is_array($payload) || !is_int($payload['update_id'] ?? null) || $payload['update_id'] !== $updateId) {
        marcarUpdateWebhookFalho($bd, $updateId, 'Payload JSON sem update_id inteiro correspondente.', $momento, $tentativa);

        return null;
    }

    $normalizado = [];
    foreach ($payload as $chave => $valor) {
        if (is_string($chave)) {
            $normalizado[$chave] = $valor;
        }
    }

    return ['update_id' => $updateId, 'payload' => $normalizado, 'tentativa' => $tentativa];
}

/** @return array{update_id: int, payload: array<string, mixed>, tentativa: int}|null */
function pegarUpdateWebhookPorId(bdTelegram $bd, int $updateId, int $momento): ?array
{
    $resultado = $bd->queryExec(
        "UPDATE webhook_inbox SET status = 'processando', tentativa_em = :tentativa_em, "
        . 'tentativas = tentativas + 1 WHERE update_id = :id '
        . "AND status IN ('pendente', 'erro') AND disponivel_em <= :disponivel_em "
        . 'RETURNING update_id, payload_json, tentativas',
        [':tentativa_em' => $momento, ':id' => $updateId, ':disponivel_em' => $momento]
    );

    return $resultado instanceof PDOStatement
        ? decodificarUpdateWebhook($bd, $resultado, $momento)
        : null;
}

/**
 * Reserva o próximo update disponível. A reserva é um único UPDATE com
 * subconsulta e RETURNING: dois workers que escolherem a mesma linha só
 * conseguem gravar uma vez, e o perdedor enxerga a fila vazia nesta rodada.
 * A lease é renovada por CAS antes das fases externas: o áudio inteiro pode
 * levar 365 s, mas a maior chamada isolada tem teto de 120 s. Restam 240 s
 * por fase para banco/Redis/escalonamento; expiração impede novos efeitos.
 *
 * @return array{update_id: int, payload: array<string, mixed>, tentativa: int}|null
 */
function pegarProximoUpdateWebhook(bdTelegram $bd, int $momento, int $leaseSegundos = \App\Support\LeaseWebhook::SEGUNDOS): ?array
{
    $recuperacao = $bd->queryExec(
        "UPDATE webhook_inbox SET status = 'erro', disponivel_em = :agora "
        . "WHERE status = 'processando' AND tentativa_em <= :limite",
        [':agora' => $momento, ':limite' => $momento - max(1, $leaseSegundos)]
    );
    if (!$recuperacao instanceof PDOStatement) {
        throw new RuntimeException('Falha ao recuperar leases expiradas da inbox.');
    }
    $resultado = $bd->queryExec(
        "UPDATE webhook_inbox SET status = 'processando', tentativa_em = :agora, "
        . 'tentativas = tentativas + 1 WHERE update_id = ('
        . "SELECT update_id FROM webhook_inbox WHERE status IN ('pendente', 'erro') "
        . 'AND disponivel_em <= :disponivel ORDER BY update_id ASC LIMIT 1) '
        . "AND status IN ('pendente', 'erro') RETURNING update_id, payload_json, tentativas",
        [':agora' => $momento, ':disponivel' => $momento]
    );

    if (!$resultado instanceof PDOStatement) {
        throw new RuntimeException('Falha ao reservar o próximo update da inbox.');
    }

    return decodificarUpdateWebhook($bd, $resultado, $momento);
}

/** Uma tentativa expirada nunca ressuscita, mesmo antes de outro worker tomá-la. */
function renovarUpdateWebhook(bdTelegram $bd, int $updateId, int $tentativa, int $momento): bool
{
    $resultado = $bd->queryExec(
        'UPDATE webhook_inbox SET tentativa_em = :agora '
        . "WHERE update_id = :id AND status = 'processando' AND tentativas = :tentativa "
        . 'AND tentativa_em > :limite',
        [':agora' => $momento, ':id' => $updateId, ':tentativa' => $tentativa,
            ':limite' => $momento - \App\Support\LeaseWebhook::SEGUNDOS]
    );

    return $resultado instanceof PDOStatement && $resultado->rowCount() === 1;
}

function marcarUpdateWebhookProcessado(bdTelegram $bd, int $updateId, int $momento, int $tentativa): bool
{
    $resultado = $bd->queryExec(
        "UPDATE webhook_inbox SET status = 'processado', processado_em = :momento, ultimo_erro = NULL "
        . "WHERE update_id = :id AND status = 'processando' AND tentativas = :tentativa",
        [':momento' => $momento, ':id' => $updateId, ':tentativa' => $tentativa]
    );

    return $resultado instanceof PDOStatement && $resultado->rowCount() === 1;
}

function marcarUpdateWebhookFalho(bdTelegram $bd, int $updateId, string $erro, int $momento, int $tentativa): bool
{
    $consulta = $bd->queryExec(
        'SELECT tentativas FROM webhook_inbox WHERE update_id = :id',
        [':id' => $updateId]
    );
    if (!$consulta instanceof PDOStatement) {
        return false;
    }
    $valor = $consulta->fetchColumn();
    $tentativas = is_numeric($valor) ? max(1, (int) $valor) : 1;
    $atraso = min(3600, 1 << min(10, $tentativas));
    $resultado = $bd->queryExec(
        "UPDATE webhook_inbox SET status = 'erro', ultimo_erro = :erro, disponivel_em = :disponivel "
        . "WHERE update_id = :id AND status = 'processando' AND tentativas = :tentativa",
        [
            ':erro' => mb_substr($erro, 0, 1000, 'UTF-8'),
            ':disponivel' => $momento + $atraso,
            ':id' => $updateId,
            ':tentativa' => $tentativa,
        ]
    );

    return $resultado instanceof PDOStatement && $resultado->rowCount() === 1;
}

/** @param array{type?: int, message?: string, file?: string, line?: int}|null $erro */
function erroFatalNoDesligamento(?array $erro): bool
{
    return $erro !== null && in_array($erro['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
}

function logarFalhaDaInbox(string $mensagem, int $updateId): void
{
    if (function_exists('escreverLog')) {
        escreverLog($mensagem, 'webhook_inbox', true, 'error', ['update_id' => $updateId]);
    }
}

/** @param array{type?: int, message?: string, file?: string, line?: int}|null $ultimoErro */
function finalizarUpdateWebhookNoDesligamento(
    bdTelegram $bd,
    int $updateId,
    int $momento,
    ?array $ultimoErro,
    int $tentativa
): void {
    if (erroFatalNoDesligamento($ultimoErro)) {
        if (!marcarUpdateWebhookFalho($bd, $updateId, 'Erro fatal durante o roteamento.', $momento, $tentativa)) {
            logarFalhaDaInbox('Erro fatal e falha ao reagendar update.', $updateId);
        }

        return;
    }
    if (!marcarUpdateWebhookProcessado($bd, $updateId, $momento, $tentativa)) {
        logarFalhaDaInbox('Saída normal, mas falha ao confirmar update.', $updateId);
    }
}

/**
 * Processa um update persistido. O shutdown confirma comandos legados que usam
 * exit e reage a erro fatal; exceções seguem pelo backoff normal.
 *
 * @param (callable(): void)|null $rotear
 * @param (callable(): int)|null $relogio
 * @return 'vazio'|'processado'|'falha'
 */
function processarProximoUpdateWebhook(
    bdTelegram $bd,
    Telegram $telegram,
    Redis $redis,
    ?int $agora = null,
    ?callable $rotear = null,
    ?int $updateId = null,
    ?callable $relogio = null
): string {
    $momento = $agora ?? time();
    $item = $updateId === null
        ? pegarProximoUpdateWebhook($bd, $momento)
        : pegarUpdateWebhookPorId($bd, $updateId, $momento);
    if ($item === null) {
        return 'vazio';
    }

    // O roteador é incluído no escopo desta função e comandos legados usam
    // variáveis genéricas como $item. Os dados da reserva são preservados em
    // nomes próprios antes do require para que essas variáveis não apaguem o
    // update_id que será confirmado no final.
    $updateIdReservado = $item['update_id'];
    $tentativa = $item['tentativa'];
    $relogio ??= time(...);
    $leaseAnterior = \App\Support\LeaseWebhook::definir(static function () use ($bd, $updateIdReservado, $tentativa, $relogio): void {
        if (!renovarUpdateWebhook($bd, $updateIdReservado, $tentativa, $relogio())) {
            throw new RuntimeException('Lease da inbox perdida; próximo efeito externo recusado.');
        }
    });
    $entregaIa = new \App\Support\EntregaIaDuravel($bd, $updateIdReservado, $tentativa, $relogio(...));
    $entregaAnterior = \App\Support\EntregaIaDuravel::definir($entregaIa);
    $contextoAnterior = \App\Support\ContextoDeExecucao::iniciarItem($updateIdReservado, $tentativa, $updateId === null);
    \App\Support\LoggerFactory::channel('webhook_inbox')->info('Update reservado para roteamento');
    $telegram->setData($item['payload']);
    $finalizado = false;
    register_shutdown_function(static function () use ($bd, $updateIdReservado, $relogio, $tentativa, &$finalizado): void {
        if ($finalizado) {
            return;
        }
        $finalizado = true;
        finalizarUpdateWebhookNoDesligamento($bd, $updateIdReservado, $relogio(), error_get_last(), $tentativa);
    });

    try {
        try {
            if ($entregaIa->retomar($telegram, $redis)) {
                // O checkpoint substitui o roteamento: não regenera IA nem cota.
            } elseif ($rotear === null) {
                require dirname(__DIR__) . '/roteador.php';
            } else {
                $rotear();
            }
        } catch (\App\Support\RespostaIaAdiada) {
            $finalizado = true;
            return 'falha';
        } catch (Throwable $erro) {
            $finalizado = true;
            registrarFalhaDoRoteador($erro);
            if (!marcarUpdateWebhookFalho($bd, $updateIdReservado, $erro->getMessage(), $relogio(), $tentativa)) {
                logarFalhaDaInbox('Falha do roteador não pôde ser reagendada.', $updateIdReservado);
            }

            return 'falha';
        }

        $finalizado = true;
        if (!marcarUpdateWebhookProcessado($bd, $updateIdReservado, $relogio(), $tentativa)) {
            logarFalhaDaInbox('Update roteado, mas não pôde ser confirmado.', $updateIdReservado);

            return 'falha';
        }

        return 'processado';
    } finally {
        \App\Support\EntregaIaDuravel::definir($entregaAnterior);
        \App\Support\LeaseWebhook::definir($leaseAnterior);
        \App\Support\ContextoDeExecucao::restaurarItem($contextoAnterior);
    }
}

/**
 * Fecha o atendimento de um update do webhook com uma linha no canal
 * `webhook`: o `update_id`, o desfecho de processarProximoUpdateWebhook() e a
 * duração em `duration_ms`, que o formatador promove ao topo do evento.
 *
 * A duração começa no hrtime(true) do topo do bot_web.php, então inclui a
 * gravação na inbox, o ACK e o roteamento inteiro (a chamada paga ao provedor,
 * quando houver). É `info` e respeita LOGS_ATIVO: é rotina, não falha. O
 * replay do cron não passa por aqui, porque no CLI todo evento também vai ao
 * STDERR, e o STDERR do cron é reservado para falha (config/crontab.txt).
 *
 * @param 'vazio'|'processado'|'falha' $desfecho
 */
function registrarFimDoAtendimentoWebhook(
    int $updateId,
    string $desfecho,
    int|float $inicioNs,
    int|float|null $fimNs = null,
    ?\Psr\Log\LoggerInterface $log = null
): void {
    ($log ?? \App\Support\LoggerFactory::channel('webhook'))->info('Atendimento do update concluído', [
        'update_id' => $updateId,
        'desfecho' => $desfecho,
        'duration_ms' => duracaoEmMilissegundos($inicioNs, $fimNs ?? hrtime(true)),
    ]);
}
