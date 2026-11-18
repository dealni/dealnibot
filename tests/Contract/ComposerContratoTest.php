<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contrato da ferramenta Composer: o manifesto, o lock, o autoload e os
 * runners de CI precisam permanecer alinhados.
 */
final class ComposerContratoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
    /** Runners CLI fora do classmap otimizado (ficam vazios onde não existem). */
    private const EXCLUIDOS_DO_CLASSMAP = [
    ];

    /** Plugins do Composer autorizados por este produto. */
    private const PLUGINS_AUTORIZADOS = [
    ];

    /** Extensões que o job de segurança instala antes do audit. */
    private const EXTENSOES_DO_JOB_DE_SEGURANCA = 'curl, mbstring, pdo_pgsql, redis';
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

    public function testAutoloadPSR4ExcluiRunnerGlobalDaGeracaoOtimizada(): void
    {
        $manifesto = self::json('composer.json');
        $autoload = $manifesto['autoload'] ?? null;
        self::assertIsArray($autoload);
        self::assertSame(['App\\' => 'src/'], $autoload['psr-4'] ?? null);
        $excluidos = self::dados(self::EXCLUIDOS_DO_CLASSMAP);
        self::assertSame($excluidos === [] ? null : $excluidos, $autoload['exclude-from-classmap'] ?? null);
        foreach ($excluidos as $excluido) {
            self::assertFileExists(self::RAIZ . $excluido);
        }
    }

    public function testConfigMantemPlataformaEPoliticaDePluginsFechadas(): void
    {
        $manifesto = self::json('composer.json');
        $config = $manifesto['config'] ?? null;
        self::assertIsArray($config);
        self::assertSame(['php' => '8.5.12'], $config['platform'] ?? null);
        self::assertTrue($config['platform-check'] ?? false);
        $allowPlugins = $config['allow-plugins'] ?? null;
        self::assertIsArray($allowPlugins);
        $autorizados = array_keys($allowPlugins);
        sort($autorizados);
        self::assertSame(self::dados(self::PLUGINS_AUTORIZADOS), $autorizados);
        foreach ($allowPlugins as $plugin => $ativo) {
            self::assertTrue($ativo, "allow-plugins deveria autorizar {$plugin} explicitamente.");
        }

        $lock = self::json('composer.lock');
        $platformOverrides = $lock['platform-overrides'] ?? null;
        self::assertIsArray($platformOverrides);
        self::assertSame('8.5.12', $platformOverrides['php'] ?? null);
        $platform = $lock['platform'] ?? null;
        self::assertIsArray($platform);
        self::assertSame('^6.3', $platform['ext-redis'] ?? null);
    }

    public function testScriptsComposerUsamBinarioAtualEValidamLock(): void
    {
        $scripts = self::json('composer.json')['scripts'] ?? null;
        self::assertIsArray($scripts);
        self::assertSame(
            '@composer validate --strict --check-lock --no-check-publish',
            $scripts['check:config'] ?? null,
        );
        self::assertSame(
            '@composer outdated --direct --no-interaction',
            $scripts['deps:outdated'] ?? null,
        );
    }

    public function testJobDeSegurancaInstalaEConfereAPlataformaAntesDoAudit(): void
    {
        $workflow = file_get_contents(self::RAIZ . '/.github/workflows/security.yml');
        self::assertIsString($workflow);
        $inicio = strpos($workflow, "  dependencies:\n");
        $fim = strpos($workflow, "  gitleaks:\n", $inicio === false ? 0 : $inicio);
        self::assertIsInt($inicio);
        self::assertIsInt($fim);
        $job = substr($workflow, $inicio, $fim - $inicio);
        self::assertStringContainsString(
            '          extensions: ' . self::EXTENSOES_DO_JOB_DE_SEGURANCA . "\n",
            $job,
        );

        $instalacao = strpos($job, 'run: composer install --no-interaction --prefer-dist --no-progress');
        $plataforma = strpos($job, 'run: composer check-platform-reqs --no-interaction');
        $audit = strpos($job, 'run: composer check:vulnerabilities');
        self::assertIsInt($instalacao);
        self::assertIsInt($plataforma);
        self::assertIsInt($audit);
        self::assertLessThan($audit, $plataforma);
        self::assertLessThan($plataforma, $instalacao);
        // O runner canônico cobre os dois locks; o teste de ferramentas
        // executa o runner com um Composer falso e prova a falha do isolado.
        self::assertStringContainsString('run: npm run test:quality-tools', $job);
        $runner = file_get_contents(self::RAIZ . '/scripts/quality/composer_audit_retry.php');
        self::assertIsString($runner);
        self::assertStringContainsString("'/tools/phpcpd'", $runner);
    }

    public function testCIValidaOsDoisManifestosComSeusLocks(): void
    {
        $workflow = file_get_contents(self::RAIZ . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);
        self::assertStringContainsString(
            'run: composer validate --strict --check-lock --no-check-publish --no-interaction',
            $workflow,
        );
        self::assertStringContainsString(
            'run: composer -d tools/phpcpd validate --strict --check-lock --no-check-publish --no-interaction',
            $workflow,
        );
        self::assertStringContainsString(
            'run: composer check-platform-reqs --working-dir=tools/phpcpd --no-interaction',
            $workflow,
        );
    }

    public function testHookValidaOManifestoPrincipalEOIsoladoQuandoAlterados(): void
    {
        $hook = file_get_contents(self::RAIZ . '/scripts/quality/check_staged.sh');
        self::assertIsString($hook);
        self::assertStringContainsString(
            "tem_arquivo '^composer\\.(json|lock)$'",
            $hook,
        );
        self::assertStringContainsString(
            'composer validate --strict --check-lock --no-check-publish --no-interaction',
            $hook,
        );
        self::assertStringContainsString(
            "tem_arquivo '^tools/phpcpd/composer\\.(json|lock)$'",
            $hook,
        );
        self::assertStringContainsString(
            'composer validate --working-dir=tools/phpcpd --strict --check-lock --no-check-publish --no-interaction',
            $hook,
        );
    }
}
