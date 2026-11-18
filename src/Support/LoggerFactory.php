<?php

declare(strict_types=1);

namespace App\Support;

use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fábrica de loggers Monolog com uma linha JSON por evento.
 *
 * O envelope segue o contrato de observabilidade (docs/ARQUITETURA.md).
 * `FormatadorDeContrato` dá ao evento os campos `ts`, `level`, `msg`,
 * `service`, `env`, `version` e `request_id`, e `ProcessadorDeRedacao` — que
 * entra em TODO canal, sem exceção — apaga o valor das chaves proibidas,
 * inclusive dentro do TEXTO da mensagem. As duas coisas ficam aqui, no único
 * ponto por onde todo log deste bot passa (inclusive o do
 * `TelegramErrorLogger`, usado por todos os canais daqui): amarrá-las ao
 * chamador seria o mesmo que torná-las opcionais.
 *
 * Destino: logs/app-YYYY-MM-DD.log (RotatingFileHandler). Em CLI/cron,
 * STDERR JSON fica sempre ativo (alertas operacionais), independente de
 * LOGS_ATIVO. O arquivo só é escrito com LOGS_ATIVO=true ou $forcar=true
 * (ex.: falha operacional de cron).
 *
 * O diretório de logs sai da variável de ambiente prefixada por bot (ver
 * `VARIAVEL_DO_DIRETORIO_DE_LOGS`) quando definida — é o que permite a suíte
 * de testes redirecionar as gravações para um diretório temporário em vez de
 * sujar logs/ de produção, e o prefixo é o que impede um bot irmão na mesma
 * VPS de herdar o diretório deste. Sem a variável, cai em
 * <raiz do repositório>/logs.
 */
final class LoggerFactory
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    private const VARIAVEL_DO_DIRETORIO_DE_LOGS = 'DEALNI_LOGS_DIR';
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    private const ARQUIVO_BASE = 'app.log';

    private const MAX_ARQUIVOS = 14;

    /** @var array<string, Logger> */
    private static array $loggers = [];

    private static bool $cli = false;

    /**
     * Liga o STDERR JSON dos processos de terminal.
     *
     * Não abre mais o ciclo à mão: `ContextoDeExecucao` resolve o `request_id`
     * na PRIMEIRA leitura e o guarda pelo processo inteiro, então um job de
     * cron já sai com um id só sem que nenhum script precise lembrar disso.
     */
    public static function forCli(bool $ativo = true): void
    {
        if (self::$cli === $ativo) {
            return;
        }

        self::fecharLoggers();
        self::$cli = $ativo;
    }

    public static function reset(): void
    {
        self::fecharLoggers();
        self::$cli = false;
    }

    /**
     * @param bool $forcar Grava em arquivo mesmo com LOGS_ATIVO=false
     */
    public static function channel(string $canal, bool $forcar = false): LoggerInterface
    {
        $canal = self::sanitizarCanal($canal);
        $modo = self::$cli ? 'cli' : 'web';
        $sufixo = $forcar ? '#force' : '';
        $chave = $canal . '#' . $modo . $sufixo;

        if (isset(self::$loggers[$chave])) {
            return self::$loggers[$chave];
        }

        $logger = new Logger($canal);
        $logger->setExceptionHandler(self::registrarFalhaDeEscrita(...));
        // Processor no LOGGER, não no handler: vale para os dois destinos
        // (arquivo e STDERR) sem depender de qual deles foi montado abaixo.
        $logger->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(
            context: [...ContextoDeExecucao::item(), ...$record->context]
        ));
        $logger->pushProcessor(new ProcessadorDeRedacao());
        [$gravarArquivo, $gravarStderr] = self::destinosDeEscrita($forcar || self::logsAtivos(), self::$cli);

        if (!$gravarArquivo && !$gravarStderr) {
            $logger->pushHandler(new NullHandler());
            self::$loggers[$chave] = $logger;

            return $logger;
        }

        $arquivoOk = false;
        if ($gravarArquivo) {
            $arquivoOk = self::adicionarDestinoDeArquivo($logger);
        }

        // CLI: STDERR sempre. Forçado sem arquivo: STDERR como contingência.
        if ($gravarStderr || !$arquivoOk) {
            $stderr = new StreamHandler('php://stderr', Level::Debug);
            $stderr->setFormatter(self::formatadorJson());
            $logger->pushHandler($stderr);
        }

        if ($logger->getHandlers() === []) {
            $logger->pushHandler(new NullHandler());
        }

        self::$loggers[$chave] = $logger;

        return $logger;
    }

    /** Prepara permissões e rotação sem alterar os destinos de contingência. */
    private static function adicionarDestinoDeArquivo(Logger $logger): bool
    {
        $diretorio = self::diretorioLogs();
        if (!is_dir($diretorio) && !(@mkdir($diretorio, 0700, true) || is_dir($diretorio))) {
            return false;
        }
        @chmod($diretorio, 0700);
        $handler = new RotatingFileHandler(
            $diretorio . '/' . self::ARQUIVO_BASE,
            self::MAX_ARQUIVOS,
            Level::Debug,
            true,
            0600,
            true
        );
        $handler->setFormatter(self::formatadorJson());
        $logger->pushHandler($handler);
        return true;
    }

    /** @return array{bool, bool} */
    private static function destinosDeEscrita(bool $arquivo, bool $stderr): array
    {
        // Comandos de produção como root não podem criar o log diário dos bots.
        $uid = 'posix_geteuid';
        if (!RuntimeEnvironment::isTesting() && PHP_SAPI === 'cli'
            && function_exists($uid) && $uid() === 0) {
            return [false, $stderr || $arquivo];
        }

        return [$arquivo, $stderr];
    }

    /** Uma falha no destino de log nunca pode derrubar o webhook ou o worker. */
    private static function registrarFalhaDeEscrita(Throwable $erro, LogRecord $registro): void
    {
        try {
            $stderr = new StreamHandler('php://stderr', Level::Debug);
            $stderr->setFormatter(self::formatadorJson());
            // Reaplica a redação: a exceção pode ter ocorrido no próprio processor.
            $registro = (new ProcessadorDeRedacao())($registro);
            $stderr->handle($registro->with(extra: [
                ...$registro->extra,
                'log_fallback' => true,
                'log_error_type' => $erro::class,
            ]));
        } catch (Throwable) {
            // Até stderr pode estar fechado. Não interrompa o trabalho de negócio.
        }
    }

    public static function diretorioLogs(): string
    {
        $env = getenv(self::VARIAVEL_DO_DIRETORIO_DE_LOGS);
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return dirname(__DIR__, 2) . '/logs';
    }

    public static function caminhoAppLogDeHoje(): string
    {
        return self::diretorioLogs() . '/app-' . date('Y-m-d') . '.log';
    }

    public static function sanitizarCanal(string $canal): string
    {
        $canal = basename($canal);
        if (in_array($canal, ['', '.', '..', '/'], true)) {
            return 'geral';
        }

        return $canal;
    }

    /**
     * Encerra os handlers antes de descartar os loggers cacheados.
     *
     * O `Logger` do Monolog mantém recursos abertos nos handlers (o arquivo só
     * é aberto na primeira escrita). Descartar apenas a referência ao logger
     * funciona em processos curtos, mas deixa descritores abertos quando a
     * fábrica é reconfigurada num worker longo. `close()` é a operação de fim
     * de ciclo documentada pelo Monolog e permite que handlers como o
     * `RotatingFileHandler` liberem o stream e concluam a rotação.
     */
    private static function fecharLoggers(): void
    {
        foreach (self::$loggers as $logger) {
            try {
                $logger->close();
            } catch (\Throwable) {
                // A limpeza não pode impedir a nova configuração do logger.
            }
        }

        self::$loggers = [];
    }

    private static function logsAtivos(): bool
    {
        return defined('LOGS_ATIVO') && LOGS_ATIVO;
    }

    /**
     * Uma instância por handler: o formatter guarda estado de configuração, não
     * de evento, e reusar a mesma não muda nada — mas criar aqui deixa o ponto
     * único de decisão sobre o stack trace.
     *
     * O stack só é serializado fora de `production`, como o contrato manda: em
     * produção ele revela caminho de arquivo e resumo de ARGUMENTO — e
     * argumento carrega token — para quem tiver acesso ao log.
     */
    private static function formatadorJson(): FormatadorDeContrato
    {
        $formatter = new FormatadorDeContrato(FormatadorDeContrato::BATCH_MODE_JSON, true);
        $formatter->includeStacktraces(false);

        // O evento registra tipo e mensagem da exceção, sem caminhos absolutos
        // e volume extra do stack trace.

        return $formatter;
    }
}
