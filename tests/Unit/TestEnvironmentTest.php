<?php

declare(strict_types=1);

namespace Tests;

use App\Support\RuntimeEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class TestEnvironmentTest extends TestCase
{
    public function testAmbienteDeTesteEhDeclaradoPeloPhpunit(): void
    {
        self::assertSame('test', getenv('APP_ENV'));
        self::assertSame('1', getenv('CHATBOTS_TESTING'));
        self::assertTrue(RuntimeEnvironment::isTesting());
    }

    public function testBancoPadraoEhPostgresIsolado(): void
    {
        $dsn = getenv('TEST_DATABASE_DSN');
        self::assertIsString($dsn);
        self::assertStringStartsWith('pgsql:', $dsn);
        $usuario = getenv('DEALNI_DB_USER');
        $senha = getenv('DEALNI_DB_PASSWORD');
        $pdo = new PDO($dsn, is_string($usuario) ? $usuario : null, is_string($senha) ? $senha : null);
        self::assertSame('pgsql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertStringStartsWith('teste_', (string) \ConsultaBanco::coluna($pdo, 'SELECT current_schema()'));
    }

    public function testConstrutorDoBancoUsaODsnTemporarioNosTestes(): void
    {
        require_once __DIR__ . '/../../inclusos/bdTelegram.php';

        $database = new \bdTelegram();
        $connectionProperty = new \ReflectionProperty(\bdTelegram::class, 'con');
        $connection = $connectionProperty->getValue($database);
        self::assertInstanceOf(PDO::class, $connection);

        $statement = $connection->query('SELECT current_schema() AS schema');
        self::assertNotFalse($statement);
        $databaseList = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($databaseList);
        $schema = $databaseList['schema'] ?? null;
        self::assertIsString($schema);
        self::assertStringStartsWith('teste_', $schema);
    }

    public function testCredenciaisDoAmbienteSaoFalsas(): void
    {
        self::assertSame('000000:TEST_TOKEN', getenv('TELEGRAM_BOT_TOKEN'));
        self::assertSame('test-only', getenv('DB_PASSWORD'));
        self::assertSame('test-only', getenv('REDIS_PASSWORD'));
        self::assertSame('test-only', getenv('OPENROUTER_API_KEY'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCarregadorDeConfiguracaoUsaCredenciaisFalsas(): void
    {
        require_once __DIR__ . '/../../inclusos/config_loader.php';
        carregarConfiguracaoSensivel();

        self::assertSame('000000:TEST_TOKEN', constant('TOKEN_BOT'));
        self::assertSame('test-only', constant('OPENROUTER_API_KEY'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCarregadorRejeitaConstanteObrigatoriaAusente(): void
    {
        require_once __DIR__ . '/../../inclusos/config_loader.php';
        $this->expectException(\RuntimeException::class);

        validarConstantesDeConfiguracao(['CONSTANTE_INEXISTENTE_DO_TESTE']);
    }

    /**
     * Nenhum arquivo de tests/ pode incluir script que executa efeito real já
     * no include.
     *
     * Os scripts de `config/` podem registrar ou remover webhooks do Telegram
     * ao serem incluídos.
     *
     * ÚNICA exceção: os `funcoes_*.php` desses diretórios, que por convenção
     * (docs/CONVENCOES.md) só declaram função e não têm topo nenhum. Incluí-los de
     * verdade é o certo — é o que faz a cobertura ser atribuída ao arquivo de
     * produção, em vez de se perder no `eval()` do tokenizer. A exceção não é
     * na confiança: FuncoesDeComandoSaoInertesTest confere, por tokens e um
     * caso por arquivo, que eles realmente não têm código de topo.
     *
     * Para o resto, a forma correta é Tests\Support\ExtratorDeFuncoesDeScript,
     * que tokeniza o arquivo e define apenas as declarações `function`, sem
     * rodar nenhuma linha de topo.
     *
     * A varredura é por TOKENS: `require`/`include` mencionados em comentário
     * ou dentro de string (como neste bloco) não contam.
     */
    public function testNenhumTesteIncluiScriptQueExecutaEfeitoRealNoInclude(): void
    {
        $fragmentosProibidos = ['/config/'];
        $arquivos = self::arquivosPhpDeTestes();
        self::assertNotSame([], $arquivos, 'Nenhum .php encontrado em tests/ para varrer.');

        $ocorrencias = [];
        foreach ($arquivos as $arquivo) {
            $codigo = file_get_contents($arquivo);
            if ($codigo === false) {
                $ocorrencias[] = "{$arquivo} → não foi possível ler";
                continue;
            }

            // Tokenizar todo arquivo de tests/ esgotava o memory_limit na
            // execução com cobertura. Quem não menciona nenhum dos diretórios
            // proibidos não tem como incluir um deles: pular a tokenização
            // desses arquivos não afrouxa nada, só evita trabalho inútil.
            if (!self::mencionaFragmento($codigo, $fragmentosProibidos)) {
                continue;
            }

            foreach (self::inclusoesDeCaminho($codigo, $fragmentosProibidos) as [$linha, $caminho]) {
                if (str_starts_with(basename($caminho), 'funcoes_')) {
                    continue;
                }

                $ocorrencias[] = "{$arquivo}:{$linha} → {$caminho}";
            }
        }

        self::assertSame(
            [],
            $ocorrencias,
            "Teste incluindo script de efeito real. Use Tests\\Support\\ExtratorDeFuncoesDeScript.\n"
                . implode("\n", $ocorrencias)
        );
    }

    /**
     * O código-fonte menciona algum dos fragmentos proibidos, em qualquer
     * posição? Filtro textual barato que antecede a varredura por tokens.
     *
     * @param array<int, string> $fragmentos
     */
    private static function mencionaFragmento(string $codigo, array $fragmentos): bool
    {
        foreach ($fragmentos as $fragmento) {
            if (strpos($codigo, $fragmento) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Arquivos .php sob tests/ (inclusive bootstrap e Support).
     *
     * @return array<int, string>
     */
    private static function arquivosPhpDeTestes(): array
    {
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__), \FilesystemIterator::SKIP_DOTS)
        );

        $arquivos = [];
        foreach ($iterador as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile() && $item->getExtension() === 'php') {
                $arquivos[] = $item->getPathname();
            }
        }
        sort($arquivos);

        return $arquivos;
    }

    /**
     * Literais de caminho usados em require/include que contenham um dos
     * fragmentos proibidos.
     *
     * @param array<int, string> $fragmentos
     * @return array<int, array{0: int, 1: string}> pares (linha, caminho)
     */
    private static function inclusoesDeCaminho(string $codigo, array $fragmentos): array
    {
        $tokens = token_get_all($codigo);
        $total = count($tokens);
        $achados = [];
        $inclusoes = [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE];

        for ($indice = 0; $indice < $total; $indice++) {
            $token = $tokens[$indice];
            if (!is_array($token) || !in_array($token[0], $inclusoes, true)) {
                continue;
            }

            // Consome a expressão do include até o ponto e vírgula, coletando
            // os literais de string que a compõem (o padrão do repositório é
            // __DIR__ . '/../../pasta/arquivo.php').
            for ($seguinte = $indice + 1; $seguinte < $total; $seguinte++) {
                $proximo = $tokens[$seguinte];
                if ($proximo === ';') {
                    $indice = $seguinte;
                    break;
                }
                if (!is_array($proximo) || $proximo[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $literal = trim($proximo[1], "'\"");
                foreach ($fragmentos as $fragmento) {
                    if (strpos($literal, $fragmento) !== false) {
                        $achados[] = [$proximo[2], $literal];
                        break;
                    }
                }
            }
        }

        unset($tokens);

        return $achados;
    }

    public function testRedeExternaEstaIsoladaPeloSistemaOperacional(): void
    {
        self::assertSame('1', getenv('CHATBOTS_NETWORK_DISABLED'));
        self::assertTrue(RuntimeEnvironment::hasNetworkIsolation());
        self::assertTrue(function_exists('curl_exec'));

        if (PHP_OS_FAMILY === 'Darwin') {
            self::assertSame('1', getenv('CHATBOTS_OS_NETWORK_SANDBOX'));
            self::assertSame('Darwin', PHP_OS_FAMILY);

            // A trava não é mais "qual binário roda a suíte". Era
            // PHP_SAPI === 'phpdbg', e aquilo se forjava exportando a variável
            // acima e chamando phpdbg à mão — sem sandbox algum, com a rede
            // viva. Aqui a propriedade que interessa é medida direto: o
            // sandbox-exec montado por scripts/quality/run_tests.php nega o
            // socket. 192.0.2.1 é da TEST-NET-1 (RFC 5737), reservada para
            // documentação e não roteável, então nem se a trava caísse este
            // teste alcançaria um host de verdade.
            $codigoErro = 0;
            $mensagemErro = '';
            $socket = @stream_socket_client('tcp://192.0.2.1:443', $codigoErro, $mensagemErro, 1.0);

            self::assertFalse($socket, 'O sandbox do SO precisa negar socket de saída.');
            self::assertSame(
                1,
                $codigoErro,
                "EPERM esperado (negado pelo sandbox); veio {$codigoErro}: {$mensagemErro}"
            );

            return;
        }

        self::assertSame('Linux', PHP_OS_FAMILY);
    }
}
