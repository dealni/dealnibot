<?php

declare(strict_types=1);

namespace Tests;

use App\Support\LoggerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppLogReader;

require_once __DIR__ . '/../../inclusos/caminhos.php';
require_once __DIR__ . '/../../inclusos/funcoes_sondas.php';

/**
 * As sondas REAIS de `/ready`, exercitadas contra dependência de verdade.
 *
 * `SondaSaudeEProntidaoTest` prova a decisão de status com closures; aqui o que
 * se prova é o outro lado: que a checagem de PostgreSQL é mesmo uma LEITURA do
 * banco, e não um `is_file()` disfarçado que responderia `ok` sobre um arquivo
 * vazio — o modo de falha que faria a sonda esconder exatamente o incidente que
 * ela existe para achar.
 *
 * Este é o arquivo que carrega o valor por bot da observabilidade, junto com
 * `inclusos/funcoes_sondas.php`: a lista de dependências muda de bot para bot,
 * e é aqui que o literal dela vive.
 */
final class SondasDeDependenciaTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    /** As dependências reais de `/ready`, na ordem do bloco de dados de `inclusos/funcoes_sondas.php`. */
    private const DEPENDENCIAS = ['postgresql', 'redis'];

    /** Arquivo de produto que define `enderecoDoRedis()`, o cliente principal. */
    private const ARQUIVO_DO_ENDERECO_DO_REDIS = 'inclusos/funcoes.php';
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    /** @var list<string> */
    private array $descartaveis = [];

    private string|false $dsnOriginal = false;
    protected function setUp(): void
    {
        $this->dsnOriginal = getenv('TEST_DATABASE_DSN');
    }

    protected function tearDown(): void
    {
        foreach ($this->descartaveis as $base) {
            if (is_file($base)) {
                unlink($base);
            }
        }
        $this->descartaveis = [];
        putenv($this->dsnOriginal === false ? 'TEST_DATABASE_DSN' : 'TEST_DATABASE_DSN=' . $this->dsnOriginal);
    }

    public function testSondasDeDependenciaEntregaAsDuasDependenciasReais(): void
    {
        // A lista é contrato do corpo do /ready: nome de dependência que muda
        // muda o `checks` que o monitor externo observa.
        $this->assertSame(self::DEPENDENCIAS, array_keys(sondasDeDependencia()));
    }

    public function testPostgresRespondeQuandoOBancoExisteEEhLegivel(): void
    {
        putenv('TEST_DATABASE_DSN=' . $this->bancoDescartavel(true));

        $this->assertTrue(sondaPostgresResponde());
    }

    public function testPostgresRespondeQuandoOCaminhoContemCaractereDeUri(): void
    {
        // O DSN de arquivo não deve interpretar o nome configurado como URI:
        // `?` e `#` são caracteres válidos em um caminho Unix.
        $caminho = sys_get_temp_dir() . '/sonda?db#' . uniqid() . '.db';
        $this->descartaveis[] = $caminho;
        $pdo = \BancoTeste::conectar($caminho);
        $pdo->exec('CREATE TABLE usuarios (id INTEGER PRIMARY KEY)');
        unset($pdo);
        putenv('TEST_DATABASE_DSN=' . \BancoTeste::dsn($caminho));

        $this->assertTrue(sondaPostgresResponde());
    }

    public function testPostgresReprovaQuandoTabelaCentralNaoExiste(): void
    {
        putenv('TEST_DATABASE_DSN=' . \BancoTeste::dsn());
        $this->expectException(\PDOException::class);
        sondaPostgresResponde();
    }

    public function testSondaNaoCriaTabelaAusente(): void
    {
        $dsn = \BancoTeste::dsn();
        putenv('TEST_DATABASE_DSN=' . $dsn);
        self::assertFalse(sondasDeDependencia()['postgresql']());
        $pdo = \BancoTeste::conectar($dsn);
        self::assertNull(\ConsultaBanco::coluna($pdo, "SELECT to_regclass(current_schema() || '.usuarios')"));
    }

    public function testRedisReprovaSemDerrubarOProcessoQuandoNaoHaServidor(): void
    {
        // A suíte aponta REDIS_PORT para 1 (phpunit.xml), onde nada escuta. O
        // que importa aqui é o que NÃO acontece: `conectarRedis()` encerraria o
        // processo, e numa sonda a falha é a RESPOSTA.
        // O que o /ready enxerga é sempre `erro`: ou a função devolve false, ou
        // ela lança (phpredis 6 lança no connect) e Sonda::checar() traduz.
        $resposta = \App\Support\Sonda::pronto([
            'redis' => static fn (): bool => sondaRedisResponde(),
        ]);

        $this->assertSame(503, $resposta['status']);
        $this->assertSame(['redis' => 'erro'], $resposta['corpo']['checks'] ?? null);
    }

    /**
     * @return array<string, array{bool, string|null, mixed, bool}>
     */
    public static function vereditoDoRedisProvider(): array
    {
        return [
            'conexão recusada' => [false, null, false, false],
            'sem senha e pong booleano' => [true, null, true, true],
            'sem senha e PONG textual' => [true, null, 'PONG', true],
            'sem senha e +PONG do modo cru' => [true, null, '+PONG', true],
            'sem senha e ping mudo' => [true, null, false, false],
            'senha aceita' => [true, 'segredo', true, true],
            'senha vazia é ignorada' => [true, '', true, true],
        ];
    }

    #[DataProvider('vereditoDoRedisProvider')]
    public function testVereditoDoRedisCobreOsDesfechosQueOServidorTeriaDado(
        bool $conectado,
        ?string $senha,
        mixed $pong,
        bool $esperado
    ): void {
        // A suíte aponta REDIS_PORT para 1, então o `connect()` real nunca
        // passa e estes três desfechos jamais rodariam contra servidor. É por
        // isso que o veredito mora numa função própria.
        $cliente = $this->createStub(\Redis::class);
        $cliente->method('setOption')->willReturn(true);
        $cliente->method('auth')->willReturn(true);
        $cliente->method('ping')->willReturn($pong);

        $this->assertSame($esperado, sondaRedisConfirmada($cliente, $conectado, $senha));
    }

    public function testSenhaRecusadaReprovaAntesDoPing(): void
    {
        // NOAUTH é queda de credencial, não de servidor — e reprovar aqui é o
        // que impede a sonda de dizer `ok` sobre um Redis que não atende.
        $cliente = $this->createStub(\Redis::class);
        $cliente->method('setOption')->willReturn(true);
        $cliente->method('auth')->willReturn(false);
        $cliente->method('ping')->willReturn(true);

        $this->assertFalse(sondaRedisConfirmada($cliente, true, 'senha-errada'));
    }

    public function testClienteQueRecusaSerializadorCruOuRetentativaZeroReprova(): void
    {
        // A sonda mede o Redis de agora, sem serializador e sem retentativa;
        // um cliente que não aceita esse estado não pode dizer `ok`.
        $cliente = $this->createStub(\Redis::class);
        $cliente->method('setOption')->willReturn(false);
        $cliente->method('auth')->willReturn(true);
        $cliente->method('ping')->willReturn(true);

        $this->assertFalse(sondaRedisConfirmada($cliente, true, null));
    }

    public function testEnderecoDoRedisUsaOMesmoTrioDeVariaveisDoRestoDoBot(): void
    {
        // A sonda lê REDIS_HOST/REDIS_PORT/REDIS_PASSWORD direto do ambiente
        // para não arrastar `inclusos/funcoes.php` (e as credenciais que ele
        // carrega) para dentro de uma rota sem autenticação. O trio é o mesmo
        // que `conectarRedis()` usa, e é o `.env.example` que guarda o contrato.
        // A senha entra na lista de propósito: sem ela o `ping()` responderia
        // NOAUTH e a sonda acusaria queda de um Redis perfeitamente vivo.
        $este = file_get_contents(dirname(__DIR__, 2) . '/inclusos/funcoes_sondas.php');
        $this->assertIsString($este);
        $this->assertStringContainsString("getenv('REDIS_HOST')", $este);
        $this->assertStringContainsString("getenv('REDIS_PORT')", $este);
        $this->assertStringContainsString("getenv('REDIS_PASSWORD')", $este);
    }

    public function testSondaEClientePrincipalUsamOMesmoEndpointSemCorrigirConfiguracao(): void
    {
        if (!function_exists('enderecoDoRedis')) {
            require_once dirname(__DIR__, 2) . '/' . self::ARQUIVO_DO_ENDERECO_DO_REDIS;
        }
        $hostAnterior = getenv('REDIS_HOST');
        $portaAnterior = getenv('REDIS_PORT');

        try {
            foreach ([
                ['redis.interno', '6380'],
                [' redis.interno ', ' 6380'],
                ['', '0'],
            ] as [$host, $porta]) {
                putenv("REDIS_HOST={$host}");
                putenv("REDIS_PORT={$porta}");

                $this->assertSame(
                    enderecoDoRedis(),
                    enderecoDoRedisDaSonda(),
                    'A sonda não pode verificar um endpoint diferente do cliente principal.'
                );
            }
        } finally {
            $hostAnterior === false ? putenv('REDIS_HOST') : putenv("REDIS_HOST={$hostAnterior}");
            $portaAnterior === false ? putenv('REDIS_PORT') : putenv("REDIS_PORT={$portaAnterior}");
        }
    }

    public function testSondaDefineTimeoutoDeConexaoELeituraDoRedis(): void
    {
        $fonte = file_get_contents(dirname(__DIR__, 2) . '/inclusos/funcoes_sondas.php');
        $this->assertIsString($fonte);
        $this->assertStringContainsString(
            "\\App\\Support\\Sonda::TIMEOUT_SEGUNDOS,\n            '',\n            100,\n            \\App\\Support\\Sonda::TIMEOUT_SEGUNDOS",
            $fonte,
            'A sonda precisa limitar conexão e leitura para respeitar o teto de prontidão.'
        );
    }

    public function testReadyNaoCarregaCredencialParaResponderUmaRotaPublica(): void
    {
        // `/ready` não tem autenticação. Não há motivo para o token do bot e as
        // credenciais dos provedores entrarem na memória de um processo que só
        // precisa responder `ok`/`erro` — e `inclusos/dados_bot.php` entra de
        // carona em quem carrega `inclusos/funcoes.php`.
        $ready = file_get_contents(dirname(__DIR__, 2) . '/ready.php');
        $this->assertIsString($ready);

        $carregados = [];
        preg_match_all('/^require(?:_once)?\s[^;]+;/m', $ready, $carregados);
        $this->assertNotEmpty($carregados[0], 'ready.php deixou de carregar as sondas');

        foreach ($carregados[0] as $linha) {
            $this->assertStringNotContainsString('funcoes.php', $linha);
            $this->assertStringNotContainsString('dados_bot.php', $linha);
            $this->assertStringNotContainsString('config_loader', $linha);
        }
    }

    public function testReprovacaoDeDependenciaViraLinhaNoCanalProntidao(): void
    {
        // É o primeiro sinal automático deste bot, e o runbook de
        // docs/OPERACAO.md filtra por `channel=="prontidao"`. Só a REPROVAÇÃO
        // vira linha: probe que passa é ruído, e uma por minuto enterraria o
        // arquivo.
        LoggerFactory::reset();
        putenv('TEST_DATABASE_DSN=' . sys_get_temp_dir() . '/nao-existe-' . uniqid() . '.db');

        $antes = AppLogReader::tamanhoAtual();
        $sondas = sondasDeDependencia();
        $this->assertFalse($sondas['postgresql']());

        $linhas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($antes));
        $this->assertNotSame([], $linhas);
        $ultima = $linhas[count($linhas) - 1];
        $this->assertSame('prontidao', $ultima['channel'] ?? null);
        $this->assertSame('error', $ultima['level'] ?? null);
        // A linha diz QUAL dependência caiu — é o que o corpo público não pode
        // dizer, e é o que quem está de plantão precisa saber.
        $contexto = $ultima['context'] ?? null;
        $this->assertIsArray($contexto);
        $this->assertSame('postgresql', $contexto['dependencia'] ?? null);
    }

    public function testSondaAprovadaNaoEscreveLinhaDeProntidao(): void
    {
        LoggerFactory::reset();
        putenv('TEST_DATABASE_DSN=' . $this->bancoDescartavel(true));

        $antes = AppLogReader::tamanhoAtual();
        $sondas = sondasDeDependencia();
        $this->assertTrue($sondas['postgresql']());

        $prontidao = array_filter(
            AppLogReader::linhasJson(AppLogReader::conteudoDesde($antes)),
            static fn (array $linha): bool => ($linha['channel'] ?? null) === 'prontidao'
        );
        $this->assertSame([], $prontidao);
    }

    private function bancoDescartavel(bool $comTabela): string
    {
        $caminho = sys_get_temp_dir() . '/sonda-' . uniqid() . '.db';
        $this->descartaveis[] = $caminho;

        $pdo = \BancoTeste::conectar($caminho);
        if ($comTabela) {
            $pdo->exec('CREATE TABLE usuarios (id_telegram TEXT PRIMARY KEY)');
        }
        unset($pdo);

        return \BancoTeste::dsn($caminho);
    }
}
