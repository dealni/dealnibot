<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Webmozart\Glob\Glob;

/**
 * Contrato do verificador de dependências: manifesto, configuração e CI têm
 * de apontar para a mesma superfície de código.
 */
final class ComposerRequireCheckerContratoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
    /** Código procedural e entrypoints deste bot, na ordem do composer-require-checker.json. */
    private const ARQUIVOS_VARRIDOS = [
        'comandos/**/*.php',
        'config/**/*.php',
        'inclusos/**/*.php',
        'processos/**/*.php',
        'scripts/deploy/**/*.php',
        'scripts/operacao/**/*.php',
        'bot.php',
        'bot_web.php',
        'health.php',
        'index.php',
        'ready.php',
        'roteador.php',
    ];
    // --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

    /**
     * Devolve a lista como está no bloco de dados: o analisador estático não
     * pode concluir "sempre vazia" ou "sempre cheia" a partir do valor deste
     * repositório, porque o valor é dado, não código.
     *
     * @param list<string> $dados
     * @return list<string>
     */
    private static function dados(array $dados): array
    {
        return $dados;
    }

    /** @return array<string, mixed> */
    private static function json(string $relativo): array
    {
        $conteudo = file_get_contents(self::RAIZ . '/' . $relativo);
        self::assertIsString($conteudo, "Não foi possível ler {$relativo}.");

        $valor = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($valor, "{$relativo} deveria conter um objeto JSON.");

        /** @var array<string, mixed> $valor */
        return $valor;
    }

    public function testConfiguracaoUsaSomenteChavesOficiaisECobreEntrypointsERunners(): void
    {
        $config = self::json('composer-require-checker.json');

        self::assertSame(
            ['php-core-extensions', 'scan-files', 'symbol-whitelist'],
            array_keys($config),
            'O JSON deve usar apenas as três chaves documentadas pelo checker.',
        );

        $arquivos = $config['scan-files'] ?? null;
        self::assertIsArray($arquivos);
        $padroesEsperados = self::dados(self::ARQUIVOS_VARRIDOS);
        self::assertSame(
            $padroesEsperados,
            $arquivos,
            'Todo código procedural e entrypoint precisa continuar no escopo.',
        );

        $raiz = realpath(self::RAIZ);
        self::assertIsString($raiz);
        foreach ($padroesEsperados as $padrao) {
            $achados = Glob::glob($raiz . '/' . $padrao);
            // A API retorna array e lança exceção para glob inválido.
            self::assertNotSame([], $achados, "Padrão sem arquivos: {$padrao}");
        }
    }

    public function testPastasDeProdutoCobremRaizESubpastasSemIncluirOutrosArquivos(): void
    {
        $raiz = realpath(self::RAIZ);
        self::assertIsString($raiz);
        foreach (self::dados(self::ARQUIVOS_VARRIDOS) as $padrao) {
            // Entrypoints individuais continuam com seus caminhos exatos.
            if (!str_contains($padrao, '/')) {
                continue;
            }
            self::assertStringEndsWith('/**/*.php', $padrao);
            $pasta = substr($padrao, 0, -strlen('/**/*.php'));

            // O checker usa globs Ant: ** inclui zero ou mais diretórios.
            foreach (['componente.php', 'Extraidos/componente.php', 'Extraidos/Interno/componente.php'] as $relativo) {
                $arquivo = $raiz . '/' . $pasta . '/' . $relativo;
                self::assertTrue(Glob::match($arquivo, $raiz . '/' . $padrao), 'Componente fora do scan: ' . $arquivo);
            }
            foreach ([$pasta . '/ignorar.txt', 'fora/' . $pasta . '/componente.php', 'vendor/componente.php', 'tests/componente.php', 'tools/componente.php'] as $relativo) {
                $arquivo = $raiz . '/' . $relativo;
                self::assertFalse(Glob::match($arquivo, $raiz . '/' . $padrao), 'Arquivo fora do escopo entrou no scan: ' . $arquivo);
            }
        }
    }

    public function testComposerScriptEJobDoCiExecutamCheckerDepoisDaInstalacao(): void
    {
        $manifesto = self::json('composer.json');
        $scripts = $manifesto['scripts'] ?? null;
        self::assertIsArray($scripts);
        self::assertSame(
            'composer-require-checker check --config-file=composer-require-checker.json',
            $scripts['check:deps:undeclared'] ?? null,
        );

        $workflow = file_get_contents(self::RAIZ . '/.github/workflows/ci.yml');
        if ($workflow === false) {
            self::fail('Não foi possível ler .github/workflows/ci.yml.');
        }
        $inicioQuality = strpos($workflow, "  quality:\n");
        $fimQuality = strpos($workflow, "  workflows:\n", $inicioQuality === false ? 0 : $inicioQuality);
        self::assertIsInt($inicioQuality);
        self::assertIsInt($fimQuality);
        $quality = substr($workflow, $inicioQuality, $fimQuality - $inicioQuality);
        $instalacao = strpos($quality, 'run: composer install --no-interaction');
        $checker = strpos($quality, 'run: composer check:deps:undeclared');
        self::assertIsInt($instalacao);
        self::assertIsInt($checker);
        self::assertLessThan($checker, $instalacao, 'O checker precisa rodar após composer install.');
    }
}
