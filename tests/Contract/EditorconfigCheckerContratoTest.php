<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contrato do editorconfig-checker: a ferramenta só ajuda se o arquivo de
 * configuração, o pin do core e os pontos de execução continuarem juntos.
 * A suíte verifica o contrato estático; a execução do gate fica em
 * `composer check:editorconfig` e no CI.
 */
final class EditorconfigCheckerContratoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    private static function ler(string $relativo): string
    {
        $conteudo = file_get_contents(self::RAIZ . '/' . $relativo);
        self::assertIsString($conteudo, "Não foi possível ler {$relativo}.");

        return $conteudo;
    }

    /** @return array<string, mixed> */
    private static function json(string $relativo): array
    {
        $conteudo = json_decode(self::ler($relativo), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($conteudo, "{$relativo} deveria conter um objeto JSON.");

        /** @var array<string, mixed> $conteudo */
        return $conteudo;
    }

    public function testConfiguracaoUsaSomenteChavesOficiaisEPinDoCore(): void
    {
        $config = self::json('.editorconfig-checker.json');

        self::assertSame(
            'https://raw.githubusercontent.com/editorconfig-checker/editorconfig-checker/v4.0.1/.editorconfig-checker.schema.json',
            $config['$schema'] ?? null,
        );
        self::assertSame('v4.0.1', $config['Version'] ?? null);
        self::assertSame(
            [],
            array_values(array_diff(array_keys($config), ['$schema', 'Version', 'Exclude', 'Disable'])),
            'A configuração deve usar apenas as chaves do schema oficial.',
        );

        $excludes = $config['Exclude'] ?? null;
        self::assertIsArray($excludes);
        foreach (['(^|/)vendor/', '(^|/)logs/', '(^|/)\\.artifacts/', '\\.db$', '\\.min\\.(js|css)$'] as $exclude) {
            self::assertContains($exclude, $excludes);
        }
        // Não existe phpstan-baseline.neon neste repositório: exclusão para
        // arquivo ausente é regra que ninguém confere e que envelhece calada.
        self::assertNotContains('^phpstan-baseline\\.neon$', $excludes);

        $disable = $config['Disable'] ?? null;
        self::assertIsArray($disable);
        self::assertTrue($disable['IndentSize'] ?? false);
    }

    public function testEditorconfigDefineRegrasQueOGateDeveConferir(): void
    {
        $editorconfig = self::ler('.editorconfig');

        self::assertStringContainsString("root = true\n", $editorconfig);
        self::assertStringContainsString("end_of_line = lf\n", $editorconfig);
        self::assertStringContainsString("insert_final_newline = true\n", $editorconfig);
        self::assertStringContainsString("trim_trailing_whitespace = true\n", $editorconfig);
        self::assertStringContainsString("indent_style = space\n", $editorconfig);
        self::assertStringContainsString("[*.php]\nindent_size = 4\n", $editorconfig);
        self::assertStringContainsString("[*.md]\ntrim_trailing_whitespace = false\n", $editorconfig);
        self::assertStringContainsString('composer check:editorconfig', $editorconfig);
    }

    public function testComandoHookEWorkflowExecutamOGate(): void
    {
        $manifesto = self::json('composer.json');
        $scripts = $manifesto['scripts'] ?? null;
        self::assertIsArray($scripts);
        self::assertSame('ec', $scripts['check:editorconfig'] ?? null);
        $check = $scripts['check'] ?? null;
        self::assertIsArray($check);
        self::assertContains('@check:editorconfig', $check);

        $lefthook = self::ler('.lefthook/pre-push/qualidade.sh');
        self::assertStringContainsString('with_local_config.sh composer check:all', $lefthook);

        $workflow = self::ler('.github/workflows/ci.yml');
        self::assertStringContainsString('run: composer check:editorconfig', $workflow);
    }
}
