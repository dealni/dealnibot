<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contrato da instalação manual de phpstan-deprecation-rules.
 *
 * O teste positivo executa o mesmo binário e o mesmo `rules.neon` que o gate
 * usa, mas analisa um arquivo temporário. Assim a API marcada como obsoleta não
 * entra no escopo do PHPStan do projeto e não vira uma exceção permanente.
 */
final class PhpStanDeprecationRulesContratoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    public function testManifestoLockEIncludeManualMantemExtensaoCompatível(): void
    {
        $manifesto = self::lerJson(self::RAIZ . '/composer.json');
        $requireDev = $manifesto['require-dev'] ?? null;
        self::assertIsArray($requireDev);
        self::assertSame('^2.0', $requireDev['phpstan/phpstan-deprecation-rules'] ?? null);

        $pacote = self::pacoteDoLock('phpstan/phpstan-deprecation-rules');
        $versao = $pacote['version'] ?? null;
        self::assertIsString($versao);
        self::assertMatchesRegularExpression('/^2\.\d+\.\d+$/', $versao);
        self::assertTrue(version_compare($versao, '2.0.5', '>='));
        self::assertSame('phpstan-extension', $pacote['type'] ?? null);
        $require = $pacote['require'] ?? null;
        self::assertIsArray($require);
        self::assertSame('^2.1.39', $require['phpstan/phpstan'] ?? null);

        $configuracao = file_get_contents(self::RAIZ . '/phpstan.neon');
        self::assertIsString($configuracao);
        self::assertStringContainsString(
            '    - vendor/phpstan/phpstan-deprecation-rules/rules.neon',
            $configuracao,
        );
        self::assertStringContainsString('    phpVersion: 80512', $configuracao);
    }

    public function testIncludeManualDetectaMetodoMarcadoComoDeprecated(): void
    {
        $fonteTemporaria = null;
        $configuracaoTemporaria = null;
        $fonte = null;
        $configuracao = null;

        try {
            $fonteTemporaria = tempnam(sys_get_temp_dir(), 'phpstan-deprecation-source-');
            $configuracaoTemporaria = tempnam(sys_get_temp_dir(), 'phpstan-deprecation-config-');
            self::assertIsString($fonteTemporaria);
            self::assertIsString($configuracaoTemporaria);
            $fonte = $fonteTemporaria . '.php';
            $configuracao = $configuracaoTemporaria . '.neon';
            self::assertTrue(rename($fonteTemporaria, $fonte));
            self::assertTrue(rename($configuracaoTemporaria, $configuracao));

            $codigo = <<<'PHP'
<?php
declare(strict_types=1);

final class ApiComMetodoObsoleto
{
    /** @deprecated use metodoAtual() */
    public function metodoObsoleto(): void
    {
    }
}

function chamaApiObsoleta(ApiComMetodoObsoleto $api): void
{
    $api->metodoObsoleto();
}

function usaConstanteObsoleta(): mixed
{
    return FILTER_SANITIZE_STRING;
}
PHP;
            $neon = <<<'NEON'
includes:
    - %s/vendor/phpstan/phpstan-deprecation-rules/rules.neon

parameters:
    level: 0
    phpVersion: 80512
    parallel:
        maximumNumberOfProcesses: 1
NEON;

            self::assertNotFalse(file_put_contents($fonte, $codigo));
            self::assertNotFalse(file_put_contents($configuracao, sprintf($neon, self::RAIZ)));

            $resultado = self::executarPhpStan($configuracao, $fonte);
            self::assertNotSame(0, $resultado['status'], $resultado['stderr']);
            self::assertStringContainsString('method.deprecated', $resultado['stdout'] . $resultado['stderr']);
            self::assertStringContainsString('constant.deprecated', $resultado['stdout'] . $resultado['stderr']);
        } finally {
            if (is_string($fonteTemporaria)) {
                @unlink($fonteTemporaria);
            }
            if (is_string($configuracaoTemporaria)) {
                @unlink($configuracaoTemporaria);
            }
            if (is_string($fonte)) {
                @unlink($fonte);
            }
            if (is_string($configuracao)) {
                @unlink($configuracao);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function lerJson(string $caminho): array
    {
        $conteudo = file_get_contents($caminho);
        self::assertIsString($conteudo);
        $dados = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($dados);

        /** @var array<string, mixed> $dados */
        return $dados;
    }

    /**
     * @return array<string, mixed>
     */
    private static function pacoteDoLock(string $nome): array
    {
        $lock = self::lerJson(self::RAIZ . '/composer.lock');
        $pacotes = $lock['packages-dev'] ?? null;
        self::assertIsArray($pacotes);

        foreach ($pacotes as $pacote) {
            if (!is_array($pacote) || ($pacote['name'] ?? null) !== $nome) {
                continue;
            }

            /** @var array<string, mixed> $pacote */
            return $pacote;
        }

        self::fail("{$nome} não está em composer.lock.");
    }

    /**
     * @return array{status: int, stdout: string, stderr: string}
     */
    private static function executarPhpStan(string $configuracao, string $fonte): array
    {
        $stdout = null;
        $stderr = null;
        $processo = null;

        try {
            $stdout = tmpfile();
            $stderr = tmpfile();
            self::assertIsResource($stdout);
            self::assertIsResource($stderr);

            $processo = proc_open(
                [
                    PHP_BINARY,
                    self::RAIZ . '/vendor/bin/phpstan',
                    'analyse',
                    '--debug',
                    '--xdebug',
                    '--no-progress',
                    '--error-format=json',
                    '--configuration=' . $configuracao,
                    $fonte,
                ],
                [0 => ['file', 'php://stdin', 'r'], 1 => $stdout, 2 => $stderr],
                $canos,
                self::RAIZ,
            );
            self::assertIsResource($processo);

            $status = proc_close($processo);
            $processo = null;
            rewind($stdout);
            rewind($stderr);
            $saida = stream_get_contents($stdout);
            $saidaDeErro = stream_get_contents($stderr);
            self::assertIsString($saida);
            self::assertIsString($saidaDeErro);

            return ['status' => $status, 'stdout' => $saida, 'stderr' => $saidaDeErro];
        } finally {
            if (is_resource($processo)) {
                @proc_terminate($processo);
                @proc_close($processo);
            }
            if (is_resource($stdout)) {
                fclose($stdout);
            }
            if (is_resource($stderr)) {
                fclose($stderr);
            }
        }
    }
}
