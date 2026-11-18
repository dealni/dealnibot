<?php

declare(strict_types=1);

namespace Tests;

use Composer\Semver\Semver;
use PHPUnit\Framework\TestCase;

/**
 * Contrato da instalação isolada do PHPCPD: o manifesto e o lock precisam
 * continuar na linha suportada, e o wrapper/CI precisam consumir a mesma
 * árvore sem ocultar arquivos de produto ou seus erros.
 */
final class PhpCpdContratoTest extends TestCase
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
        $dados = json_decode(self::ler($relativo), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($dados, "{$relativo} deveria conter um objeto JSON.");

        /** @var array<string, mixed> $dados */
        return $dados;
    }

    /** @return array<string, mixed> */
    private static function pacoteNoLock(string $relativo, string $nome): array
    {
        $lock = self::json($relativo);
        $pacotes = $lock['packages-dev'] ?? null;
        self::assertIsArray($pacotes);

        foreach ($pacotes as $pacote) {
            if (!is_array($pacote) || ($pacote['name'] ?? null) !== $nome) {
                continue;
            }

            /** @var array<string, mixed> $pacote */
            return $pacote;
        }

        self::fail("{$nome} não está em {$relativo}.");
    }

    public function testManifestoELockFixamPhpCpdNove(): void
    {
        $manifesto = self::json('tools/phpcpd/composer.json');
        $requireDev = $manifesto['require-dev'] ?? null;
        self::assertIsArray($requireDev);
        self::assertSame('^9.2', $requireDev['systemsdk/phpcpd'] ?? null);
        $require = $manifesto['require'] ?? null;
        self::assertIsArray($require);
        self::assertSame('>=8.5.12 <8.6', $require['php'] ?? null);
        $config = $manifesto['config'] ?? null;
        self::assertIsArray($config);
        $platform = $config['platform'] ?? null;
        self::assertIsArray($platform);
        self::assertSame('8.5.12', $platform['php'] ?? null);

        // A linha 9.2 usa a tokenização que considera operadores de um caractere.
        // O lock precisa preservar essa precisão e a compatibilidade com PHP 8.5.
        $phpcpd = self::pacoteNoLock('tools/phpcpd/composer.lock', 'systemsdk/phpcpd');
        $versao = $phpcpd['version'] ?? null;
        self::assertIsString($versao);
        self::assertTrue(
            Semver::satisfies($versao, '^9.2'),
            "tools/phpcpd/composer.lock trouxe {$versao}, fora da linha ^9.2.",
        );
        $requires = $phpcpd['require'] ?? null;
        self::assertIsArray($requires);
        // O PHP exigido pela ferramenta precisa continuar aceitando a linha 8.5
        // fixada na plataforma do manifesto.
        $linhaPhp = $requires['php'] ?? null;
        self::assertIsString($linhaPhp);
        self::assertTrue(Semver::satisfies('8.5.12', $linhaPhp), "phpcpd exige php {$linhaPhp}, incompatível com a plataforma 8.5.12.");
        self::assertSame('*', $requires['ext-dom'] ?? null);
        self::assertSame('*', $requires['ext-mbstring'] ?? null);
        foreach (['phpunit/php-file-iterator', 'phpunit/php-timer', 'sebastian/cli-parser', 'sebastian/version'] as $dependencia) {
            self::assertIsString($requires[$dependencia] ?? null, "phpcpd precisa continuar declarando {$dependencia}.");
        }
    }

    public function testDependenciasDoLockNaoTemMaisOConflitoHistorico(): void
    {
        $manifesto = self::json('composer.json');
        $requireDev = $manifesto['require-dev'] ?? null;
        self::assertIsArray($requireDev);
        self::assertSame('^13.4', $requireDev['phpunit/phpunit'] ?? null);

        // O conflito histórico era o cli-parser do phpcpd ficar fora da linha
        // que o PHPUnit exige. A prova é a versão instalada em tools/phpcpd
        // satisfazer o constraint do PHPUnit da raiz, qualquer que seja o patch
        // que o Dependabot tenha trazido.
        $phpunit = self::pacoteNoLock('composer.lock', 'phpunit/phpunit');
        $requires = $phpunit['require'] ?? null;
        self::assertIsArray($requires);
        $linhaCliParser = $requires['sebastian/cli-parser'] ?? null;
        self::assertIsString($linhaCliParser);
        $versaoCliParser = self::pacoteNoLock('tools/phpcpd/composer.lock', 'sebastian/cli-parser')['version'] ?? null;
        self::assertIsString($versaoCliParser);
        self::assertTrue(
            Semver::satisfies($versaoCliParser, $linhaCliParser),
            "sebastian/cli-parser {$versaoCliParser} em tools/phpcpd não satisfaz {$linhaCliParser} exigido pelo PHPUnit.",
        );
    }

    public function testWrapperCobreRaizComExclusoesEUsaBaseline(): void
    {
        $wrapper = self::ler('scripts/quality/run_phpcpd.php');

        self::assertStringContainsString('. escapeshellarg($raiz)', $wrapper);
        self::assertStringContainsString('--min-lines=10 --min-tokens=70', $wrapper);
        foreach ([
            'vendor',
            'node_modules',
            'tests',
            'tools',
            '.git',
            '.artifacts',
            '.github',
            '.vscode',
            'docs',
            'deploy',
            'logs',
            'composer-unused.php',
            'rector.php',
            '.php-cs-fixer.dist.php',
        ] as $exclusao) {
            self::assertStringContainsString("'{$exclusao}'", $wrapper);
        }
        self::assertStringContainsString("'/tools/phpcpd/vendor/bin/phpcpd'", $wrapper);
        self::assertStringContainsString("\$duplicacaoBaseline['clones']", $wrapper);

        $baseline = self::json('tools/quality/duplicacao-baseline.json')['duplicacao'] ?? null;
        self::assertIsArray($baseline);
        self::assertIsInt($baseline['clones'] ?? null);
        self::assertIsInt($baseline['linhas'] ?? null);
    }

    public function testCIRecusaAtualizacaoDoBaselineAntesDeExecutarFerramenta(): void
    {
        $baselineAntes = self::ler('tools/quality/duplicacao-baseline.json');
        $ciAnterior = getenv('CI');
        putenv('CI=1');
        $canos = [];
        $processo = null;

        try {
            $processo = proc_open(
                [PHP_BINARY, self::RAIZ . '/scripts/quality/run_phpcpd.php', '--atualizar-baseline'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $canos,
                self::RAIZ,
            );
            self::assertIsResource($processo);
            $stdout = stream_get_contents($canos[1]);
            $stderr = stream_get_contents($canos[2]);
            fclose($canos[1]);
            fclose($canos[2]);
            $status = proc_close($processo);
            $processo = null;

            self::assertSame(2, $status);
            self::assertSame('', $stdout);
            self::assertStringContainsString(
                'No CI o baseline de duplicação é somente leitura',
                $stderr,
            );
            self::assertSame($baselineAntes, self::ler('tools/quality/duplicacao-baseline.json'));
        } finally {
            foreach ($canos as $cano) {
                if (is_resource($cano)) {
                    fclose($cano);
                }
            }
            if (is_resource($processo)) {
                @proc_close($processo);
            }
            if ($ciAnterior === false) {
                putenv('CI');
            } else {
                putenv('CI=' . $ciAnterior);
            }
        }
    }

    public function testManifestoSeparadoEInstaladoEAuditadoNosPontosDeUso(): void
    {
        $scripts = self::json('composer.json')['scripts'] ?? null;
        self::assertIsArray($scripts);
        self::assertSame('@php scripts/quality/run_phpcpd.php', $scripts['check:duplication'] ?? null);
        self::assertSame(
            '@php scripts/quality/run_phpcpd.php --atualizar-baseline',
            $scripts['duplication:baseline'] ?? null,
        );

        $ci = self::ler('.github/workflows/ci.yml');
        self::assertStringContainsString('composer install -d tools/phpcpd', $ci);
        self::assertStringContainsString('run: composer check:duplication', $ci);

        $security = self::ler('.github/workflows/security.yml');
        self::assertStringContainsString('run: composer check:vulnerabilities', $security);
        self::assertStringContainsString(
            "'/tools/phpcpd'",
            self::ler('scripts/quality/composer_audit_retry.php'),
        );
    }
}
