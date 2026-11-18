<?php

declare(strict_types=1);

namespace Tests;

use PhpCsFixer\ConfigInterface;
use PhpCsFixer\Fixer\Import\OrderedImportsFixer;
use PhpCsFixer\ParallelAwareConfigInterface;
use PhpCsFixer\Tokenizer\Tokens;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Contrato do PHP-CS-Fixer: o comando e o Finder precisam continuar
 * apontando para o mesmo conjunto de arquivos e regras.
 */
final class PhpCsFixerContratoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    private static function raizReal(): string
    {
        $raiz = realpath(self::RAIZ);
        self::assertIsString($raiz);

        return $raiz;
    }

    /** @return array<string, mixed> */
    private static function composer(): array
    {
        $manifesto = json_decode(
            (string) file_get_contents(self::RAIZ . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifesto);

        /** @var array<string, mixed> $manifesto */
        return $manifesto;
    }

    public function testScriptsUsamCheckParaVerificacaoEFutureMode(): void
    {
        $scripts = self::composer()['scripts'] ?? null;
        self::assertIsArray($scripts);

        $check = $scripts['format:check'] ?? null;
        self::assertIsString($check);
        self::assertStringContainsString('PHP_CS_FIXER_FUTURE_MODE=1', $check);
        self::assertStringContainsString('php-cs-fixer check', $check);
        self::assertStringContainsString('--diff', $check);
        self::assertStringContainsString('--format=txt', $check);
        self::assertStringNotContainsString('--dry-run', $check);

        $fix = $scripts['format'] ?? null;
        self::assertIsString($fix);
        self::assertStringContainsString('PHP_CS_FIXER_FUTURE_MODE=1', $fix);
        self::assertStringContainsString('php-cs-fixer fix', $fix);

        $lefthook = file_get_contents(self::RAIZ . '/.lefthook/pre-push/qualidade.sh');
        self::assertIsString($lefthook);
        self::assertStringContainsString('with_local_config.sh composer check:all', $lefthook);

        $workflow = file_get_contents(self::RAIZ . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);
        self::assertStringContainsString('run: composer format:check', $workflow);
    }

    public function testImportsUsamOrdemExplicitaMesmoQuandoAlfabetoFavoreceFuncoes(): void
    {
        $config = require self::RAIZ . '/.php-cs-fixer.dist.php';
        self::assertInstanceOf(ConfigInterface::class, $config);
        $regra = $config->getRules()['ordered_imports'] ?? null;
        self::assertSame([
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ], $regra);
        $fixer = new OrderedImportsFixer();
        $fixer->configure($regra);
        $tokens = Tokens::fromCode("<?php\nuse function a;\nuse const B;\nuse Z;\n");
        $fixer->fix(new SplFileInfo('fixture-imports.php'), $tokens);

        self::assertSame("<?php\nuse Z;\nuse function a;\nuse const B;\n", $tokens->generateCode());
    }

    public function testConfigExpõeApiCacheParalelismoRegrasERiscosEsperados(): void
    {
        require_once self::RAIZ . '/vendor/autoload.php';
        $config = require self::RAIZ . '/.php-cs-fixer.dist.php';

        self::assertInstanceOf(ConfigInterface::class, $config);
        self::assertInstanceOf(ParallelAwareConfigInterface::class, $config);
        $parallel = $config->getParallelConfig();
        self::assertGreaterThan(0, $parallel->getFilesPerProcess());
        self::assertGreaterThan(0, $parallel->getMaxProcesses());
        self::assertGreaterThan(0, $parallel->getProcessTimeout());
        self::assertTrue($config->getUsingCache());
        self::assertSame(self::raizReal() . '/.artifacts/quality/cache/php-cs-fixer.cache', $config->getCacheFile());
        self::assertFalse($config->getRiskyAllowed());
        self::assertSame(
            [
                '@PSR12' => true,
                'array_syntax' => ['syntax' => 'short'],
                'no_unused_imports' => true,
                'ordered_imports' => [
                    'imports_order' => ['class', 'function', 'const'],
                    'sort_algorithm' => 'alpha',
                ],
            ],
            $config->getRules(),
        );

        $arquivos = [];
        foreach ($config->getFinder() as $arquivo) {
            $arquivos[] = $arquivo->getPathname();
            self::assertSame('php', $arquivo->getExtension());
        }
        self::assertNotEmpty($arquivos);
        self::assertNotContains(self::raizReal() . '/tools/quality/semgrep/project/fixtures/violations.php', $arquivos);
        self::assertNotContains(self::raizReal() . '/.php-cs-fixer.dist.php', $arquivos);
    }
}
