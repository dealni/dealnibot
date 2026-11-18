<?php

declare(strict_types=1);

namespace Tests;

use App\Support\RuntimeEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * App\Support\RuntimeEnvironment — a decisão "isto é ambiente de teste?".
 *
 * É a base de tudo: `tests/bootstrap.php` ABORTA a suíte quando
 * isTesting() ou hasNetworkIsolation() devolvem false, e
 * inclusos/config_loader.php usa a MESMA regra para escolher entre carregar
 * `inclusos/config.php` (credenciais reais) ou constantes falsas. Se isTesting()
 * ficar frouxo, uma variável vazada num servidor faz o bot subir com token
 * '000000:TEST_TOKEN' e parar de responder; se ficar rígido demais, a suíte
 * roda contra o banco e a API de produção.
 *
 * Todas as variáveis alteradas são restauradas no tearDown: a suíte inteira
 * roda no mesmo processo e o bootstrap já validou o ambiente.
 */
final class SuporteRuntimeEnvironmentTest extends TestCase
{
    /** @var array<string, string|false> Valor original das variáveis alteradas. */
    private array $ambienteOriginal = [];

    protected function tearDown(): void
    {
        foreach ($this->ambienteOriginal as $nome => $valor) {
            if ($valor === false) {
                putenv($nome);

                continue;
            }
            putenv("{$nome}={$valor}");
        }
        $this->ambienteOriginal = [];
    }

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

    // ---------------------------------------------------------------------
    // name(): precedência env × padrão
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function nomeDoAmbienteProvider(): array
    {
        return [
            // Variável ausente ou em branco = nada foi configurado: o padrão
            // seguro é PRODUÇÃO (nunca "test", que liberaria credencial falsa).
            'ausente cai em produção' => [null, 'production'],
            'vazia cai em produção' => ['', 'production'],
            // "0" é string não vazia: vale como ambiente chamado "0", não como
            // ausência. É o caso que um `empty()` no lugar do `!== ''` erraria.
            'zero textual é um nome de ambiente' => ['0', '0'],
            'test' => ['test', 'test'],
            'staging' => ['staging', 'staging'],
            // Sem normalização: "Test" NÃO é "test" (ver isTesting abaixo).
            'maiúscula não é normalizada' => ['Test', 'Test'],
            'com espaço não é normalizado' => [' test', ' test'],
        ];
    }

    #[DataProvider('nomeDoAmbienteProvider')]
    public function testNomeDoAmbienteSegueAVariavelComPadraoProducao(?string $valor, string $esperado): void
    {
        $this->definirAmbiente('APP_ENV', $valor);

        $this->assertSame($esperado, RuntimeEnvironment::name());
    }

    // ---------------------------------------------------------------------
    // isTesting(): as DUAS variáveis, sem atalho
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: bool}>
     */
    public static function combinacoesDeAmbienteDeTesteProvider(): array
    {
        return [
            'as duas corretas' => ['test', '1', true],

            // Uma só não basta: é exatamente o cenário de variável vazada num
            // servidor compartilhado ou herdada de um shell.
            'só APP_ENV' => ['test', null, false],
            'só CHATBOTS_TESTING' => ['production', '1', false],
            'nenhuma' => [null, null, false],

            // CHATBOTS_TESTING é comparada com === '1': nenhum outro valor
            // "verdadeiro" serve.
            'CHATBOTS_TESTING=true' => ['test', 'true', false],
            'CHATBOTS_TESTING=01' => ['test', '01', false],
            'CHATBOTS_TESTING=yes' => ['test', 'yes', false],
            'CHATBOTS_TESTING=0' => ['test', '0', false],
            'CHATBOTS_TESTING vazia' => ['test', '', false],

            // APP_ENV é comparada com === 'test': sem trim e sem lowercase.
            'APP_ENV=Test' => ['Test', '1', false],
            'APP_ENV=TEST' => ['TEST', '1', false],
            'APP_ENV com espaço' => [' test', '1', false],
            'APP_ENV=testing' => ['testing', '1', false],
        ];
    }

    #[DataProvider('combinacoesDeAmbienteDeTesteProvider')]
    public function testAmbienteDeTesteExigeAsDuasVariaveisExatas(
        ?string $appEnv,
        ?string $chatbotsTesting,
        bool $esperado
    ): void {
        $this->definirAmbiente('APP_ENV', $appEnv);
        $this->definirAmbiente('CHATBOTS_TESTING', $chatbotsTesting);

        $this->assertSame($esperado, RuntimeEnvironment::isTesting());
    }

    /**
     * O construtor de bdTelegram e o config_loader repetem essa mesma condição
     * na mão. Este teste fixa a fonte da verdade: só o par exato liga o modo de
     * teste, e o padrão de qualquer outra combinação é produção.
     */
    public function testForaDoParExatoOAmbienteEhSempreProducaoENaoTeste(): void
    {
        $this->definirAmbiente('APP_ENV', null);
        $this->definirAmbiente('CHATBOTS_TESTING', '1');

        $this->assertFalse(RuntimeEnvironment::isTesting());
        $this->assertSame('production', RuntimeEnvironment::name());
    }

    // ---------------------------------------------------------------------
    // hasNetworkIsolation(): o portão que impede a suíte de falar com a rede
    // ---------------------------------------------------------------------

    /**
     * No macOS o isolamento vem do sandbox-exec montado por
     * scripts/quality/run_tests.php, sinalizado por CHATBOTS_OS_NETWORK_SANDBOX.
     * A variável precisa ser NECESSÁRIA: sem ela o bootstrap tem que abortar em
     * vez de deixar a suíte falar com a API do Telegram/OpenAI de verdade.
     *
     * No Linux o isolamento é lido do próprio kernel (namespace de rede e
     * interfaces em /sys/class/net), então a variável é irrelevante — e este
     * teste prova justamente isso, em vez de assumir.
     */
    public function testIsolamentoDeRedeNoMacOsDependeDoSandboxDeclarado(): void
    {
        $this->assertTrue(
            RuntimeEnvironment::hasNetworkIsolation(),
            'Precondição: a suíte só roda com a rede isolada.'
        );

        $this->definirAmbiente('CHATBOTS_OS_NETWORK_SANDBOX', null);

        if (PHP_OS_FAMILY === 'Darwin') {
            $this->assertFalse(
                RuntimeEnvironment::hasNetworkIsolation(),
                'Sem CHATBOTS_OS_NETWORK_SANDBOX o macOS não tem como provar o isolamento.'
            );

            $this->definirAmbiente('CHATBOTS_OS_NETWORK_SANDBOX', '0');
            $this->assertFalse(RuntimeEnvironment::hasNetworkIsolation());

            $this->definirAmbiente('CHATBOTS_OS_NETWORK_SANDBOX', '1');
            $this->assertTrue(RuntimeEnvironment::hasNetworkIsolation());

            return;
        }

        // Linux: o isolamento é verificado no kernel, não em variável.
        $this->assertTrue(
            RuntimeEnvironment::hasNetworkIsolation(),
            'No Linux o isolamento vem do namespace de rede, não da variável.'
        );
    }

    /**
     * Nenhuma das duas checagens pode se confundir: estar em ambiente de teste
     * não implica rede isolada, e é por isso que tests/bootstrap.php exige as
     * duas separadamente.
     */
    public function testAmbienteDeTesteEIsolamentoDeRedeSaoChecagensIndependentes(): void
    {
        $this->definirAmbiente('APP_ENV', 'production');
        $this->definirAmbiente('CHATBOTS_TESTING', null);

        $this->assertFalse(RuntimeEnvironment::isTesting());
        // Derrubar o modo de teste não afrouxa nem endurece o isolamento de rede.
        $this->assertTrue(RuntimeEnvironment::hasNetworkIsolation());
    }
}
