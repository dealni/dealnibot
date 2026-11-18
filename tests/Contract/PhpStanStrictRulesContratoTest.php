<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contrato da instalação manual de phpstan-strict-rules.
 *
 * A análise positiva usa o mesmo `rules.neon` incluído pelo projeto, mas
 * analisa um arquivo temporário. Assim o teste prova que a extensão está
 * realmente ativa sem introduzir violações deliberadas no código do bot.
 */
final class PhpStanStrictRulesContratoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    /** @var list<string> */
    private const OPCOES_STRICT_RULES = [
        'disallowedLooseComparison',
        'booleansInConditions',
        'booleansInLoopConditions',
        'uselessCast',
        'requireParentConstructorCall',
        'disallowedBacktick',
        'disallowedEmpty',
        'disallowedImplicitArrayCreation',
        'disallowedShortTernary',
        'overwriteVariablesWithLoop',
        'closureUsesThis',
        'matchingInheritedMethodNames',
        'numericOperandsInArithmeticOperators',
        'strictFunctionCalls',
        'dynamicCallOnStaticMethod',
        'switchConditionsMatchingType',
        'noVariableVariables',
        'strictArrayFilter',
        'illegalConstructorMethodCall',
    ];

    public function testManifestoLockIncludeEOpcoesOficiaisPermanecemCompatíveis(): void
    {
        $manifesto = self::lerJson(self::RAIZ . '/composer.json');
        $requireDev = $manifesto['require-dev'] ?? null;
        self::assertIsArray($requireDev);
        self::assertSame('^2.1', $requireDev['phpstan/phpstan-strict-rules'] ?? null);

        $pacote = self::pacoteDoLock('phpstan/phpstan-strict-rules');
        $versao = $pacote['version'] ?? null;
        self::assertIsString($versao);
        self::assertMatchesRegularExpression('/^2\.1\.\d+$/', $versao);
        self::assertSame('phpstan-extension', $pacote['type'] ?? null);

        $dependencias = $pacote['require'] ?? null;
        self::assertIsArray($dependencias);
        self::assertSame('^2.3', $dependencias['phpstan/phpstan'] ?? null);

        $configuracao = file_get_contents(self::RAIZ . '/phpstan.neon');
        self::assertIsString($configuracao);
        self::assertStringContainsString(
            '    - vendor/phpstan/phpstan-strict-rules/rules.neon',
            $configuracao,
        );

        $regras = file_get_contents(self::RAIZ . '/vendor/phpstan/phpstan-strict-rules/rules.neon');
        self::assertIsString($regras);
        self::assertStringContainsString("\t\tallRules: true", $regras);
        foreach (self::OPCOES_STRICT_RULES as $opcao) {
            self::assertStringContainsString("\t\t{$opcao}:", $regras, "Opção ausente: {$opcao}");
        }
    }

    public function testIncludeManualDetectaViolacoesStrictRules(): void
    {
        $fonteTemporaria = null;
        $configuracaoTemporaria = null;
        $fonte = null;
        $configuracao = null;

        try {
            $fonteTemporaria = tempnam(sys_get_temp_dir(), 'phpstan-strict-source-');
            $configuracaoTemporaria = tempnam(sys_get_temp_dir(), 'phpstan-strict-config-');
            self::assertIsString($fonteTemporaria);
            self::assertIsString($configuracaoTemporaria);
            $fonte = $fonteTemporaria . '.php';
            $configuracao = $configuracaoTemporaria . '.neon';
            self::assertTrue(rename($fonteTemporaria, $fonte));
            self::assertTrue(rename($configuracaoTemporaria, $configuracao));

            $codigo = <<<'PHP'
<?php
declare(strict_types=1);

function violaRegrasStrictRules(mixed $valor): bool
{
    if ($valor) {
        return true;
    }

    if (empty($valor)) {
        return false;
    }

    return in_array($valor, ['ok']);
}
PHP;
            $neon = <<<'NEON'
includes:
    - %s/vendor/phpstan/phpstan-strict-rules/rules.neon

parameters:
    level: 0
NEON;

            self::assertNotFalse(file_put_contents($fonte, $codigo));
            self::assertNotFalse(file_put_contents($configuracao, sprintf($neon, self::RAIZ)));

            $resultado = self::executarPhpStan($configuracao, $fonte);
            self::assertNotSame(0, $resultado['status'], $resultado['stderr']);
            $saida = $resultado['stdout'] . $resultado['stderr'];
            self::assertStringContainsString('if.condNotBoolean', $saida);
            self::assertStringContainsString('empty.notAllowed', $saida);
            self::assertStringContainsString('function.strict', $saida);
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
                    '--no-progress',
                    '--debug',
                    '--xdebug',
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
