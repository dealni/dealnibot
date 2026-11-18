<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Nenhum código de produção lê ou publica dependência por estado global.
 *
 * Telegram, banco, Redis e os demais clientes chegam às funções por parâmetro
 * tipado e aos comandos pelo escopo de quem os inclui. Com `global $x` ou
 * `$GLOBALS['x']`, a função dependia de quem a chamou ter deixado a variável
 * certa no escopo global: o callback de long polling do bot.php tem escopo
 * próprio e não publica nada, os testes precisavam copiar o dublê para
 * `$GLOBALS` no setUp, e um chamador que esquecesse só descobria em produção.
 *
 * A varredura é recursiva a partir da raiz, então uma pasta nova de produção
 * entra sozinha. O tokenizador acha `global` e `$GLOBALS` mesmo no meio de uma
 * expressão, sem confundir com comentário ou texto.
 */
final class SemEstadoGlobalTest extends TestCase
{
    /** Pastas que não são código de produção. */
    private const IGNORADAS = ['.git', '.artifacts', 'node_modules', 'vendor', 'tests'];

    /** Entradas que toda varredura correta precisa achar. */
    private const OBRIGATORIOS = ['bot.php', 'bot_web.php', 'roteador.php'];

    public function testCodigoDeProducaoNaoUsaGlobalNemGlobals(): void
    {
        $achados = [];
        $arquivos = $this->arquivosDeProducao();
        foreach ($arquivos as $relativo => $absoluto) {
            $codigo = file_get_contents($absoluto);
            $this->assertIsString($codigo, "não foi possível ler {$relativo}");
            foreach (self::usosDeEstadoGlobal($codigo) as $linha) {
                $achados[] = "{$relativo}:{$linha}";
            }
        }

        // Sem esta trava, um filtro de pastas errado aprovaria sem ler nada.
        foreach (self::OBRIGATORIOS as $obrigatorio) {
            $this->assertArrayHasKey($obrigatorio, $arquivos, "a varredura não achou {$obrigatorio}");
        }
        $this->assertNotEmpty(
            array_filter(array_keys($arquivos), static fn (string $caminho): bool => str_starts_with($caminho, 'comandos/')),
            'a varredura não desceu em comandos/'
        );
        $this->assertSame([], $achados, 'Dependência por estado global: passe-a como parâmetro.');
    }

    /** Controle do detector: um tokenizador que nunca acha nada deixaria o contrato verde. */
    public function testDetectorAchaGlobalEGlobalsForaDeComentario(): void
    {
        $this->assertSame([3], self::usosDeEstadoGlobal("<?php\nfunction f(): void {\n    global \$redis;\n}\n"));
        $this->assertSame([2], self::usosDeEstadoGlobal("<?php\n\$x = \$GLOBALS['telegram'] ?? null;\n"));
        $this->assertSame([], self::usosDeEstadoGlobal("<?php\n// global \$redis; \$GLOBALS['bd']\n\$t = 'global \$bd';\n"));
    }

    /** @return list<int> linhas com `global` ou `$GLOBALS` */
    private static function usosDeEstadoGlobal(string $codigo): array
    {
        $linhas = [];
        foreach (\PhpToken::tokenize($codigo) as $token) {
            if ($token->id === T_GLOBAL || ($token->id === T_VARIABLE && $token->text === '$GLOBALS')) {
                $linhas[] = $token->line;
            }
        }

        return $linhas;
    }

    /** @return array<string, string> caminho relativo => absoluto, em ordem */
    private function arquivosDeProducao(): array
    {
        $raiz = dirname(__DIR__, 2);
        $filtro = static function (SplFileInfo $arquivo): bool {
            if ($arquivo->isDir()) {
                return !in_array($arquivo->getFilename(), self::IGNORADAS, true);
            }

            return $arquivo->getExtension() === 'php';
        };

        $arquivos = [];
        $iterador = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS),
                $filtro
            )
        );
        foreach ($iterador as $arquivo) {
            if ($arquivo instanceof SplFileInfo) {
                $arquivos[substr($arquivo->getPathname(), strlen($raiz) + 1)] = $arquivo->getPathname();
            }
        }
        ksort($arquivos);

        return $arquivos;
    }
}
