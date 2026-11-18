<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contrato dos artefatos publicados pelo CI. A action v4+ não aceita uploads
 * repetidos e, por padrão, descarta arquivos ocultos; os caminhos e opções
 * abaixo precisam continuar alinhados aos relatórios que os gates produzem.
 */
final class UploadArtifactContratoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';
    private const SHA_V702 = 'cf430e030ddbb5b0abf93d22962f4752f3646cd9';

    private static function ler(string $relativo): string
    {
        $conteudo = file_get_contents(self::RAIZ . '/' . $relativo);
        self::assertIsString($conteudo, "Não foi possível ler {$relativo}.");

        return $conteudo;
    }

    /** @return list<string> */
    private static function blocosDeUpload(string $workflow): array
    {
        $quantidade = preg_match_all(
            '/^      - name:.*?(?=^      - name:|\z)/ms',
            $workflow,
            $correspondencias,
        );
        self::assertIsInt($quantidade);

        $blocos = array_values(array_filter(
            $correspondencias[0],
            static fn (string $bloco): bool => str_contains(
                $bloco,
                'uses: actions/upload-artifact@',
            ),
        ));

        return $blocos;
    }

    /** @param list<string> $blocos */
    private static function blocoComArtefato(array $blocos, string $nome): string
    {
        foreach ($blocos as $bloco) {
            if (str_contains($bloco, "          name: {$nome}\n")) {
                return $bloco;
            }
        }

        self::fail("O artefato {$nome} não foi encontrado.");
    }

    /** @return list<string> */
    private static function todosOsBlocos(): array
    {
        $blocos = [];
        foreach (['.github/workflows/ci.yml'] as $workflow) {
            $blocos = [...$blocos, ...self::blocosDeUpload(self::ler($workflow))];
        }

        return $blocos;
    }

    public function testTodasAsInvocacoesFixamAV702PorShaEUsamNomesUnicos(): void
    {
        $blocos = self::todosOsBlocos();
        self::assertCount(1, $blocos);

        $refs = [];
        $nomes = [];
        foreach ($blocos as $bloco) {
            $encontrada = preg_match(
                '/uses: actions\/upload-artifact@([0-9a-f]{40}) # (v[0-9.]+)/',
                $bloco,
                $partes,
            );
            self::assertSame(1, $encontrada);
            $refs[] = $partes[1] . ' #' . $partes[2];

            $nomeEncontrado = preg_match('/^          name: ([a-z0-9-]+)$/m', $bloco, $nome);
            self::assertSame(1, $nomeEncontrado);
            $nomes[] = $nome[1];
        }

        self::assertSame(
            [self::SHA_V702 . ' #v7.0.2'],
            array_values(array_unique($refs)),
        );
        self::assertCount(count($nomes), array_unique($nomes));
        sort($nomes);
        self::assertSame(['reports'], $nomes);
    }

    public function testRelatorioInformativoMantemDefaultsSegurosENaoFalhaPorAusencia(): void
    {
        $blocos = self::todosOsBlocos();
        $relatorios = self::blocoComArtefato($blocos, 'reports');

        self::assertStringContainsString("        if: always()\n", $relatorios);
        self::assertStringContainsString("            rector.log\n", $relatorios);
        self::assertStringContainsString("            outdated.log\n", $relatorios);
        self::assertStringContainsString("          if-no-files-found: warn\n", $relatorios);
        self::assertStringNotContainsString('include-hidden-files:', $relatorios);

        $todos = implode("\n", $blocos);
        self::assertStringContainsString("          retention-days: 14\n", $todos);
        self::assertStringNotContainsString('overwrite:', $todos);
        self::assertStringNotContainsString('archive:', $todos);
        self::assertStringNotContainsString('compression-level:', $todos);
    }

    public function testWorkflowsMantemPermissaoMinimaSemSegredos(): void
    {
        foreach (['.github/workflows/ci.yml'] as $arquivo) {
            $workflow = self::ler($arquivo);
            self::assertStringContainsString("permissions:\n  contents: read", $workflow);
            self::assertStringNotContainsString('actions: write', $workflow);
            self::assertStringNotContainsString('secrets.', $workflow);
        }
    }
}
