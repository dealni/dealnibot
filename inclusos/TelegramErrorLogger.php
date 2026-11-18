<?php

declare(strict_types=1);

/**
 * Telegram Error Logger Class
 *
 * Grava erros da Bot API em JSON via App\Support\LoggerFactory (Monolog).
 *
 * O cliente Telegram chama o método estático global
 * `TelegramErrorLogger::log(json_decode($result, true), $loggerArray)`.
 */
class TelegramErrorLogger
{
    /** Trecho da descrição com que a Bot API recusa o Markdown da mensagem. */
    private const RECUSA_DE_FORMATACAO = "can't parse entities";

    private static bool $retendoRecusaDeFormatacao = false;

    /** @var list<array{array<mixed>, array<int|string, mixed>}> */
    private static array $recusasDeFormatacaoRetidas = [];

    /**
     * Roda o envio segurando o 400 "can't parse entities" em vez de gravá-lo.
     *
     * Quem chama reenvia a mensagem sem `parse_mode` e decide o nível com
     * soltarRecusasDeFormatacao(): a recusa só é erro se o reenvio também não
     * entregar. Os demais erros da Bot API seguem direto para o log.
     *
     * @template T
     * @param callable(): T $envio
     * @return T
     */
    public static function reterRecusaDeFormatacao(callable $envio): mixed
    {
        self::$retendoRecusaDeFormatacao = true;
        try {
            return $envio();
        } finally {
            self::$retendoRecusaDeFormatacao = false;
        }
    }

    /**
     * Grava as recusas retidas: como aviso quando o reenvio em texto puro
     * entregou a mensagem, como erro quando não entregou.
     */
    public static function soltarRecusasDeFormatacao(bool $reenvioEntregue): void
    {
        $retidas = self::$recusasDeFormatacaoRetidas;
        self::$recusasDeFormatacaoRetidas = [];
        foreach ($retidas as [$result, $loggerArray]) {
            self::gravar($result, $loggerArray, $reenvioEntregue);
        }
    }

    /**
     * Log errors to file or handle them as needed
     * @param mixed $result Response from Telegram API
     * @param array<int|string, mixed> $loggerArray Additional context data
     */
    public static function log($result, array $loggerArray = []): void
    {
        // A resposta vem de json_decode (mixed): sem array não há erro a logar
        if (!is_array($result)) {
            return;
        }

        // Verifica se houve erro na resposta da API
        if (!isset($result['ok']) || $result['ok'] !== false) {
            return;
        }

        if (self::$retendoRecusaDeFormatacao && ($result['curl_error_code'] ?? 0) === 0 && self::eRecusaDeFormatacao($result)) {
            self::$recusasDeFormatacaoRetidas[] = [$result, $loggerArray];
            return;
        }

        self::gravar($result, $loggerArray, false);
    }

    /**
     * Recusa que não passa sozinha: o bot foi removido, bloqueado ou perdeu o
     * direito de escrever no chat. Falha transitória (429, 5xx, rede) não
     * entra. É o que decide o nível do log e a memória de
     * inclusos/funcoes_recusa_de_escrita.php.
     *
     * @param array<mixed> $result
     */
    public static function ehRecusaDefinitivaDeEscrita(array $result): bool
    {
        if (($result['ok'] ?? null) !== false) {
            return false;
        }
        $codigo = $result['error_code'] ?? null;
        $descricao = $result['description'] ?? '';

        return $codigo === 403 || ($codigo === 400 && is_string($descricao)
            && preg_match('/CHAT_WRITE_FORBIDDEN|CHAT_RESTRICTED|chat not found|not enough rights to send|have no rights to send/i', $descricao) === 1);
    }

    /** @param array<mixed> $result */
    private static function eRecusaDeFormatacao(array $result): bool
    {
        return ($result['error_code'] ?? null) === 400
            && is_string($result['description'] ?? null)
            && str_contains($result['description'], self::RECUSA_DE_FORMATACAO);
    }

    private static function falhaReal(bool $aviso, bool $semMudanca, bool $recusa): bool
    {
        return !$aviso && !$semMudanca && !$recusa;
    }

    /** @param array<mixed> $result */
    private static function descricaoDaResposta(array $result): string
    {
        return isset($result['description']) && is_scalar($result['description'])
            ? (string) $result['description']
            : 'Unknown error';
    }

    private static function carregarLogger(): bool
    {
        // Alguns entrypoints carregam Telegram.php antes do autoload do Composer.
        // A falta de vendor/ não interfere no envio da mensagem.
        if (!class_exists(\App\Support\LoggerFactory::class)) {
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (!is_file($autoload)) {
                return false;
            }
            require_once $autoload;
        }

        if (!class_exists(\App\Support\LoggerFactory::class)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<mixed> $result
     * @param array<int|string, mixed> $loggerArray
     */
    private static function mensagemJaAtualizada(array $result, array $loggerArray, string $description): bool
    {
        return ($result['error_code'] ?? null) === 400
            && in_array($loggerArray['metodo'] ?? null, ['editMessageText', 'editMessageReplyMarkup'], true)
            && str_starts_with(strtolower($description), 'bad request: message is not modified');
    }

    /**
     * @param array<mixed> $result
     * @param array<int|string, mixed> $loggerArray
     */
    private static function gravar(array $result, array $loggerArray, bool $comoAviso): void
    {
        // description e error_code só entram no log se forem escalares
        $description = self::descricaoDaResposta($result);

        if (!self::carregarLogger()) {
            return;
        }

        $description = \App\Support\ResumoTelegram::descricao($description);
        $errorCode = (string) (\App\Support\ResumoTelegram::codigo($result['error_code'] ?? null) ?? 0);
        $falhaDeTransporte = ($result['curl_error_code'] ?? 0) !== 0;
        $comoAviso = $comoAviso && !$falhaDeTransporte;
        $naoModificada = !$falhaDeTransporte && self::mensagemJaAtualizada($result, $loggerArray, $description);
        $recusaDefinitiva = !$falhaDeTransporte && self::ehRecusaDefinitivaDeEscrita($result);
        $canal = \App\Support\LoggerFactory::channel('telegram', self::falhaReal($comoAviso, $naoModificada, $recusaDefinitiva));
        $contexto = [
            'error_code' => $errorCode,
            'api' => \App\Support\ResumoTelegram::resposta($result),
            'context' => \App\Support\ResumoTelegram::contexto($loggerArray),
        ];
        if ($naoModificada) {
            $canal->debug('Telegram: mensagem já atualizada', $contexto);
            return;
        }
        if ($comoAviso) {
            // O reenvio sem parse_mode entregou: a recusa não é falha do bot.
            $canal->warning('Telegram API Warning: ' . $description, $contexto + ['reenvio' => 'sem parse_mode, entregue']);
            return;
        }
        if ($recusaDefinitiva) {
            // Grupo que tirou do bot o direito de escrever é decisão do grupo,
            // não defeito: como `error`, 2.682 recusas em 7 dias escondiam o
            // erro real. O roteador também para de chamar a API desse chat.
            $canal->warning('Telegram API Warning: ' . $description, $contexto + ['recusa_de_escrita' => true]);
            return;
        }
        $canal->error('Telegram API Error: ' . $description, $contexto);
    }
}
