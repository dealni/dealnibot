<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Testes da validação de credenciais (inclusos/config_loader.php).
 *
 * O arquivo só DECLARA funções — nada roda no include —, então ele é
 * carregado diretamente e as funções são exercitadas de verdade.
 *
 * O que importa aqui: subir com credencial faltando ou em branco tem que
 * FALHAR ALTO no boot. Sem isso o bot sobe, fala com a API do Telegram com
 * token vazio e só se descobre o problema quando os usuários param de receber
 * resposta — sem erro nenhum no log.
 */
final class InclusosConfigLoaderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inclusos/config_loader.php';
    }

    public function testConstanteObrigatoriaAusenteInterrompeOBootNomeandoAConstante(): void
    {
        try {
            validarConstantesDeConfiguracao(['TOKEN_AUSENTE_A', 'TOKEN_AUSENTE_B']);
            $this->fail('Constante obrigatória ausente precisa interromper o boot.');
        } catch (RuntimeException $erro) {
            // A mensagem precisa dizer QUAIS constantes faltam: é o que o
            // operador vê no log ao subir o bot.
            $this->assertSame(
                'Configuração incompleta em inclusos/config.php. Constantes ausentes: TOKEN_AUSENTE_A, TOKEN_AUSENTE_B',
                $erro->getMessage()
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valoresEmBrancoProvider(): array
    {
        return [
            'string vazia' => [''],
            'só espaços' => ['   '],
            'só quebra de linha' => ["\n"],
        ];
    }

    /**
     * Constante DEFINIDA mas em branco é o caso perigoso: `defined()` devolve
     * true e o bot subiria com token vazio. O trim() da validação é o que
     * transforma isso em falha de boot.
     */
    #[DataProvider('valoresEmBrancoProvider')]
    public function testConstanteDefinidaEmBrancoTambemInterrompeOBoot(string $valor): void
    {
        $nome = 'DEALNIBOT_TESTE_EM_BRANCO_' . md5($valor);
        if (!defined($nome)) {
            define($nome, $valor);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Configuração vazia em inclusos/config.php: ' . $nome);

        validarConstantesDeConfiguracao([$nome], [$nome]);
    }

    public function testConstanteNaoTextualContaComoVazia(): void
    {
        // Um `define('TOKEN_BOT', 0)` num config.php mal editado passaria pelo
        // defined() e viraria "0" na URL da API; a validação exige string.
        $nome = 'DEALNIBOT_TESTE_NAO_TEXTUAL';
        if (!defined($nome)) {
            define($nome, 0);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Configuração vazia em inclusos/config.php: ' . $nome);

        validarConstantesDeConfiguracao([$nome], [$nome]);
    }

    public function testConstantesPresentesEPreenchidasPassam(): void
    {
        $nome = 'DEALNIBOT_TESTE_PREENCHIDA';
        if (!defined($nome)) {
            define($nome, '123456:TOKEN');
        }

        validarConstantesDeConfiguracao([$nome], [$nome]);

        // Sem exceção: o boot segue. (A ausência de exceção é o comportamento
        // sob teste; o assert existe para o teste não ser marcado como risky.)
        $this->assertTrue(defined($nome));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function urlBasePublicaProvider(): array
    {
        return [
            'HTTPS minúsculo com caminho' => ['https://example.com/dealnibot', true],
            'esquema HTTPS não diferencia maiúsculas' => ['HTTPS://example.com/dealnibot', true],
            'sem esquema' => ['//example.com/dealnibot', false],
            'HTTP' => ['http://example.com/dealnibot', false],
            'credenciais' => ['https://usuario:senha@example.com/dealnibot', false],
            'query' => ['https://example.com/dealnibot?destino=outro', false],
            'fragmento' => ['https://example.com/dealnibot#outro', false],
            'URL inválida' => ['https://example.com caminho', false],
            'array' => [['https://example.com/dealnibot'], false],
            'nulo' => [null, false],
        ];
    }

    #[DataProvider('urlBasePublicaProvider')]
    public function testUrlBasePublicaValidaApenasHttpsSemComponentesAmbiguos(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, urlBasePublicaValida($value));
    }

    // ---------------------------------------------------------------------
    // Precedência em modo de teste: env → padrão inofensivo
    // ---------------------------------------------------------------------

    /**
     * Variável vazia é ausência de configuração e cai no padrão inofensivo —
     * nunca num token literalmente vazio, que faria toda chamada à API do
     * Telegram voltar 404 sem nenhum erro no boot.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testVariavelVaziaCaiNoPadraoInofensivo(): void
    {
        putenv('TELEGRAM_BOT_TOKEN=');
        putenv('OPENROUTER_API_KEY=');

        require_once __DIR__ . '/../../inclusos/config_loader.php';
        carregarConfiguracaoSensivel();

        $this->assertSame('000000:TEST_TOKEN', constant('TOKEN_BOT'));
        $this->assertSame('test-only', constant('OPENROUTER_API_KEY'));
    }

    /**
     * Variável AUSENTE segue o mesmo caminho da vazia.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testVariavelAusenteCaiNoPadraoInofensivo(): void
    {
        putenv('TELEGRAM_BOT_TOKEN');
        putenv('OPENROUTER_API_KEY');

        require_once __DIR__ . '/../../inclusos/config_loader.php';
        carregarConfiguracaoSensivel();

        $this->assertSame('000000:TEST_TOKEN', constant('TOKEN_BOT'));
        $this->assertSame('test-only', constant('OPENROUTER_API_KEY'));
    }

    /**
     * `"0"` é valor configurado, não ausência: a checagem é `!== ''`, e um
     * `empty()` no lugar dela silenciosamente trocaria o valor do operador pelo
     * padrão. Borda clássica e a única diferença observável entre as duas
     * implementações.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testValorZeroTextualEhRespeitadoEmVezDeCairNoPadrao(): void
    {
        putenv('TELEGRAM_BOT_TOKEN=0');
        putenv('OPENROUTER_API_KEY=0');

        require_once __DIR__ . '/../../inclusos/config_loader.php';
        carregarConfiguracaoSensivel();

        $this->assertSame('0', constant('TOKEN_BOT'));
        $this->assertSame('0', constant('OPENROUTER_API_KEY'));
    }

    /**
     * O ambiente tem precedência sobre o padrão — é assim que o phpunit.xml
     * injeta credenciais de teste distintas por bot.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAmbientePreenchidoTemPrecedenciaSobreOPadrao(): void
    {
        putenv('TELEGRAM_BOT_TOKEN=999999:OUTRO_TOKEN');
        putenv('OPENROUTER_API_KEY=sk-de-teste');

        require_once __DIR__ . '/../../inclusos/config_loader.php';
        carregarConfiguracaoSensivel();

        $this->assertSame('999999:OUTRO_TOKEN', constant('TOKEN_BOT'));
        $this->assertSame('sk-de-teste', constant('OPENROUTER_API_KEY'));
    }

    /**
     * Constante já definida vence tudo (o `if (!defined(...))`): o carregador é
     * chamado por dados_bot.php, que é incluído por vários scripts na mesma
     * requisição, e uma redefinição emitiria warning e derrubaria o webhook.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstanteJaDefinidaNaoEhRedefinidaNemGeraAviso(): void
    {
        define('TOKEN_BOT', '111111:DEFINIDA_ANTES');
        putenv('TELEGRAM_BOT_TOKEN=222222:DO_AMBIENTE');

        require_once __DIR__ . '/../../inclusos/config_loader.php';
        carregarConfiguracaoSensivel();

        $this->assertSame('111111:DEFINIDA_ANTES', constant('TOKEN_BOT'));
    }

    /**
     * Chamar duas vezes na mesma requisição (dois `require` de dados_bot.php)
     * precisa ser inofensivo.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testChamadaRepetidaEhIdempotente(): void
    {
        require_once __DIR__ . '/../../inclusos/config_loader.php';
        carregarConfiguracaoSensivel();
        $primeiro = constant('TOKEN_BOT');
        carregarConfiguracaoSensivel();

        $this->assertSame($primeiro, constant('TOKEN_BOT'));
    }

    /**
     * O ramo de produção (que faz `require inclusos/config.php`, arquivo fora
     * do git com as credenciais reais) não pode ser executado por uma suíte.
     * O que dá para travar sem executá-lo é a CONDIÇÃO: ela precisa continuar
     * exigindo as duas variáveis, exatamente como
     * App\Support\RuntimeEnvironment::isTesting() — uma sozinha vazando no
     * servidor faria o bot de produção subir com token '000000:TEST_TOKEN' e
     * parar de responder sem nenhum erro no log.
     */
    public function testCondicaoDeModoDeTesteContinuaEspelhandoORuntimeEnvironment(): void
    {
        $fonte = file_get_contents(__DIR__ . '/../../inclusos/config_loader.php');
        $this->assertIsString($fonte, 'inclusos/config_loader.php precisa ser legível.');

        $this->assertStringContainsString(
            "if (getenv('CHATBOTS_TESTING') !== '1' || getenv('APP_ENV') !== 'test') {",
            $fonte
        );
        $this->assertStringContainsString("require_once __DIR__ . '/config.php';", $fonte);

        $runtime = file_get_contents(__DIR__ . '/../../src/Support/RuntimeEnvironment.php');
        $this->assertIsString($runtime);
        $this->assertStringContainsString(
            "return self::name() === 'test' && getenv('CHATBOTS_TESTING') === '1';",
            $runtime
        );
    }

    public function testConstanteObrigatoriaNaoPrecisaSerNaoVaziaQuandoNaoEhExigido(): void
    {
        // A segunda lista é opcional: em modo de teste o carregador valida
        // apenas a PRESENÇA das constantes, porque os valores vêm do
        // phpunit.xml. Passar só a primeira lista não pode disparar a
        // verificação de conteúdo.
        $nome = 'DEALNIBOT_TESTE_PRESENTE_MAS_VAZIA';
        if (!defined($nome)) {
            define($nome, '');
        }

        validarConstantesDeConfiguracao([$nome]);

        $this->assertSame('', constant($nome));
    }

    // ---------------------------------------------------------------------
    // Formato do segredo do webhook
    // ---------------------------------------------------------------------

    /**
     * A validação de formato só roda quando a constante está na LISTA de
     * obrigatórias.
     *
     * É o que permite ao ramo de teste do carregador validar presença sem
     * exigir formato, e o que impediria a checagem de disparar num bot da
     * família que não use webhook.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSegredoForaDaListaDeObrigatoriasNaoEValidado(): void
    {
        // Segredo com ESPAÇO: formato recusado pelo Telegram e pela validação.
        // Ainda assim nada é lançado, porque a constante não está na lista de
        // obrigatórias — é a lista, e não a presença da constante, que decide.
        define('TELEGRAM_WEBHOOK_SECRET', 'valor invalido com espaco');
        require_once __DIR__ . '/../../inclusos/config_loader.php';

        validarFormatoDoSegredoWebhookTelegram(['TOKEN_BOT', 'OPENROUTER_API_KEY']);

        // Contraprova no MESMO processo: com a constante na lista, o mesmo
        // valor derruba o boot.
        $lancou = false;
        try {
            validarFormatoDoSegredoWebhookTelegram(['TELEGRAM_WEBHOOK_SECRET']);
        } catch (RuntimeException) {
            $lancou = true;
        }

        $this->assertTrue($lancou, 'O segredo fora do formato precisa derrubar o boot quando é exigido.');
    }

    /**
     * Copiar `inclusos/config.example.php` para `config.php` tem de produzir um
     * arquivo que satisfaz o contrato de produção — menos os valores, que quem
     * instala preenche.
     *
     * A `TELEGRAM_WEBHOOK_SECRET` ficava COMENTADA no exemplo, e o efeito era
     * concreto: quem seguia o README subia um bot em que
     * `carregarConfiguracaoSensivel()` lançava
     * "Constantes ausentes: TELEGRAM_WEBHOOK_SECRET" e todo request virava 500.
     * Com a linha descomentada, a única coisa que ainda barra o boot é o
     * segredo EM BRANCO — que é o passo do operador, e diz isso com todas as
     * letras.
     *
     * A diferença entre as duas mensagens é o que este teste mede: ele reprova
     * tanto se a constante voltar a ser comentada quanto se o portão de formato
     * do segredo desaparecer.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExemploCopiadoDefineAsTresConstantesEParaSoNoSegredoEmBranco(): void
    {
        // O exemplo só declara define(): nenhum efeito no include.
        (static function (): mixed {
            return require __DIR__ . '/../../inclusos/config.example.php';
        })();
        require_once __DIR__ . '/../../inclusos/config_loader.php';

        $this->assertTrue(defined('TOKEN_BOT'));
        $this->assertTrue(defined('OPENROUTER_API_KEY'));
        $this->assertTrue(
            defined('TELEGRAM_WEBHOOK_SECRET'),
            'A linha do segredo do webhook voltou a ficar comentada no exemplo.'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'TELEGRAM_WEBHOOK_SECRET deve ter 32 a 256 caracteres: A-Z, a-z, 0-9, _ ou -.'
        );

        validarConstantesDeConfiguracao(
            ['TOKEN_BOT', 'OPENROUTER_API_KEY', 'TELEGRAM_WEBHOOK_SECRET']
        );
    }

    /**
     * O exemplo versionado precisa DEFINIR todas as constantes que
     * config_loader.php exige em produção — de verdade, não em comentário.
     *
     * A `TELEGRAM_WEBHOOK_SECRET` ficou COMENTADA no exemplo, e quem copiava o
     * arquivo seguindo o README subia um bot que respondia 500 em todo request:
     * `carregarConfiguracaoSensivel()` chama
     * `validarConstantesDeConfiguracao()` com a constante na lista, e ela lança
     * `RuntimeException` quando a constante não existe.
     *
     * A leitura é pelo TOKEN STREAM, não por regex sobre o texto: o
     * `token_get_all()` entrega a linha comentada como um único T_COMMENT, e
     * uma chamada dentro de comentário deixa de existir. Uma verificação por
     * regex sobre o arquivo cru casa igual nos dois casos — foi assim que o
     * exemplo quebrado passou verde por um portão que dizia validá-lo.
     */
    public function testExemploDeConfigDefineTodasAsConstantesExigidasEmProducao(): void
    {
        $definidas = self::constantesDefinidasNoExemplo();

        foreach (self::constantesExigidasEmProducao() as $constante) {
            $this->assertContains(
                $constante,
                $definidas,
                "inclusos/config.example.php não define {$constante}: quem copiar o exemplo "
                . 'sobe um bot que lança RuntimeException no boot.'
            );
        }
    }

    /**
     * Contraprova do teste acima: comentar a chamada tem de tirar a constante
     * da lista. Sem isto, `constantesDefinidasNoExemplo()` poderia estar lendo
     * o texto cru e ninguém notaria — que é exatamente o defeito original.
     */
    public function testDefineDentroDeComentarioNaoContaComoConstanteDefinida(): void
    {
        $comentada = "<?php\ndefine('PRESENTE', '');\n// define('COMENTADA', '');\n"
            . "/* define('EM_BLOCO', ''); */\n";

        $definidas = self::constantesDefinidasNoCodigo($comentada);

        $this->assertSame(['PRESENTE'], $definidas);
    }

    /**
     * Constantes que config_loader.php exige no ramo de produção.
     *
     * @return array<int, string>
     */
    private static function constantesExigidasEmProducao(): array
    {
        $fonte = file_get_contents(__DIR__ . '/../../inclusos/config_loader.php');
        if (!is_string($fonte) || preg_match('/\$requiredConstants\s*=\s*\[(.*?)\];/s', $fonte, $bloco) !== 1) {
            return [];
        }

        preg_match_all("/'([A-Z][A-Z0-9_]*)'/", $bloco[1], $achados);

        return array_values(array_unique($achados[1]));
    }

    /**
     * @return array<int, string>
     */
    private static function constantesDefinidasNoExemplo(): array
    {
        $fonte = file_get_contents(__DIR__ . '/../../inclusos/config.example.php');

        return is_string($fonte) ? self::constantesDefinidasNoCodigo($fonte) : [];
    }

    /**
     * Nomes passados a `define('NOME', ...)` no código, lidos pelo tokenizer.
     *
     * @return array<int, string>
     */
    private static function constantesDefinidasNoCodigo(string $codigo): array
    {
        $tokens = token_get_all($codigo);
        $total = count($tokens);
        $nomes = [];

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'define') {
                continue;
            }

            // define( 'NOME' — os dois próximos tokens significativos.
            $abertura = self::proximoSignificativo($tokens, $i + 1);
            if ($abertura === null || $tokens[$abertura] !== '(') {
                continue;
            }
            $nome = self::proximoSignificativo($tokens, $abertura + 1);
            $tokenNome = $nome === null ? null : $tokens[$nome];
            if (!is_array($tokenNome) || $tokenNome[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $nomes[] = trim($tokenNome[1], "'\"");
        }

        return array_values(array_unique($nomes));
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function proximoSignificativo(array $tokens, int $inicio): ?int
    {
        $total = count($tokens);
        for ($i = $inicio; $i < $total; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
