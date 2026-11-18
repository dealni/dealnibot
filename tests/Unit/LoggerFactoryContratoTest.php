<?php

declare(strict_types=1);

namespace Tests;

use App\Support\LoggerFactory;
use App\Support\ProcessadorDeRedacao;
use App\Support\RuntimeEnvironment;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppLogReader;

/**
 * Contratos da fábrica de loggers e da leitura do ambiente.
 *
 * Dois pontos que nenhum outro teste alcança:
 *
 *   1. **De onde sai o diretório de log.** A variável prefixada por bot (bloco
 *      de dados abaixo) é o que permite a suíte inteira gravar em
 *      `sys_get_temp_dir()` em vez de sujar `logs/` de produção — e o prefixo
 *      é o que impede um bot irmão na mesma VPS de herdar o diretório deste. Um `&&` virado numa guarda de
 *      cinco caracteres devolve string vazia como diretório, e o log some sem
 *      ninguém perceber.
 *
 *   2. **A chave do cache de loggers.** Canal e modo forçado entram na chave;
 *      se um deles cair, dois canais diferentes passam a compartilhar o mesmo
 *      Logger — e o `$forcar = true` do cron devolve o logger silencioso que
 *      já estava em cache.
 *
 * Os casos que mexem em variável de ambiente rodam em processo separado: a
 * ordem de execução é sorteada, e um `putenv()` vazado contaminaria qualquer
 * teste posterior que grave log.
 */
final class LoggerFactoryContratoTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    private const VARIAVEL_DO_DIRETORIO = 'DEALNI_LOGS_DIR';
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    /** @var list<string> */
    private array $diretoriosTemporarios = [];

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        foreach ($this->diretoriosTemporarios as $diretorio) {
            $this->apagarTemporario($diretorio);
        }
        $this->diretoriosTemporarios = [];
    }

    private function diretorioTemporario(string $prefixo): string
    {
        $diretorio = sys_get_temp_dir() . '/' . $prefixo . uniqid();
        $this->diretoriosTemporarios[] = $diretorio;

        return $diretorio;
    }

    private function apagarTemporario(string $caminho): void
    {
        if (is_file($caminho) || is_link($caminho)) {
            unlink($caminho);

            return;
        }
        if (!is_dir($caminho)) {
            return;
        }

        $entradas = scandir($caminho);
        if ($entradas === false) {
            return;
        }
        foreach ($entradas as $entrada) {
            if ($entrada !== '.' && $entrada !== '..') {
                $this->apagarTemporario($caminho . '/' . $entrada);
            }
        }
        rmdir($caminho);
    }

    /** Raiz do repositório — o destino padrão quando não há variável. */
    private function diretorioPadrao(): string
    {
        return dirname(__DIR__, 2) . '/logs';
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDiretorioDeLogVemDaVariavelDeAmbienteQuandoPreenchida(): void
    {
        $destino = $this->diretorioTemporario('chatbots_dir_de_log_');
        putenv(self::VARIAVEL_DO_DIRETORIO . '=' . $destino);
        LoggerFactory::reset();

        $this->assertSame($destino, LoggerFactory::diretorioLogs());
        $this->assertSame(
            $destino . '/app-' . date('Y-m-d') . '.log',
            LoggerFactory::caminhoAppLogDeHoje()
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testVariavelVaziaCaiNoDiretorioPadraoDoRepositorio(): void
    {
        // String vazia NÃO é destino válido: sem a segunda metade da guarda o
        // diretório de log viraria '' e o handler passaria a escrever em
        // '/app-AAAA-MM-DD.log', na raiz do sistema de arquivos.
        putenv(self::VARIAVEL_DO_DIRETORIO . '=');
        LoggerFactory::reset();

        $this->assertSame($this->diretorioPadrao(), LoggerFactory::diretorioLogs());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testVariavelAusenteCaiNoDiretorioPadraoDoRepositorio(): void
    {
        putenv(self::VARIAVEL_DO_DIRETORIO);
        LoggerFactory::reset();

        $this->assertFalse(getenv(self::VARIAVEL_DO_DIRETORIO));
        $this->assertSame($this->diretorioPadrao(), LoggerFactory::diretorioLogs());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCadaCanalTemOSeuLoggerEOMesmoCanalReaproveitaOCache(): void
    {
        LoggerFactory::reset();

        $bd = LoggerFactory::channel('bd');
        $telegram = LoggerFactory::channel('telegram');

        // Sem o nome do canal na chave, os dois canais dividiriam o mesmo
        // Logger e as linhas sairiam com o canal errado.
        $this->assertNotSame($bd, $telegram);
        // E o cache tem de devolver a MESMA instância na segunda chamada:
        // sem ele, cada chamada abriria um handler novo para o mesmo arquivo.
        $this->assertSame($bd, LoggerFactory::channel('bd'));
        $this->assertSame($telegram, LoggerFactory::channel('telegram'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOModoForcadoNaoReaproveitaOLoggerSilencioso(): void
    {
        // O cron pede `$forcar = true` justamente quando LOGS_ATIVO está
        // desligada. Se o modo forçado não entrasse na chave, ele receberia de
        // volta o logger silencioso já cacheado e a falha operacional sumiria.
        $this->assertFalse(defined('LOGS_ATIVO'));
        LoggerFactory::reset();

        $silencioso = LoggerFactory::channel('bd');
        $forcado = LoggerFactory::channel('bd', true);

        $this->assertNotSame($silencioso, $forcado);
        $this->assertSame($forcado, LoggerFactory::channel('bd', true));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testChaveDistingueModoCliWebEForcado(): void
    {
        LoggerFactory::reset();
        $web = LoggerFactory::channel('bd');
        $webForcado = LoggerFactory::channel('bd', true);

        // `forCli()` limpa o cache para trocar os handlers. Aqui mudamos só o
        // estado do modo para conferir também o contrato da chave: se `cli`
        // cair dela, o logger web já cacheado será reutilizado indevidamente.
        $estadoCli = new \ReflectionProperty(LoggerFactory::class, 'cli');
        $estadoCli->setValue(null, true);
        $cli = LoggerFactory::channel('bd');
        $cliForcado = LoggerFactory::channel('bd', true);

        $this->assertNotSame($web, $webForcado);
        $this->assertNotSame($web, $cli);
        $this->assertNotSame($web, $cliForcado);
        $this->assertNotSame($webForcado, $cli);
        $this->assertNotSame($webForcado, $cliForcado);
        $this->assertNotSame($cli, $cliForcado);

        $cache = new \ReflectionProperty(LoggerFactory::class, 'loggers');
        $loggers = $cache->getValue();
        $this->assertIsArray($loggers);
        $this->assertSame(
            ['bd#web', 'bd#web#force', 'bd#cli', 'bd#cli#force'],
            array_keys($loggers)
        );

        $estadoCli->setValue(null, false);
        $this->assertSame($web, LoggerFactory::channel('bd'));
        $this->assertSame($webForcado, LoggerFactory::channel('bd', true));
        $estadoCli->setValue(null, true);
        $this->assertSame($cli, LoggerFactory::channel('bd'));
        $this->assertSame($cliForcado, LoggerFactory::channel('bd', true));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTodoLoggerRecebeProcessadorDeRedacao(): void
    {
        define('LOGS_ATIVO', true);
        putenv(self::VARIAVEL_DO_DIRETORIO . '=' . $this->diretorioTemporario('chatbots_redacao_'));
        LoggerFactory::reset();
        $antes = AppLogReader::tamanhoAtual();

        LoggerFactory::channel('redacao')->error(
            'falha com contexto',
            ['token' => 'nao-gravar-este-valor']
        );

        $linhas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($antes));
        $this->assertCount(1, $linhas);
        $contexto = $linhas[0]['context'] ?? null;
        $this->assertIsArray($contexto);
        $this->assertSame(ProcessadorDeRedacao::SUBSTITUTO, $contexto['token'] ?? null);
        $this->assertStringNotContainsString('nao-gravar-este-valor', (string) json_encode($linhas[0]));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDiretorioIngravavelUsaStderrComoContingenciaDoForce(): void
    {
        $base = $this->diretorioTemporario('chatbots_logs_bloqueado_');
        $this->assertTrue(mkdir($base, 0700));
        $arquivo = $base . '/nao-e-diretorio';
        file_put_contents($arquivo, 'bloqueado');
        putenv(self::VARIAVEL_DO_DIRETORIO . '=' . $arquivo . '/filho');
        LoggerFactory::reset();

        $logger = LoggerFactory::channel('fallback', true);
        $this->assertInstanceOf(Logger::class, $logger);
        $handlers = $logger->getHandlers();
        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(StreamHandler::class, $handlers[0]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCriacaoDoDiretorioDeLogRespeitaPermissao0700(): void
    {
        $diretorio = $this->diretorioTemporario('chatbots_logs_permissao_') . '/logs';
        $umaskAnterior = umask(0);

        try {
            putenv(self::VARIAVEL_DO_DIRETORIO . '=' . $diretorio);
            LoggerFactory::reset();
            $logger = LoggerFactory::channel('permissao', true);

            $this->assertInstanceOf(Logger::class, $logger);
            $this->assertTrue(is_dir($diretorio));
            $this->assertSame(0700, fileperms($diretorio) & 0777);
        } finally {
            umask($umaskAnterior);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCliUsaStreamHandlerComFormatterDoContrato(): void
    {
        LoggerFactory::reset();
        LoggerFactory::forCli();

        $logger = LoggerFactory::channel('cli');
        $this->assertInstanceOf(Logger::class, $logger);
        $handlers = $logger->getHandlers();
        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(StreamHandler::class, $handlers[0]);
        $formatter = $handlers[0]->getFormatter();
        $this->assertInstanceOf(\App\Support\FormatadorDeContrato::class, $formatter);

        $stack = new \ReflectionProperty(\Monolog\Formatter\JsonFormatter::class, 'includeStacktraces');
        $this->assertFalse($stack->getValue($formatter));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLoggerSilenciosoMantemNullHandlerNoCaminhoSemSaida(): void
    {
        // Sem arquivo ativo e fora da CLI, o logger precisa continuar sendo
        // utilizável sem emitir nada. O NullHandler é a guarda explícita desse
        // contrato; sem o push, a chamada pareceria funcionar mas perderia a
        // garantia de que o logger sempre tem um handler.
        $this->assertFalse(defined('LOGS_ATIVO'));
        LoggerFactory::reset();

        $logger = LoggerFactory::channel('silencioso');
        $this->assertInstanceOf(Logger::class, $logger);
        $handlers = $logger->getHandlers();

        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(NullHandler::class, $handlers[0]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormatadorRemoveMarcadorDeExcecaoQuandoErrJaFoiPromovido(): void
    {
        define('LOGS_ATIVO', true);
        putenv(self::VARIAVEL_DO_DIRETORIO . '=' . $this->diretorioTemporario('chatbots_formatador_'));
        LoggerFactory::reset();
        $antes = AppLogReader::tamanhoAtual();

        LoggerFactory::channel('formatador_marcador')->error(
            'falha com erro explícito',
            [
                'exception' => new \RuntimeException('falha original'),
                // Um `err` já presente impede a segunda exceção de ser
                // promovida, deixando o contexto disponível para conferir a
                // remoção do marcador interno do processor.
                'err' => 'erro explícito',
            ]
        );

        $linhas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($antes));
        $this->assertCount(1, $linhas);
        $evento = $linhas[0];
        $this->assertSame('erro explícito', $evento['err'] ?? null);

        $contexto = $evento['context'] ?? null;
        $this->assertIsArray($contexto);
        $excecao = $contexto['exception'] ?? null;
        $this->assertIsArray($excecao);
        $this->assertArrayNotHasKey(ProcessadorDeRedacao::MARCADOR_EXCECAO, $excecao);
        $this->assertSame(\RuntimeException::class, $excecao['class'] ?? null);
        $this->assertSame('falha original', $excecao['message'] ?? null);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTelegramErrorLoggerForcaFalhasComLogsDesativados(): void
    {
        define('LOGS_ATIVO', false);
        LoggerFactory::reset();
        require_once dirname(__DIR__, 2) . '/inclusos/TelegramErrorLogger.php';

        \TelegramErrorLogger::log(['ok' => false, 'description' => 'falha operacional']);

        $loggers = new \ReflectionProperty(LoggerFactory::class, 'loggers');
        $cache = $loggers->getValue();
        $this->assertIsArray($cache);
        $this->assertArrayHasKey('telegram#web#force', $cache);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTelegramErrorLoggerIgnoraRespostaArrayAccessNaoArray(): void
    {
        define('LOGS_ATIVO', true);
        $diretorio = $this->diretorioTemporario('chatbots_resposta_nao_array_');
        putenv(self::VARIAVEL_DO_DIRETORIO . '=' . $diretorio);
        LoggerFactory::reset();
        require_once dirname(__DIR__, 2) . '/inclusos/TelegramErrorLogger.php';

        $resposta = new class () implements \ArrayAccess {
            public function offsetExists(mixed $offset): bool
            {
                return $offset === 'ok';
            }

            public function offsetGet(mixed $offset): mixed
            {
                return false;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
            }

            public function offsetUnset(mixed $offset): void
            {
            }
        };

        \TelegramErrorLogger::log($resposta);

        $this->assertFileDoesNotExist(LoggerFactory::caminhoAppLogDeHoje());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testArquivoUsaLockingParaPreservarCadaLinhaJson(): void
    {
        define('LOGS_ATIVO', true);
        putenv(self::VARIAVEL_DO_DIRETORIO . '=' . $this->diretorioTemporario('chatbots_lock_'));
        LoggerFactory::reset();

        $logger = LoggerFactory::channel('locking');
        $this->assertInstanceOf(Logger::class, $logger);
        $handlers = $logger->getHandlers();
        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(RotatingFileHandler::class, $handlers[0]);
        $this->assertTrue($handlers[0]->getBubble());

        $useLocking = new \ReflectionProperty(StreamHandler::class, 'useLocking');
        $this->assertTrue($useLocking->getValue($handlers[0]));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResetFechaStreamsDosLoggersAntesDeDescartarOCache(): void
    {
        define('LOGS_ATIVO', true);
        putenv(self::VARIAVEL_DO_DIRETORIO . '=' . $this->diretorioTemporario('chatbots_reset_'));
        LoggerFactory::reset();

        $logger = LoggerFactory::channel('reset');
        $this->assertInstanceOf(Logger::class, $logger);
        $handlers = $logger->getHandlers();
        $this->assertCount(1, $handlers);
        $handler = $handlers[0];
        $this->assertInstanceOf(RotatingFileHandler::class, $handler);

        // O RotatingFileHandler fecha o primeiro stream depois de criar e
        // rotacionar o arquivo do dia; a segunda escrita deixa o handler
        // aberto, permitindo conferir o efeito de reset abaixo.
        $logger->info('cria o arquivo');
        $logger->info('abre o stream');
        $this->assertIsResource($handler->getStream());

        LoggerFactory::reset();

        $this->assertNull($handler->getStream());
    }

    public function testSanitizarCanalEChamavelDeForaERecusaNomeDeCaminho(): void
    {
        // O método é público de propósito: o `AppLogReader` da suíte e quem
        // monta nome de canal a partir de dado externo chamam daqui de fora.
        $this->assertSame('bd', LoggerFactory::sanitizarCanal('../../bd'));
        $this->assertSame('geral', LoggerFactory::sanitizarCanal(''));
        $this->assertSame('geral', LoggerFactory::sanitizarCanal('/'));
        $this->assertSame('geral', LoggerFactory::sanitizarCanal('..'));
    }

    public function testNomeDoAmbienteEChamavelDeForaEValeTestNaSuite(): void
    {
        $this->assertSame('test', RuntimeEnvironment::name());
        $this->assertTrue(RuntimeEnvironment::isTesting());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAmbienteSemAppEnvEProducao(): void
    {
        // Sem APP_ENV — ou com ela vazia — o padrão é PRODUÇÃO, nunca teste:
        // é o que impede um processo mal configurado de se declarar seguro.
        putenv('APP_ENV=');
        $this->assertSame('production', RuntimeEnvironment::name());

        putenv('APP_ENV');
        $this->assertSame('production', RuntimeEnvironment::name());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAsDuasCondicoesPrecisamValerParaOAmbienteSerDeTeste(): void
    {
        // As duas condições valem JUNTAS. Com uma só, bastaria APP_ENV=test
        // (que qualquer um define) para o bootstrap liberar a suíte.
        putenv('CHATBOTS_TESTING=0');
        $this->assertSame('test', RuntimeEnvironment::name());
        $this->assertFalse(RuntimeEnvironment::isTesting());

        putenv('APP_ENV=production');
        putenv('CHATBOTS_TESTING=1');
        $this->assertFalse(RuntimeEnvironment::isTesting());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function canaisProvider(): array
    {
        return [
            'nome simples' => ['erros_redis', 'erros_redis'],
            'path traversal vira basename' => ['../../etc/passwd', 'passwd'],
            'caminho absoluto vira basename' => ['/var/log/algo', 'algo'],
            'vazio cai no padrão' => ['', 'geral'],
            'ponto cai no padrão' => ['.', 'geral'],
            'ponto-ponto cai no padrão' => ['..', 'geral'],
            'barra cai no padrão' => ['/', 'geral'],
        ];
    }

    /**
     * O canal vira nome de arquivo no formatador e no filtro do operador; um
     * valor com `../` não pode escapar do diretório de logs — e o Logger nasce
     * já com o nome saneado.
     */
    #[DataProvider('canaisProvider')]
    public function testCanalEhSanitizadoParaBasenameEONomeDoLogger(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, LoggerFactory::sanitizarCanal($entrada));

        $logger = LoggerFactory::channel($entrada);
        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame($esperado, $logger->getName());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testForCliLigaOStderrEDescartaOsLoggersJaMontados(): void
    {
        LoggerFactory::reset();
        $antes = LoggerFactory::channel('phpunit_cli');
        LoggerFactory::forCli(true);
        $depois = LoggerFactory::channel('phpunit_cli');

        // O cache é limpo: o mesmo canal devolve outra instância, agora com o
        // handler de STDERR (é o que faz um job de cron falhar visível).
        $this->assertNotSame($antes, $depois);
        $this->assertInstanceOf(Logger::class, $depois);
        $this->assertSame([StreamHandler::class], $this->classesDosHandlers($depois));
    }

    /**
     * Chamar forCli() com o valor que já está em vigor é no-op: não pode
     * descartar os loggers em uso (o handler rotativo reabriria o arquivo a
     * cada chamada).
     */
    public function testForCliComOMesmoValorNaoDescartaOsLoggersJaCriados(): void
    {
        LoggerFactory::reset();
        $antes = LoggerFactory::channel('cache');
        LoggerFactory::forCli(false);

        $this->assertSame($antes, LoggerFactory::channel('cache'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCliComForcarEscreveNoArquivoEnoStderr(): void
    {
        // Falha operacional num worker: precisa ficar no arquivo (apuração
        // depois) E sair no STDERR (o systemd/cron captura na hora). Perder um
        // dos dois é perder metade do rastro do incidente.
        putenv(self::VARIAVEL_DO_DIRETORIO . '=' . $this->diretorioTemporario('chatbots_cli_forcado_'));
        LoggerFactory::reset();
        LoggerFactory::forCli(true);

        // getHandlers() devolve a pilha do Monolog: o último empilhado vem
        // primeiro, e é o STDERR.
        $logger = LoggerFactory::channel('falha-cli', true);
        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame(
            [StreamHandler::class, RotatingFileHandler::class],
            $this->classesDosHandlers($logger)
        );
    }

    public function testFormatadorJsonNaoDespejaOStacktraceNoLog(): void
    {
        // Um stacktrace tem dezenas de KB, caminhos internos e argumentos de
        // chamada. O contrato do log é UMA linha por evento: com o trace, uma
        // exceção sozinha enche o arquivo do dia e vaza caminho de servidor.
        LoggerFactory::reset();
        LoggerFactory::forCli(true);
        $logger = LoggerFactory::channel('excecao');
        $this->assertInstanceOf(Logger::class, $logger);

        // O STDERR do CLI está sempre no topo da pilha. O handler de arquivo
        // entra junto quando LOGS_ATIVO já foi definida no processo — e como
        // essa constante é global e irrevogável, outro arquivo da suíte pode
        // tê-la ligado antes. O que este contrato mede é o FORMATADOR, então o
        // handler é escolhido pelo papel, não pela posição na pilha.
        $stderr = null;
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof StreamHandler && !$handler instanceof RotatingFileHandler) {
                $stderr = $handler;
                break;
            }
        }
        $this->assertInstanceOf(StreamHandler::class, $stderr);
        $formatador = $stderr->getFormatter();
        $this->assertInstanceOf(\Monolog\Formatter\JsonFormatter::class, $formatador);

        $linha = $formatador->format(new \Monolog\LogRecord(
            new \DateTimeImmutable('2026-08-23 12:00:00'),
            'excecao',
            \Monolog\Level::Error,
            'pedido não enviado',
            ['exception' => new \RuntimeException('fornecedor recusou')]
        ));

        // `msg`, não `message`: o nome do campo é o do contrato da frota, e o
        // stack continua fora em TODO ambiente (o contrato permite fora de
        // produção; a frota escolheu o teto — ver o LoggerFactory).
        $this->assertStringContainsString('"msg":"pedido não enviado"', $linha);
        $this->assertStringContainsString('fornecedor recusou', $linha);
        $this->assertStringNotContainsString('"trace"', $linha);
        $this->assertStringNotContainsString('"message":"pedido', $linha);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testArquivoDeLogNasceComPermissao0600(): void
    {
        // O log guarda id de usuário, referência de pagamento e mensagem de
        // erro. Só o usuário do processo pode ler ou escrever; a permissão é
        // aplicada pelo próprio handler, não pelo umask.
        $diretorio = $this->diretorioTemporario('chatbots_permissao_arquivo_');
        putenv(self::VARIAVEL_DO_DIRETORIO . '=' . $diretorio);
        LoggerFactory::reset();

        // $forcar = true: independe de LOGS_ATIVO.
        LoggerFactory::channel('permissao', true)->error('linha de teste');

        $arquivo = LoggerFactory::caminhoAppLogDeHoje();
        $this->assertFileExists($arquivo);
        clearstatcache(true, $arquivo);
        $this->assertSame(0600, fileperms($arquivo) & 0777);
        clearstatcache(true, $diretorio);
        $this->assertSame(0700, fileperms($diretorio) & 0777);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResetDescartaOsLoggersEDesligaOModoCli(): void
    {
        $this->assertFalse(defined('LOGS_ATIVO'));
        LoggerFactory::forCli(true);
        $cli = LoggerFactory::channel('reiniciado');

        LoggerFactory::reset();
        $depois = LoggerFactory::channel('reiniciado');

        $this->assertNotSame($cli, $depois);
        $this->assertInstanceOf(Logger::class, $depois);
        $this->assertSame([NullHandler::class], $this->classesDosHandlers($depois));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testComLogsAtivoOCanalGravaNoArquivoRotativo(): void
    {
        define('LOGS_ATIVO', true);
        putenv(self::VARIAVEL_DO_DIRETORIO . '=' . $this->diretorioTemporario('chatbots_arquivo_'));
        LoggerFactory::reset();

        $logger = LoggerFactory::channel('phpunit_arquivo');
        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame([RotatingFileHandler::class], $this->classesDosHandlers($logger));
    }

    /**
     * Diretório impossível de criar: logar NUNCA pode derrubar o fluxo do bot.
     * Mesmo fora do CLI e sem $forcar, logs habilitados precisam de contingência —
     * o evento segue para stderr e o update continua sendo atendido.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDiretorioImpossivelUsaStderrEmVezDePerderOEvento(): void
    {
        define('LOGS_ATIVO', true);
        // /dev/null é arquivo: nenhum mkdir consegue criar um diretório sob ele.
        putenv(self::VARIAVEL_DO_DIRETORIO . '=/dev/null/chatbots-impossivel');
        LoggerFactory::reset();

        $logger = LoggerFactory::channel('phpunit_sem_disco');
        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame([StreamHandler::class], $this->classesDosHandlers($logger));
    }

    /**
     * Com $forcar e sem conseguir o arquivo, a mensagem ainda precisa sair por
     * algum lugar: o STDERR entra como contingência.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testForcarSemDiscoCaiNoStderrComoContingencia(): void
    {
        putenv(self::VARIAVEL_DO_DIRETORIO . '=/dev/null/chatbots-impossivel');
        LoggerFactory::reset();

        $logger = LoggerFactory::channel('erros_redis', true);
        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame([StreamHandler::class], $this->classesDosHandlers($logger));
    }

    /**
     * @return list<class-string>
     */
    private function classesDosHandlers(Logger $logger): array
    {
        $classes = [];
        foreach ($logger->getHandlers() as $handler) {
            $classes[] = $handler::class;
        }

        return $classes;
    }
}
