<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contrato da instalação manual de phpstan/phpstan-phpunit.
 *
 * O pacote é um phpstan-extension, mas o projeto não instala
 * phpstan/extension-installer. Estes testes executam o mesmo binário com os
 * dois includes explícitos para provar tanto a tipagem de mocks quanto as
 * regras específicas de PHPUnit.
 */
final class PhpStanPhpunitContratoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    public function testManifestoLockEIncludesManuaisMantemCompatibilidade(): void
    {
        $manifesto = self::lerJson('composer.json');
        $requireDev = $manifesto['require-dev'] ?? null;
        self::assertIsArray($requireDev);
        self::assertSame('^2.1', $requireDev['phpstan/phpstan-phpunit'] ?? null);

        $pacote = self::pacoteDoLock('phpstan/phpstan-phpunit');
        $versao = $pacote['version'] ?? null;
        self::assertIsString($versao);
        self::assertMatchesRegularExpression('/^2\.1\.\d+$/', $versao);
        self::assertSame('phpstan-extension', $pacote['type'] ?? null);
        $requisitos = $pacote['require'] ?? null;
        self::assertIsArray($requisitos);
        self::assertSame('^2.3.0', $requisitos['phpstan/phpstan'] ?? null);
        $conflitos = $pacote['conflict'] ?? null;
        self::assertIsArray($conflitos);
        self::assertSame('<7.0', $conflitos['phpunit/phpunit'] ?? null);
        $extra = $pacote['extra'] ?? null;
        self::assertIsArray($extra);
        $phpstanExtra = $extra['phpstan'] ?? null;
        self::assertIsArray($phpstanExtra);
        self::assertSame(
            ['extension.neon', 'rules.neon'],
            $phpstanExtra['includes'] ?? null,
        );

        $configuracao = file_get_contents(self::RAIZ . '/phpstan.neon');
        self::assertIsString($configuracao);
        self::assertStringContainsString(
            '    - vendor/phpstan/phpstan-phpunit/extension.neon',
            $configuracao,
        );
        self::assertStringContainsString(
            '    - vendor/phpstan/phpstan-phpunit/rules.neon',
            $configuracao,
        );
    }

    public function testIncludeManualPreservaTipoDeMock(): void
    {
        $codigo = <<<'PHP'
<?php
declare(strict_types=1);

namespace PhpStanPhpunitFixture;

use PHPUnit\Framework\TestCase;

interface Gateway
{
    public function resposta(): string;
}

final class MockTest extends TestCase
{
    public function testMock(): void
    {
        $mock = $this->createMockForIntersectionOfInterfaces([Gateway::class]);
        $mock->expects($this->once())->method('resposta')->willReturn('ok');
        self::assertSame('ok', $mock->resposta());
    }
}
PHP;

        $resultado = self::executarPhpStan($codigo);
        self::assertSame(0, $resultado['status'], $resultado['stdout'] . $resultado['stderr']);
        self::assertStringNotContainsString('phpunit.mockMethod', $resultado['stdout'] . $resultado['stderr']);
    }

    public function testRulesManualDetectamUsoInadequadoDeAssert(): void
    {
        $codigo = <<<'PHP'
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AssertTest extends TestCase
{
    public function testAssert(): void
    {
        self::assertSame(true, true);
    }
}
PHP;

        $resultado = self::executarPhpStan($codigo);
        self::assertNotSame(0, $resultado['status'], $resultado['stdout'] . $resultado['stderr']);
        self::assertStringContainsString('phpunit.assertTrue', $resultado['stdout'] . $resultado['stderr']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function lerJson(string $relativo): array
    {
        $conteudo = file_get_contents(self::RAIZ . '/' . $relativo);
        self::assertIsString($conteudo);
        $valor = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($valor);

        /** @var array<string, mixed> $valor */
        return $valor;
    }

    /**
     * @return array<string, mixed>
     */
    private static function pacoteDoLock(string $nome): array
    {
        $lock = self::lerJson('composer.lock');
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
    private static function executarPhpStan(string $codigo): array
    {
        $fonteTemporaria = null;
        $configTemporaria = null;
        $fonte = null;
        $configuracao = null;
        $stdout = null;
        $stderr = null;
        $processo = null;

        try {
            $fonteTemporaria = tempnam(sys_get_temp_dir(), 'phpstan-phpunit-source-');
            $configTemporaria = tempnam(sys_get_temp_dir(), 'phpstan-phpunit-config-');
            self::assertIsString($fonteTemporaria);
            self::assertIsString($configTemporaria);
            $fonte = $fonteTemporaria . '.php';
            $configuracao = $configTemporaria . '.neon';
            self::assertTrue(rename($fonteTemporaria, $fonte));
            self::assertTrue(rename($configTemporaria, $configuracao));

            $neon = <<<'NEON'
includes:
    - %s/vendor/phpstan/phpstan-phpunit/extension.neon
    - %s/vendor/phpstan/phpstan-phpunit/rules.neon

parameters:
    level: max
    phpVersion: 80512
    parallel:
        maximumNumberOfProcesses: 1
NEON;

            $stdout = tmpfile();
            $stderr = tmpfile();
            self::assertIsResource($stdout);
            self::assertIsResource($stderr);

            self::assertNotFalse(file_put_contents($fonte, $codigo));
            self::assertNotFalse(file_put_contents(
                $configuracao,
                sprintf($neon, self::RAIZ, self::RAIZ),
            ));

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
                [
                    0 => ['file', 'php://stdin', 'r'],
                    1 => $stdout,
                    2 => $stderr,
                ],
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
            if (is_string($fonteTemporaria)) {
                @unlink($fonteTemporaria);
            }
            if (is_string($configTemporaria)) {
                @unlink($configTemporaria);
            }
            if (is_string($fonte)) {
                @unlink($fonte);
            }
            if (is_string($configuracao)) {
                @unlink($configuracao);
            }
        }
    }
}
