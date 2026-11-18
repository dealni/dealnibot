<?php

declare(strict_types=1);

/**
 * Preserva Markdown e repete somente recusas explícitas de
 * formato ou limite. Marcadores simples abertos seguem como texto puro desde
 * o primeiro envio, sem exigir uma recusa da Bot API.
 *
 * O 400 "can't parse entities" da primeira tentativa fica retido no logger e
 * só vira erro se o reenvio sem parse_mode também falhar; com o reenvio
 * entregue, vai ao log como aviso, sem inflar a contagem de erros.
 * TelegramErrorLogger chega pelo require_once do topo de Telegram.php.
 * Na inbox, qualquer 429 preserva a resposta e reagenda sua entrega pelo cron,
 * sem espera no FPM nem nova geração de IA. Fora da inbox (polling legado),
 * retry_after de 1 a 60 segundos permite uma única nova tentativa; espera e
 * envio acrescentam até 75 s. Timeout/5xx não permitem reenvio automático.
 * A lease é renovada antes de cada requisição ao Telegram.
 *
 * @param (callable(int): void)|null $esperar substitui a espera nos testes
 */
function enviarRespostaIa(Telegram $telegram, Redis $redis, string $texto, ?callable $esperar = null): bool
{
    $conteudo = [
        'chat_id' => $telegram->ChatID(),
        'text' => $texto,
        'reply_parameters' => json_encode(['message_id' => $telegram->MessageID(), 'allow_sending_without_reply' => true]),
    ];
    if (!respostaIaTemMarcadorAberto($texto)) {
        $conteudo['parse_mode'] = 'Markdown';
    }
    return entregarConteudoRespostaIa($telegram, $redis, $conteudo, $esperar);
}

/** @param array<string, mixed> $conteudo
 * @param (callable(int): void)|null $esperar
 */
function entregarConteudoRespostaIa(Telegram $telegram, Redis $redis, array $conteudo, ?callable $esperar = null): bool
{
    $entregue = false;
    $resultado = ['ok' => false];
    $limiteRetentado = false;
    $esperar ??= static function (int $segundos): void {
        sleep($segundos);
    };
    try {
        // Cada condição só pode acrescentar um envio: três tentativas no máximo.
        for ($tentativa = 0; $tentativa < 3; $tentativa++) {
            \App\Support\EntregaIaDuravel::preparar($conteudo);
            $resultado = TelegramErrorLogger::reterRecusaDeFormatacao(
                static fn (): array => $telegram->sendMessage($conteudo)
            );
            if (($resultado['error_code'] ?? null) === 429) {
                // Na inbox, persiste a recusa antes de esperar: o cron retoma.
                \App\Support\EntregaIaDuravel::concluir($resultado);
            }
            if ($resultado['ok'] !== false) {
                break;
            }
            if (isset($conteudo['parse_mode']) && respostaIaRecusaFormatacao($resultado)) {
                unset($conteudo['parse_mode']);
                continue;
            }
            $atraso = segundosDeRetentativaRespostaIa($resultado);
            if (!$limiteRetentado && $atraso !== null) {
                $limiteRetentado = true;
                renovarLeaseDaInboxAtiva();
                $esperar($atraso);
                continue;
            }
            break;
        }

        // Não repetir uma entrega recusada por permissão nem um resultado
        // ambíguo; a recusa definitiva fica lembrada para o próximo update.
        $entregue = $resultado['ok'] === true;
        lembrarRecusaDeEscrita($redis, $telegram->ChatID(), $resultado);
        if ($limiteRetentado && $entregue) {
            \App\Support\LoggerFactory::channel('telegram', true)->info('Resposta de IA entregue após limite do Telegram', ['retentativa' => true]);
        }

        \App\Support\EntregaIaDuravel::concluir($resultado);

        return $entregue;
    } finally {
        TelegramErrorLogger::soltarRecusasDeFormatacao($entregue);
    }
}
