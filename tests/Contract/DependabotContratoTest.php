<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contrato do Dependabot: cada manifesto versionado tem uma entrada própria,
 * e cada action externa continua legível e imutável por SHA completo.
 */
final class DependabotContratoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    private static function ler(string $relativo): string
    {
        $conteudo = file_get_contents(self::RAIZ . '/' . $relativo);
        self::assertIsString($conteudo, "Não foi possível ler {$relativo}.");

        return $conteudo;
    }

    /** @return list<array{ecosystem: string, directory: string, text: string}> */
    private static function blocosDependabot(): array
    {
        $texto = self::ler('.github/dependabot.yml');
        $linhas = preg_split('/\R/u', $texto);
        self::assertIsArray($linhas);
        $blocos = [];
        $atual = null;

        foreach ($linhas as $linha) {
            if (preg_match(
                '/^\s*-\s+package-ecosystem:\s*["\']?([^"\'\s]+)["\']?\s*$/u',
                $linha,
                $correspondencia,
            ) === 1) {
                if ($atual !== null) {
                    $blocos[] = $atual;
                }
                $atual = [
                    'ecosystem' => $correspondencia[1],
                    'directory' => '',
                    'text' => $linha . "\n",
                ];
                continue;
            }
            if ($atual === null) {
                continue;
            }
            $atual['text'] .= $linha . "\n";
            if (preg_match(
                '/^\s+directory:\s*["\']([^"\']+)["\']\s*$/u',
                $linha,
                $correspondencia,
            ) === 1) {
                $atual['directory'] = $correspondencia[1];
            }
        }
        if ($atual !== null) {
            $blocos[] = $atual;
        }

        return $blocos;
    }

    /** @return array{ecosystem: string, directory: string, text: string} */
    private static function bloco(string $ecosystem, string $directory): array
    {
        foreach (self::blocosDependabot() as $bloco) {
            if ($bloco['ecosystem'] === $ecosystem && $bloco['directory'] === $directory) {
                return $bloco;
            }
        }

        self::fail("Bloco {$ecosystem} em {$directory} ausente do Dependabot.");
    }

    public function testTodosOsManifestosTemBlocoSemSobreposicao(): void
    {
        $esperados = [
            ['npm', '/', ['package.json', 'package-lock.json']],
            ['composer', '/', ['composer.json', 'composer.lock']],
            ['composer', '/tools/phpcpd', ['tools/phpcpd/composer.json', 'tools/phpcpd/composer.lock']],
        ];
        $chaves = [];

        foreach ($esperados as [$ecosystem, $directory, $manifestos]) {
            $bloco = self::bloco($ecosystem, $directory);
            $chave = $ecosystem . ':' . $directory;
            self::assertNotContains($chave, $chaves, "Bloco {$chave} está duplicado.");
            $chaves[] = $chave;
            self::assertMatchesRegularExpression('/^\s*schedule:\s*$/m', $bloco['text']);
            self::assertMatchesRegularExpression('/^\s+interval:\s+(weekly|monthly)\s*$/m', $bloco['text']);
            self::assertMatchesRegularExpression('/^\s+open-pull-requests-limit:\s+[1-9]\d*\s*$/m', $bloco['text']);

            foreach ($manifestos as $manifesto) {
                self::assertFileExists(self::RAIZ . '/' . $manifesto);
            }
        }

        // Com `directory: "/"` o Dependabot varre só `.github/workflows` e o
        // `action.yml` da raiz; a composite action precisa de bloco próprio
        // para o SHA fixado dentro dela não congelar (dependabot-core#6704).
        foreach (['/', '/.github/actions/setup-node', '/.github/actions/setup-postgres'] as $diretorio) {
            $actions = self::bloco('github-actions', $diretorio);
            self::assertMatchesRegularExpression('/^\s+patterns:\s+\["\*"\]\s*$/m', $actions['text']);
            self::assertStringContainsString('prefix: "chore"', $actions['text']);
            self::assertMatchesRegularExpression('/^\s+interval:\s+weekly\s*$/m', $actions['text']);
        }
        self::assertCount(
            count($chaves),
            array_unique($chaves),
            'Há sobreposição de diretórios nos blocos de manifestos.',
        );
    }

    public function testCadaBlocoUsaPrefixoConventionalEAgendasValidas(): void
    {
        $config = self::ler('.github/dependabot.yml');
        self::assertStringContainsString("version: 2\n", $config);
        self::assertSame(6, substr_count($config, 'package-ecosystem:'));
        self::assertSame(6, substr_count($config, 'prefix: "chore"'));
        self::assertSame(6, substr_count($config, 'open-pull-requests-limit:'));
        self::assertStringNotContainsString('ignore:', $config);
        self::assertStringNotContainsString('labels:', $config);
    }

    public function testActionsExternasEmWorkflowsECompositeUsamShaCompletoERelease(): void
    {
        $workflowsYml = glob(self::RAIZ . '/.github/workflows/*.yml');
        if ($workflowsYml === false) {
            $workflowsYml = [];
        }
        $workflowsYaml = glob(self::RAIZ . '/.github/workflows/*.yaml');
        if ($workflowsYaml === false) {
            $workflowsYaml = [];
        }
        $arquivos = array_merge(
            $workflowsYml,
            $workflowsYaml,
            [
                self::RAIZ . '/.github/actions/setup-node/action.yml',
                self::RAIZ . '/.github/actions/setup-postgres/action.yml',
            ],
        );
        self::assertContains(self::RAIZ . '/.github/actions/setup-node/action.yml', $arquivos);

        foreach ($arquivos as $arquivo) {
            $texto = file_get_contents($arquivo);
            self::assertIsString($texto, "Não foi possível ler {$arquivo}.");
            $linhas = preg_split('/\R/u', $texto);
            self::assertIsArray($linhas);
            foreach ($linhas as $linha) {
                if (preg_match('/^\s*(?:-\s+)?uses:\s*(\S+)/u', $linha, $uso) !== 1) {
                    continue;
                }
                if (str_starts_with($uso[1], './')) {
                    continue;
                }
                self::assertMatchesRegularExpression(
                    '/@[0-9a-f]{40}\s+#\s*v?\d+\.\d+\.\d+\s*$/u',
                    $linha,
                    "Action externa sem SHA e comentário de release: {$linha}",
                );
            }
        }
    }
}
