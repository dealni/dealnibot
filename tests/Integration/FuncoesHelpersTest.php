<?php

declare(strict_types=1);

namespace Tests;

use App\Support\LoggerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppLogReader;

/**
 * Testes dos helpers de inclusos/funcoes.php.
 * O dealnibot é minimalista: expõe enderecoDoRedis(), conectarRedis(),
 * escreverLog(), iniciarLoggerCli() e ecoarEEncerrar().
 *
 * enderecoDoRedis() e escreverLog() são testadas diretamente (leitura do
 * ambiente e gravação de uma linha JSON no app.log, ambas sem rede).
 * conectarRedis() instancia a classe Redis internamente e encerra o processo
 * com exit() em caso de falha, então é exercitada num subprocesso `php -n`
 * (sem ext-redis carregada) com um stub puro de Redis: nenhuma conexão de rede
 * real é feita e o exit() da função nunca derruba o processo do PHPUnit.
 *
 * O destino do log mudou de logs/{canal}.log (texto) para uma linha JSON em
 * logs/app-AAAA-MM-DD.log (Monolog), com o antigo nome de arquivo virando o
 * NOME DO CANAL. A suíte aponta DEALNI_LOGS_DIR para um diretório temporário
 * (tests/bootstrap.php), então nada é escrito no logs/ de produção.
 */
final class FuncoesHelpersTest extends TestCase
{
    /** Código de saída convencionado no stub para "ext-redis presente mesmo com php -n". */
    private const CODIGO_STUB_INDISPONIVEL = 97;

    /** @var array<string, string|false> Valor original das variáveis de ambiente alteradas. */
    private array $ambienteOriginal = [];

    private int $tamanhoLogAntes = 0;

    /**
     * A escreverLog() canônica respeita LOGS_ATIVO: sem a constante, nada é
     * gravado. Em produção o dados_bot.php a define como true.
     *
     * A definição fica AQUI, e não em setUpBeforeClass(), porque os dois testes
     * marcados com #[RunInSeparateProcess] precisam do processo SEM a
     * constante — e o setUpBeforeClass roda também no processo-filho.
     */
    private function ligarLogs(): void
    {
        if (!defined('LOGS_ATIVO')) {
            define('LOGS_ATIVO', true);
        }
        LoggerFactory::reset();
        $this->tamanhoLogAntes = AppLogReader::tamanhoAtual();
    }

    protected function setUp(): void
    {
        LoggerFactory::reset();
        $this->tamanhoLogAntes = AppLogReader::tamanhoAtual();
    }

    protected function tearDown(): void
    {
        // Restaura REDIS_HOST/REDIS_PORT: o phpunit.xml as define para o
        // processo inteiro e outros testes leem os mesmos valores.
        foreach ($this->ambienteOriginal as $nome => $valor) {
            if ($valor === false) {
                putenv($nome);

                continue;
            }
            putenv("{$nome}={$valor}");
        }
        $this->ambienteOriginal = [];
    }

    /**
     * Trava os valores padrão dos parâmetros sem precisar escrever no log nem
     * abrir conexão real: se alguém mudar o padrão ('geral' / false / false),
     * este teste quebra.
     *
     * @throws \ReflectionException
     */
    public function testValoresPadraoDosParametrosDosHelpers(): void
    {
        $escreverLog = new \ReflectionFunction('escreverLog');
        $parametrosLog = $escreverLog->getParameters();
        $this->assertCount(5, $parametrosLog);
        $this->assertSame('info', $parametrosLog[3]->getDefaultValue());
        $this->assertSame([], $parametrosLog[4]->getDefaultValue());
        $this->assertSame('mensagem', $parametrosLog[0]->getName());
        $this->assertFalse($parametrosLog[0]->isOptional());
        $this->assertSame('arquivo_log', $parametrosLog[1]->getName());
        $this->assertSame('geral', $parametrosLog[1]->getDefaultValue());
        // O 3º parâmetro é o que permite registrar falha de infraestrutura
        // mesmo com LOGS_ATIVO=false; o padrão precisa continuar false, senão
        // todo log de negócio passaria a ignorar a flag.
        $this->assertSame('forcar', $parametrosLog[2]->getName());
        $this->assertFalse($parametrosLog[2]->getDefaultValue());

        $conectarRedis = new \ReflectionFunction('conectarRedis');
        $parametrosRedis = $conectarRedis->getParameters();
        $this->assertCount(2, $parametrosRedis);
        $this->assertSame('persistent', $parametrosRedis[0]->getName());
        $this->assertFalse($parametrosRedis[0]->getDefaultValue());
        $this->assertSame('cliente', $parametrosRedis[1]->getName());
        $this->assertNull($parametrosRedis[1]->getDefaultValue());
    }

    // ---------------------------------------------------------------------
    // Endereço do Redis lido do ambiente
    // ---------------------------------------------------------------------

    /**
     * Define uma variável de ambiente guardando o valor anterior para o tearDown.
     */
    private function definirAmbiente(string $nome, ?string $valor): void
    {
        if (!array_key_exists($nome, $this->ambienteOriginal)) {
            $this->ambienteOriginal[$nome] = getenv($nome);
        }

        if ($valor === null) {
            putenv($nome);

            return;
        }
        putenv("{$nome}={$valor}");
    }

    public function testEnderecoUsaHostEPortaDoAmbiente(): void
    {
        $this->definirAmbiente('REDIS_HOST', 'redis.interno');
        $this->definirAmbiente('REDIS_PORT', '6380');

        $this->assertSame(['host' => 'redis.interno', 'port' => 6380], enderecoDoRedis());
    }

    /**
     * O padrão é 127.0.0.1, e não 'localhost': 'localhost' pode resolver para
     * ::1 antes do IPv4 e o Redis padrão só escuta em IPv4 — a conexão falharia
     * dependendo do /etc/hosts da máquina. Quem depender do nome pode continuar
     * definindo REDIS_HOST=localhost.
     */
    public function testEnderecoSemVariaveisCaiNoPadraoDeProducao(): void
    {
        $this->definirAmbiente('REDIS_HOST', null);
        $this->definirAmbiente('REDIS_PORT', null);

        $this->assertSame(['host' => '127.0.0.1', 'port' => 6380], enderecoDoRedis());
    }

    /**
     * Valores que NÃO são porta válida: cada um precisa cair no 6380 em vez de
     * virar um inteiro truncado (o cast direto transformaria '6380.9' em 6380
     * e '-1' em -1, e o connect() morreria com uma porta que ninguém escreveu).
     *
     * @return array<string, array{string}>
     */
    public static function portasInvalidasProvider(): array
    {
        return [
            'vazia' => [''],
            'texto' => ['seis mil'],
            'decimal' => ['6380.9'],
            'negativa' => ['-1'],
            'com espaço' => [' 6380'],
            'acima do limite TCP' => ['65536'],
            'acima do limite inteiro' => ['999999999999999999999999'],
        ];
    }

    #[DataProvider('portasInvalidasProvider')]
    public function testPortaInvalidaCaiNoPadrao(string $porta): void
    {
        $this->definirAmbiente('REDIS_PORT', $porta);

        $this->assertSame(6380, enderecoDoRedis()['port']);
    }

    public function testHostVazioEPortaZeroUsamOEndpointDoBot(): void
    {
        // Host vazio e porta fora do intervalo não podem selecionar outro bot.
        $this->definirAmbiente('REDIS_HOST', '');
        $this->definirAmbiente('REDIS_PORT', '0');

        $this->assertSame(['host' => '127.0.0.1', 'port' => 6380], enderecoDoRedis());
    }

    // ---------------------------------------------------------------------
    // escreverLog(): uma linha JSON por evento, canal = 2º argumento
    // ---------------------------------------------------------------------

    /**
     * Entradas JSON acrescentadas ao app.log desde o setUp deste teste.
     *
     * @return list<array<string, mixed>>
     */
    private function entradasNovas(): array
    {
        return AppLogReader::linhasJson(AppLogReader::conteudoDesde($this->tamanhoLogAntes));
    }

    public function testEscreverLogGravaUmaLinhaJsonComCanalMensagemEHorario(): void
    {
        $this->ligarLogs();
        $canal = 'phpunit_' . uniqid();
        $mensagem = 'mensagem de teste phpunit: çãõ *_`[';

        $antes = time();
        escreverLog($mensagem, $canal);
        $depois = time();

        $entradas = $this->entradasNovas();
        $this->assertCount(1, $entradas);
        $entrada = $entradas[0];
        $this->assertSame($mensagem, $entrada['msg']);
        $this->assertSame($canal, $entrada['channel']);
        $this->assertSame('info', $entrada['level']);

        // O horário gravado precisa ser o da chamada.
        $this->assertIsString($entrada['ts']);
        $timestamp = strtotime($entrada['ts']);
        $this->assertIsInt($timestamp);
        $this->assertGreaterThanOrEqual($antes, $timestamp);
        $this->assertLessThanOrEqual($depois, $timestamp);
    }

    /**
     * O diretório de logs pode não existir num ambiente recém-implantado: o
     * factory precisa criá-lo em vez de perder a mensagem em silêncio.
     */
    public function testEscreverLogCriaODiretorioDeLogsQuandoAusente(): void
    {
        $this->ligarLogs();
        $diretorio = sys_get_temp_dir() . '/dealnibot-testes-logs-ausente-' . uniqid();
        $original = getenv('DEALNI_LOGS_DIR');
        $this->assertIsString($original);

        try {
            putenv('DEALNI_LOGS_DIR=' . $diretorio);
            LoggerFactory::reset();
            $this->assertDirectoryDoesNotExist($diretorio);

            escreverLog('linha em diretorio recem-criado', 'phpunit_' . uniqid());

            $this->assertDirectoryExists($diretorio);
            $arquivo = LoggerFactory::caminhoAppLogDeHoje();
            $this->assertFileExists($arquivo);
            $conteudo = (string) file_get_contents($arquivo);
            $this->assertStringContainsString('linha em diretorio recem-criado', $conteudo);
        } finally {
            $arquivos = glob($diretorio . '/*');
            foreach ($arquivos === false ? [] : $arquivos as $arquivo) {
                @unlink($arquivo);
            }
            @rmdir($diretorio);
            putenv('DEALNI_LOGS_DIR=' . $original);
            LoggerFactory::reset();
        }
    }

    /**
     * Mensagens de borda que passam por escreverLog(): o texto precisa sair
     * íntegro no campo `message`, sem trim, sem truncar e sem "otimização" que
     * descarte a entrada.
     *
     * @return array<string, array{string}>
     */
    public static function mensagensDeBordaProvider(): array
    {
        return [
            // Mensagem vazia continua sendo um evento: precisa gerar linha, e
            // não sumir. É o log de "algo aconteceu e ninguém montou o texto".
            'vazia' => [''],
            // "0" é o falso-negativo clássico de empty(): não pode ser
            // descartado como se fosse ausência de mensagem.
            'zero textual' => ['0'],
            'só espaços' => ['   '],
            'acentuação e cedilha' => ['Não foi possível conectar à sessão de configuração'],
            'emoji fora do BMP' => ['falha ao enviar 🚀📚😼'],
            'caracteres de Markdown' => ['token *_`[]() inválido'],
            'aspas e barras' => ['{"ok":false,"description":"C:\\\\temp\\\\x"}'],
            'string longa' => ['x'],
        ];
    }

    #[DataProvider('mensagensDeBordaProvider')]
    public function testEscreverLogPreservaAMensagemNaIntegra(string $mensagem): void
    {
        $this->ligarLogs();
        if ($mensagem === 'x') {
            // 64 KiB: uma resposta de erro da API do Telegram/OpenAI inteira.
            $mensagem = str_repeat('x', 65536);
        }

        escreverLog($mensagem, 'phpunit_' . uniqid());

        $entradas = $this->entradasNovas();
        $this->assertCount(1, $entradas);
        $this->assertSame(
            $mensagem,
            $entradas[0]['msg'],
            'A mensagem gravada precisa ser idêntica à recebida.'
        );
    }

    /**
     * Regressão do defeito que este arquivo congelava enquanto o log era texto
     * puro: escreverLog() não escapava quebra de linha, então uma mensagem
     * multilinha (um stack trace, ou um corpo JSON pretty-printed vindo de uma
     * API) virava VÁRIAS linhas no arquivo e só a primeira carregava o
     * timestamp — quem lia com `grep`/`tail` depois de um incidente perdia a
     * correlação das linhas seguintes.
     *
     * A migração para o app.log JSON fechou isso ESTRUTURALMENTE: um evento é
     * uma linha, e a quebra vive escapada dentro do campo `message`. Este teste
     * passou a travar o comportamento novo, para que uma volta ao formato em
     * texto não passe despercebida.
     */
    public function testMensagemMultilinhaContinuaSendoUmaLinhaSoNoArquivo(): void
    {
        $this->ligarLogs();
        escreverLog("primeira parte\nsegunda parte", 'phpunit_' . uniqid());

        $trecho = AppLogReader::conteudoDesde($this->tamanhoLogAntes);
        // O filtro descarta APENAS linha vazia: com o callback padrão do
        // array_filter uma linha "0" também sumiria, e linha assim é evento
        // quebrado — exatamente o que este teste existe para pegar.
        $linhasFisicas = array_values(array_filter(
            explode("\n", rtrim($trecho, "\r\n")),
            static fn (string $linha): bool => $linha !== ''
        ));
        $this->assertCount(1, $linhasFisicas, 'Um evento tem que ocupar exatamente uma linha.');

        $entradas = $this->entradasNovas();
        $this->assertCount(1, $entradas);
        $this->assertSame("primeira parte\nsegunda parte", $entradas[0]['msg']);
    }

    /**
     * INVERTIDO em 2026-08-30 pelo contrato de observabilidade da frota: o
     * horário do evento sai em UTC, e não mais no fuso do processo. O teste
     * anterior travava exatamente o oposto (`-03:00` no fim da string).
     *
     * A troca não é cosmética. Log em fuso local só se correlaciona com outro
     * log do mesmo fuso; e o campo `ts` do contrato existe para ordenar
     * eventos de serviços diferentes na mesma linha do tempo. Correlacionar
     * com o horário relatado pelo usuário continua possível — é uma conversão,
     * e a conversão só é possível porque o fuso está declarado no valor.
     */
    public function testHorarioDoLogSaiEmUtcMesmoComOProcessoEmOutroFuso(): void
    {
        $this->ligarLogs();
        $fusoOriginal = date_default_timezone_get();

        try {
            // São Paulo é UTC-3 o ano inteiro (sem horário de verão desde 2019),
            // então um `ts` que herdasse o fuso do processo ficaria três horas
            // atrás do instante medido aqui.
            date_default_timezone_set('America/Sao_Paulo');
            LoggerFactory::reset();

            // A linha-base é recapturada DEPOIS de trocar o fuso, de propósito.
            // O nome do arquivo é `app-<date('Y-m-d')>.log`, e por isso ele
            // depende do fuso do processo: das 21h às 24h em São Paulo a data
            // local e a UTC são diferentes, então a linha-base tirada no fuso
            // anterior apontava para OUTRO arquivo e o trecho "novo" trazia o
            // dia inteiro. O teste falhava só nessa janela de três horas.
            $this->tamanhoLogAntes = AppLogReader::tamanhoAtual();

            $antes = time();
            escreverLog('marca em Sao Paulo', 'phpunit_' . uniqid());
            $depois = time();

            $entradas = $this->entradasNovas();
            $this->assertCount(1, $entradas);
            $ts = $entradas[0]['ts'] ?? null;
            $this->assertIsString($ts);
            $this->assertMatchesRegularExpression(
                '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/',
                $ts
            );

            // O instante continua sendo o da chamada: o que mudou é a forma de
            // escrevê-lo, não o relógio.
            $instante = strtotime($ts);
            $this->assertIsInt($instante);
            $this->assertGreaterThanOrEqual($antes, $instante);
            $this->assertLessThanOrEqual($depois, $instante);
        } finally {
            date_default_timezone_set($fusoOriginal);
            LoggerFactory::reset();
        }
    }

    public function testEscreverLogAcrescentaSemSobrescreverConteudoAnterior(): void
    {
        $this->ligarLogs();
        $canal = 'phpunit_' . uniqid();

        escreverLog('primeira linha', $canal);
        escreverLog('segunda linha', $canal);

        $entradas = $this->entradasNovas();
        $this->assertCount(2, $entradas);
        $this->assertSame('primeira linha', $entradas[0]['msg']);
        $this->assertSame('segunda linha', $entradas[1]['msg']);
    }

    /**
     * basename('/') degenera para string vazia: sem o fallback do factory o
     * canal viraria um nome inválido. Também é a proteção contra path traversal
     * vinda do 2º argumento.
     */
    public function testCanalDegeneradoCaiNoCanalGeral(): void
    {
        $this->ligarLogs();
        $marcador = 'phpunit_fallback_' . uniqid();

        escreverLog($marcador, '/');

        $entradas = $this->entradasNovas();
        $this->assertCount(1, $entradas);
        $this->assertSame($marcador, $entradas[0]['msg']);
        $this->assertSame('geral', $entradas[0]['channel']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSemLogsAtivoNadaEhGravado(): void
    {
        // Processo de produção sem dados_bot.php carregado (ou com a flag
        // desligada) não pode escrever nada em disco.
        $this->assertFalse(defined('LOGS_ATIVO'));
        $antes = AppLogReader::tamanhoAtual();

        escreverLog('mensagem que nao deve ser gravada', 'phpunit_silencioso');

        $this->assertSame('', AppLogReader::conteudoDesde($antes));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testForcarGravaMesmoComLogsDesativados(): void
    {
        // É o que garante que uma falha de infraestrutura (Redis fora do ar)
        // fique registrada mesmo num bot com LOGS_ATIVO=false.
        define('LOGS_ATIVO', false);
        LoggerFactory::reset();
        $antes = AppLogReader::tamanhoAtual();

        escreverLog('falha de infraestrutura forcada', 'erros_redis', true);

        $entradas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($antes));
        $this->assertCount(1, $entradas);
        $this->assertSame('falha de infraestrutura forcada', $entradas[0]['msg']);
        $this->assertSame('erros_redis', $entradas[0]['channel']);
    }

    /**
     * iniciarLoggerCli() é o que os entrypoints de terminal (bot.php) e os jobs
     * de cron chamam para ligar o STDERR JSON: sem ele, um job que falha com
     * LOGS_ATIVO=false não deixaria rastro nenhum no e-mail do cron.
     */
    public function testIniciarLoggerCliLigaOStderrDosProcessosDeTerminal(): void
    {
        try {
            iniciarLoggerCli();

            $logger = LoggerFactory::channel('phpunit_cli_' . uniqid());
            if (!$logger instanceof \Monolog\Logger) {
                self::fail('LoggerFactory precisa devolver um Monolog\\Logger.');
            }
            $tipos = array_map(static fn (object $h): string => $h::class, $logger->getHandlers());
            $this->assertContains(\Monolog\Handler\StreamHandler::class, $tipos);
        } finally {
            // Estado global: sem isto os testes seguintes escreveriam no STDERR
            // do processo do PHPUnit.
            LoggerFactory::forCli(false);
            LoggerFactory::reset();
        }
    }

    // ---------------------------------------------------------------------
    // conectarRedis(): stub puro num subprocesso `php -n`
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function providerTipoDeConexao(): array
    {
        return [
            'conexão normal usa connect()' => [false, 'connect'],
            'conexão persistente usa pconnect()' => [true, 'pconnect'],
        ];
    }

    #[DataProvider('providerTipoDeConexao')]
    public function testConectarRedisConectaPingaERetornaOCliente(bool $persistent, string $metodoEsperado): void
    {
        $resultado = $this->executarConectarRedisComStub('return true;', 'return true;', $persistent);
        $this->pularSeStubImpossivel($resultado);

        $this->assertSame(0, $resultado['codigo']);
        // Assert exato da sequência: método certo (connect × pconnect, nunca os
        // dois), host/porta configurada para testes, timeout de conexão e de
        // leitura, ping de verificação e retorno da MESMA instância criada
        // dentro da função.
        $this->assertSame('OK Redis ' . $metodoEsperado . ':127.0.0.1:1:2:2,ping', $resultado['saida']);
    }

    /**
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function providerTipoDeConexaoNoLog(): array
    {
        return [
            'conexão normal' => [false, 'normal'],
            'conexão persistente' => [true, 'persistente'],
        ];
    }

    #[DataProvider('providerTipoDeConexaoNoLog')]
    public function testConectarRedisEncerraComMensagemELogQuandoConexaoFalha(bool $persistent, string $tipoEsperadoNoLog): void
    {
        $this->ligarLogs();
        $resultado = $this->executarConectarRedisComStub('return false;', 'return true;', $persistent);
        $this->pularSeStubImpossivel($resultado);

        // Em falha, a função imprime a mensagem e encerra com exit(1)
        // (status de erro visível para monitoramento); o "OK ..." final do
        // script não pode aparecer (a função nunca retorna).
        $this->assertSame(1, $resultado['codigo']);
        $this->assertSame('Erro: Serviço Redis não está disponível. Tente novamente mais tarde.', $resultado['saida']);

        $this->assertMensagemNoCanalErrosRedis(
            "Redis recusou a operação"
        );
    }

    /**
     * A partir do phpredis 6 connect()/pconnect() LANÇAM RedisException em vez
     * de devolver false. Sem o catch em conectarRedis(), a queda do Redis
     * virava exceção não tratada — HTTP 500 em todo webhook — e o tratamento
     * (log, mensagem amigável, exit 1) nunca chegava a rodar.
     */
    public function testConexaoQueLancaExcecaoRecebeOMesmoTratamentoDeFalha(): void
    {
        $this->ligarLogs();
        $resultado = $this->executarConectarRedisComStub(
            'throw new RuntimeException(\'connection refused\');',
            'return true;',
            false
        );
        $this->pularSeStubImpossivel($resultado);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertSame('Erro: Serviço Redis não está disponível. Tente novamente mais tarde.', $resultado['saida']);
        $this->assertMensagemNoCanalErrosRedis('Falha de conexão Redis');
    }

    public function testConectarRedisEncerraComMensagemELogQuandoPingFalha(): void
    {
        $this->ligarLogs();
        $resultado = $this->executarConectarRedisComStub(
            'return true;',
            'throw new Exception(\'falha simulada de ping\');',
            false
        );
        $this->pularSeStubImpossivel($resultado);

        // Ping quebrado também imprime a mensagem e encerra com exit(1).
        $this->assertSame(1, $resultado['codigo']);
        $this->assertSame('Erro: Serviço Redis não está funcionando. Tente novamente mais tarde.', $resultado['saida']);

        $this->assertMensagemNoCanalErrosRedis('Erro: Redis não está respondendo: falha simulada de ping');
    }

    /**
     * ping() que devolve false (sem lançar) também é Redis quebrado: a checagem
     * `=== false` é o que separa "respondeu" de "não respondeu".
     */
    public function testPingQueDevolveFalseTambemEncerraComErro(): void
    {
        $this->ligarLogs();
        $resultado = $this->executarConectarRedisComStub('return true;', 'return false;', false);
        $this->pularSeStubImpossivel($resultado);

        $this->assertSame(1, $resultado['codigo']);
        $this->assertSame('Erro: Serviço Redis não está funcionando. Tente novamente mais tarde.', $resultado['saida']);
        $this->assertMensagemNoCanalErrosRedis('Erro: Redis não está respondendo: Redis não respondeu ao ping.');
    }

    private function assertMensagemNoCanalErrosRedis(string $trecho): void
    {
        $entradas = $this->entradasNovas();
        $this->assertNotEmpty($entradas, 'A falha de infraestrutura precisa ficar registrada no app.log.');
        $ultima = $entradas[count($entradas) - 1];
        $this->assertSame('erros_redis', $ultima['channel']);
        $this->assertSame('error', $ultima['level']);
        $this->assertIsArray($ultima['context']);
        $this->assertContains($ultima['context']['phase'], ['connect', 'auth', 'ping']);
        $this->assertIsString($ultima['msg']);
        $this->assertStringContainsString($trecho, $ultima['msg']);
    }

    /**
     * Executa conectarRedis() num subprocesso `php -n` (nenhuma extensão
     * compartilhada carregada, logo sem ext-redis) usando um stub puro de
     * Redis que registra cada chamada. Sem rede real; o exit() interno da
     * função encerra apenas o subprocesso.
     *
     * @param string $corpoConexao Código PHP executado em connect()/pconnect() após registrar a chamada
     * @param string $corpoPing Código PHP executado em ping() após registrar a chamada
     * @param bool $persistent Valor repassado a conectarRedis()
     * @return array{codigo: int, saida: string}
     */
    private function executarConectarRedisComStub(string $corpoConexao, string $corpoPing, bool $persistent): array
    {
        $raiz = dirname(__DIR__, 2);
        $caminhoFuncoes = $raiz . '/inclusos/funcoes.php';
        $diretorioLogs = LoggerFactory::diretorioLogs();

        // vendor/autoload.php NÃO pode ser usado aqui: o platform_check gerado
        // pelo Composer exige ext-redis, justamente a extensão que o `php -n`
        // derruba para o stub poder existir. Um autoloader PSR-4 mínimo carrega
        // o que escreverLog() precisa (App\Support\LoggerFactory + Monolog).
        $autoloader = '<?php' . "\n"
            . '$raizProjeto = ' . var_export($raiz, true) . ';' . "\n"
            . 'spl_autoload_register(static function (string $classe) use ($raizProjeto): void {' . "\n"
            . '    $prefixos = array(' . "\n"
            . '        "App\\\\" => $raizProjeto . "/src/",' . "\n"
            . '        "Monolog\\\\" => $raizProjeto . "/vendor/monolog/monolog/src/Monolog/",' . "\n"
            . '        "Psr\\\\Log\\\\" => $raizProjeto . "/vendor/psr/log/src/",' . "\n"
            . '    );' . "\n"
            . '    foreach ($prefixos as $prefixo => $base) {' . "\n"
            . '        if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0) { continue; }' . "\n"
            . '        $arquivo = $base . str_replace("\\\\", "/", substr($classe, strlen($prefixo))) . ".php";' . "\n"
            . '        if (is_file($arquivo)) { require $arquivo; }' . "\n"
            . '        return;' . "\n"
            . '    }' . "\n"
            . '});' . "\n";

        // A classe stub é declarada dentro de um if para impedir o early
        // binding do PHP: o guard de class_exists precisa rodar ANTES de a
        // classe existir, senão ele enxergaria o próprio stub.
        $script = $autoloader
            . 'if (class_exists(\'Redis\', false)) { exit(' . self::CODIGO_STUB_INDISPONIVEL . '); }' . "\n"
            . 'if (true) {' . "\n"
            . '    class Redis' . "\n"
            . '    {' . "\n"
            . '        /** @var array<int, string> */' . "\n"
            . '        public $chamadas = array();' . "\n"
            . '        public function connect($host, $port, $timeout = null, $persistentId = null, $retryInterval = null, $readTimeout = null, $context = null) { $this->chamadas[] = "connect:{$host}:{$port}:{$timeout}:{$readTimeout}"; ' . $corpoConexao . ' }' . "\n"
            . '        public function pconnect($host, $port, $timeout = null, $persistentId = null, $retryInterval = null, $readTimeout = null, $context = null) { $this->chamadas[] = "pconnect:{$host}:{$port}:{$timeout}:{$readTimeout}"; ' . $corpoConexao . ' }' . "\n"
            . '        public function auth($senha) { return true; }' . "\n"
            . '        public function ping() { $this->chamadas[] = \'ping\'; ' . $corpoPing . ' }' . "\n"
            . '    }' . "\n"
            . '}' . "\n"
            . 'putenv(' . var_export('DEALNI_LOGS_DIR=' . $diretorioLogs, true) . ');' . "\n"
            . 'define(\'LOGS_ATIVO\', true);' . "\n"
            . 'require ' . var_export($caminhoFuncoes, true) . ';' . "\n"
            . '$redis = conectarRedis(' . var_export($persistent, true) . ');' . "\n"
            . 'echo \'OK \' . get_class($redis) . \' \' . implode(\',\', $redis->chamadas);' . "\n";

        $caminhoScript = tempnam(sys_get_temp_dir(), 'phpunit_redis_stub_');
        if ($caminhoScript === false) {
            self::fail('Não foi possível criar o script temporário do stub de Redis.');
        }

        try {
            $this->assertNotFalse(file_put_contents($caminhoScript, $script));

            $binario = escapeshellarg(PHP_BINARY);
            // `php -n` também remove date.timezone. Entre 21h e meia-noite em
            // São Paulo, o filho caía em UTC e o RotatingFileHandler escrevia
            // no arquivo do dia seguinte, enquanto o processo pai procurava o
            // arquivo do dia local. Preserve explicitamente o fuso do teste.
            $fuso = escapeshellarg('date.timezone=' . date_default_timezone_get());
            $comando = $binario . ' -n -d ' . $fuso . $this->extensoesExigidasPeloFilho()
                . ' ' . escapeshellarg($caminhoScript) . ' 2>&1';
            $linhas = [];
            $codigo = -1;
            exec($comando, $linhas, $codigo);
        } finally {
            @unlink($caminhoScript);
        }

        return ['codigo' => $codigo, 'saida' => implode("\n", $linhas)];
    }

    /**
     * `-n` existe para o filho não carregar ext-redis, mas ele derruba TODAS as
     * extensões que vêm por ini — e a distribuição decide quais são essas. No
     * Homebrew (macOS) ctype é compilada estaticamente e sobrevive ao `-n`; no
     * Debian/Ubuntu (o CI) ela é um .so carregado por conf.d e some. Como
     * conectarRedis() chama ctype_digit() para ler REDIS_PORT, o filho morria
     * com "Call to undefined function ctype_digit()" — fatal, exit 255 — antes
     * de qualquer asserção, e o CI ficava vermelho enquanto a suíte local
     * passava. O mesmo vale para mbstring/json, que o Monolog usa ao formatar a
     * linha do app.log.
     *
     * Recarrega explicitamente só as extensões que o caminho exercitado usa.
     * A imagem oficial do PHP pode deixar o .so no extension_dir e ainda
     * compilar a extensão no binário; por isso consultamos outro processo com
     * `-n` antes de decidir. ext-redis continua de fora — é o motivo de o `-n`
     * existir.
     */
    private function extensoesExigidasPeloFilho(): string
    {
        $diretorio = ini_get('extension_dir');
        if (!is_string($diretorio) || $diretorio === '') {
            return '';
        }

        $linhas = [];
        $codigo = -1;
        exec(
            escapeshellarg(PHP_BINARY)
                . ' -n -r '
                . escapeshellarg('echo implode("\n", get_loaded_extensions());'),
            $linhas,
            $codigo
        );
        $carregadasSemIni = $codigo === 0 ? array_map('strtolower', $linhas) : [];

        $argumentos = '';
        foreach (['ctype', 'json', 'mbstring'] as $extensao) {
            if (!in_array(strtolower($extensao), $carregadasSemIni, true)
                && is_file(rtrim($diretorio, '/') . '/' . $extensao . '.so')) {
                $argumentos .= ' -d ' . escapeshellarg('extension=' . $extensao);
            }
        }

        return $argumentos;
    }

    /**
     * @param array{codigo: int, saida: string} $resultado
     */
    private function pularSeStubImpossivel(array $resultado): void
    {
        if ($resultado['codigo'] === self::CODIGO_STUB_INDISPONIVEL) {
            self::markTestSkipped('ext-redis carregada mesmo com php -n: não dá para substituir a classe Redis por stub.');
        }

        // Qualquer outro desfecho anormal do subprocesso tem que chegar ao
        // relatório com a saída real. Sem isto, uma quebra de ambiente aparece
        // como "Failed asserting that 255 is identical to 0" e não diz nada.
        if ($resultado['codigo'] !== 0 && $resultado['codigo'] !== 1) {
            self::fail(
                'Subprocesso do stub de Redis terminou com código inesperado '
                    . $resultado['codigo'] . '. Saída: ' . $resultado['saida']
            );
        }
    }

    public function testRegistrarFalhaDoRoteadorGravaClasseMensagemEOrigem(): void
    {
        // O que esta função existe para impedir: exceção de comando escapando
        // do require do roteador sem deixar rastro. O 200 já foi ao Telegram,
        // então não há erro HTTP nem reentrega para denunciar o problema — a
        // linha de log é a única evidência que sobra.
        if (!defined('LOGS_ATIVO')) {
            define('LOGS_ATIVO', true);
        }
        LoggerFactory::reset();

        $antes = AppLogReader::tamanhoAtual();
        $erro = new \RuntimeException('id_telegram inválido no contexto do script.');

        registrarFalhaDoRoteador($erro);

        $linhas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($antes));
        $this->assertCount(1, $linhas);
        $this->assertSame('roteador_erros', $linhas[0]['channel']);

        $mensagem = $linhas[0]['msg'];
        $this->assertIsString($mensagem);
        // Classe e mensagem identificam O QUE quebrou; arquivo e linha, ONDE —
        // e getFile() aponta o comando exato, que é o dado que falta ao operador.
        $this->assertStringContainsString('RuntimeException', $mensagem);
        $this->assertStringContainsString('id_telegram inválido', $mensagem);
        $this->assertStringContainsString($erro->getFile() . ':' . $erro->getLine(), $mensagem);
    }
}
