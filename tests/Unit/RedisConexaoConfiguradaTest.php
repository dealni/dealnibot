<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/redis_conexao.php';

/**
 * Dublê mínimo para provar os argumentos da conexão sem abrir um socket.
 */
final class RedisConfiguracaoConexaoFake
{
    /** @var array<int, array{metodo: string, host: string, port: int, timeout: float, persistent_id: ?string, retry_interval: int, read_timeout: float}> */
    public array $conexoes = [];

    public ?string $senha = null;

    public function connect(
        string $host,
        int $port,
        float $timeout = 0,
        ?string $persistentId = null,
        int $retryInterval = 0,
        float $readTimeout = 0
    ): bool {
        $this->conexoes[] = [
            'metodo' => 'connect',
            'host' => $host,
            'port' => $port,
            'timeout' => $timeout,
            'persistent_id' => $persistentId,
            'retry_interval' => $retryInterval,
            'read_timeout' => $readTimeout,
        ];

        return true;
    }

    public function pconnect(
        string $host,
        int $port,
        float $timeout = 0,
        ?string $persistentId = null,
        int $retryInterval = 0,
        float $readTimeout = 0
    ): bool {
        $this->conexoes[] = [
            'metodo' => 'pconnect',
            'host' => $host,
            'port' => $port,
            'timeout' => $timeout,
            'persistent_id' => $persistentId,
            'retry_interval' => $retryInterval,
            'read_timeout' => $readTimeout,
        ];

        return true;
    }

    public function auth(string $senha): bool
    {
        $this->senha = $senha;

        return true;
    }
}

/**
 * Dublê cujo connect() é recusado: auth() nunca pode ser chamado depois.
 */
final class RedisConexaoRecusadaFake
{
    public function connect(string $host, int $port): bool
    {
        return false;
    }

    public function auth(string $senha): bool
    {
        throw new \LogicException('auth() não pode ser chamado depois de um connect() recusado.');
    }
}

/**
 * Dublê que conecta mas não expõe auth(): um cliente sem suporte a senha.
 */
final class RedisSemAutenticacaoFake
{
    public function connect(string $host, int $port): bool
    {
        return true;
    }
}

/**
 * Dublê cujo auth() devolve o que o teste mandar: false, ou a própria
 * instância (modo encadeável do phpredis).
 */
final class RedisAutenticacaoConfiguravelFake
{
    public function __construct(private bool $aceitaSenha, private bool $devolveInstancia = false)
    {
    }

    public function connect(string $host, int $port): bool
    {
        return true;
    }

    public function auth(string $senha): bool|self
    {
        if ($this->devolveInstancia) {
            return $this;
        }

        return $this->aceitaSenha;
    }
}

/**
 * Testes de enderecoDaConexaoRedis(), senhaDaConexaoRedis() e
 * conectarRedisConfigurado() (inclusos/redis_conexao.php).
 *
 * O helper de conexão usa o mesmo endereço para os webhooks, o worker, o smoke
 * e os scripts auxiliares. Um host ou porta lidos errado não derrubam nada de
 * forma silenciosa: cada chamador aplica a própria política de falha. Os
 * testes de conexão usam um dublê sem socket.
 */
final class RedisConexaoConfiguradaTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    private const PORTA_PADRAO = 6380;
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    /** @var array<string, string|false> Valores originais, restaurados no fim. */
    private array $ambienteOriginal = [];

    protected function setUp(): void
    {
        foreach (['REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD'] as $variavel) {
            $this->ambienteOriginal[$variavel] = getenv($variavel);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->ambienteOriginal as $variavel => $valor) {
            if (is_string($valor)) {
                putenv("{$variavel}={$valor}");
            } else {
                putenv($variavel);
            }
        }
    }

    private function definirAmbiente(?string $host, ?string $porta): void
    {
        putenv($host === null ? 'REDIS_HOST' : "REDIS_HOST={$host}");
        putenv($porta === null ? 'REDIS_PORT' : "REDIS_PORT={$porta}");
    }

    private function definirSenha(?string $senha): void
    {
        putenv($senha === null ? 'REDIS_PASSWORD' : "REDIS_PASSWORD={$senha}");
    }

    public function testUsaOHostEAPortaDoAmbienteQuandoEstaoPreenchidos(): void
    {
        $this->definirAmbiente('redis.interno', '6380');

        $this->assertSame(['host' => 'redis.interno', 'port' => 6380], enderecoDaConexaoRedis());
    }

    public function testPortaEhDevolvidaComoInteiroEnaoComoTexto(): void
    {
        // Redis::connect() recebe a porta como int; devolver "6380" aqui faria
        // o bot depender de coerção implícita. O zero à esquerda entra no teste
        // porque ctype_digit o aceita e o cast precisa produzir 6380, não 06380.
        $this->definirAmbiente('127.0.0.1', '06380');

        $configuracao = enderecoDaConexaoRedis();

        $this->assertSame(6380, $configuracao['port']);
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function ambientesSemConfiguracaoProvider(): array
    {
        return [
            'variáveis ausentes' => [null, null],
            'variáveis vazias' => ['', ''],
        ];
    }

    #[DataProvider('ambientesSemConfiguracaoProvider')]
    public function testSemConfiguracaoCaiNoRedisLocalPadrao(?string $host, ?string $porta): void
    {
        $this->definirAmbiente($host, $porta);

        $this->assertSame(['host' => '127.0.0.1', 'port' => self::PORTA_PADRAO], enderecoDaConexaoRedis());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function portasForaDoFormatoProvider(): array
    {
        return [
            'texto' => ['porta'],
            'com espaço' => [' 6379'],
            'negativa' => ['-1'],
            'decimal' => ['6379.0'],
            'sufixo' => ['6379a'],
            'zero' => ['0'],
            'acima do limite TCP' => ['65536'],
        ];
    }

    /**
     * Porta fora do formato cai na padrão em vez de virar 0 pelo cast: uma
     * porta 0 faria o connect() apontar para lugar nenhum sem erro de
     * configuração aparente.
     */
    #[DataProvider('portasForaDoFormatoProvider')]
    public function testPortaForaDoFormatoUsaAPadraoEmVezDeZero(string $porta): void
    {
        $this->definirAmbiente('127.0.0.1', $porta);

        $configuracao = enderecoDaConexaoRedis();

        $this->assertSame(self::PORTA_PADRAO, $configuracao['port']);
    }

    public function testHostPreservaEspacosEDominiosCompletos(): void
    {
        // Nenhum trim é aplicado: o valor vai para o connect() como está, e o
        // teste registra isso para que uma mudança futura seja deliberada.
        $this->definirAmbiente('redis-prod.exemplo.test', '6379');
        $this->assertSame('redis-prod.exemplo.test', enderecoDaConexaoRedis()['host']);

        // Espaço acidental no .env NÃO é corrigido aqui — o connect() é que
        // falha. Diferente da porta, que cai na padrão, o host errado precisa
        // aparecer como está para o erro ser diagnosticável.
        $this->definirAmbiente(' redis.interno ', '6379');
        $this->assertSame(' redis.interno ', enderecoDaConexaoRedis()['host']);
    }

    public function testConexaoUsaTimeoutsEAutenticaQuandoSenhaFoiConfigurada(): void
    {
        $this->definirAmbiente('redis.interno', '6380');
        $this->definirSenha('senha com espaço');
        $redis = new RedisConfiguracaoConexaoFake();

        $this->assertTrue(conectarRedisConfigurado($redis));
        $this->assertSame([
            [
                'metodo' => 'connect',
                'host' => 'redis.interno',
                'port' => 6380,
                'timeout' => 2.0,
                'persistent_id' => null,
                'retry_interval' => 0,
                'read_timeout' => 2.0,
            ],
        ], $redis->conexoes);
        $this->assertSame('senha com espaço', $redis->senha);
    }

    public function testSenhaDaConexaoRedisPreservaEspacosETrataVaziaComoAusente(): void
    {
        $this->definirSenha('senha com espaço ');
        $this->assertSame('senha com espaço ', senhaDaConexaoRedis());

        $this->definirSenha('');
        $this->assertNull(senhaDaConexaoRedis());

        $this->definirSenha(null);
        $this->assertNull(senhaDaConexaoRedis());
    }

    public function testConexaoPersistenteNaoAutenticaComSenhaVazia(): void
    {
        $this->definirAmbiente('redis.interno', '6380');
        $this->definirSenha('');
        $redis = new RedisConfiguracaoConexaoFake();

        $this->assertTrue(conectarRedisConfigurado($redis, true));
        $this->assertSame('pconnect', $redis->conexoes[0]['metodo']);
        $this->assertNull($redis->senha);
        $this->assertSame(2.0, $redis->conexoes[0]['timeout']);
        $this->assertSame(2.0, $redis->conexoes[0]['read_timeout']);
    }

    /**
     * O parâmetro é `mixed` de propósito (ver o docblock do helper): o que não
     * é um cliente Redis precisa ser recusado como "não conectado", nunca
     * virar TypeError num webhook.
     */
    public function testValorQueNaoEClienteRedisNaoConecta(): void
    {
        $this->definirAmbiente('redis.interno', '6380');

        $this->assertFalse(conectarRedisConfigurado(null));
        $this->assertFalse(conectarRedisConfigurado('redis://redis.interno:6380'));
        // Objeto sem connect()/pconnect(): o helper não chama método inexistente.
        $this->assertFalse(conectarRedisConfigurado(new \stdClass()));
        $this->assertFalse(conectarRedisConfigurado(new \stdClass(), true));
    }

    public function testConexaoRecusadaNaoTentaAutenticar(): void
    {
        $this->definirAmbiente('redis.interno', '6380');
        $this->definirSenha('segredo');

        // O dublê lança se auth() for chamado: senha nunca vai para um socket
        // que não abriu.
        $this->assertFalse(conectarRedisConfigurado(new RedisConexaoRecusadaFake()));
    }

    public function testSenhaConfiguradaExigeClienteComAuth(): void
    {
        $this->definirAmbiente('redis.interno', '6380');
        $this->definirSenha('segredo');

        // Conectar sem autenticar num Redis que exige senha deixaria todo
        // comando seguinte falhar com NOAUTH; melhor recusar a conexão aqui.
        $this->assertFalse(conectarRedisConfigurado(new RedisSemAutenticacaoFake()));
    }

    public function testSenhaRecusadaPeloServidorDevolveFalse(): void
    {
        $this->definirAmbiente('redis.interno', '6380');
        $this->definirSenha('segredo-errado');

        $this->assertFalse(conectarRedisConfigurado(new RedisAutenticacaoConfiguravelFake(false)));
    }

    public function testAuthEmModoEncadeavelEAceitoComoSucesso(): void
    {
        // Com Redis::OPT_... em modo encadeável, auth() devolve a própria
        // instância em vez de true; tratar isso como recusa derrubaria uma
        // conexão válida.
        $this->definirAmbiente('redis.interno', '6380');
        $this->definirSenha('segredo');

        $this->assertTrue(conectarRedisConfigurado(new RedisAutenticacaoConfiguravelFake(true, true)));
        $this->assertTrue(conectarRedisConfigurado(new RedisAutenticacaoConfiguravelFake(true)));
    }
}
