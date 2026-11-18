<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

use function Quality\dependenciasComLicencaReprovada;
use function Quality\escopoEncolheu;
use function Quality\executarAuditoriaComRetry;
use function Quality\executarProcesso;
use function Quality\problemasNaEstruturaDosWorkflows;
use function Quality\problemasNosLinks;
use function Quality\validarCarregamentoDeProducao;
use function Quality\validarWorkflows;

require_once __DIR__ . '/../../scripts/quality/check_doc_links.php';
require_once __DIR__ . '/../../scripts/quality/check_licenses.php';
require_once __DIR__ . '/../../scripts/quality/check_production_config.php';
require_once __DIR__ . '/../../scripts/quality/check_workflows.php';
require_once __DIR__ . '/../../scripts/quality/composer_audit_retry.php';

final class QualityGatesTest extends TestCase
{
    public function testScriptsPodemSerImportadosSemExecutarOsPortoes(): void
    {
        self::assertTrue(function_exists('Quality\\mainAudit'));
        self::assertTrue(function_exists('Quality\\mainConfiguracaoProducao'));
        self::assertTrue(function_exists('Quality\\mainLicencas'));
        self::assertTrue(function_exists('Quality\\mainLinks'));
        self::assertTrue(function_exists('Quality\\mainWorkflows'));
    }

    public function testShellcheckDoGateEHookUsamConfiguracaoDeterministicaEDialetoDoShebang(): void
    {
        $raiz = dirname(__DIR__, 2);
        $gate = file_get_contents($raiz . '/scripts/quality/check_shell.sh');
        $hook = file_get_contents($raiz . '/scripts/quality/check_staged.sh');
        self::assertIsString($gate);
        self::assertIsString($hook);

        self::assertStringNotContainsString(
            'scripts+=("scripts/quality/check_staged.sh")',
            $gate,
        );
        self::assertStringContainsString(
            'env -u SHELLCHECK_OPTS shellcheck --norc --severity=style --color=never',
            $gate,
        );
        self::assertStringContainsString(
            'env -u SHELLCHECK_OPTS shellcheck --norc --severity=style --color=never "${scripts[@]}"',
            $hook,
        );
        self::assertStringContainsString('git show ":${script}" -- > "$temporario/arvore/$script"', $hook);
        self::assertStringContainsString('cd "$temporario/arvore"', $hook);
        self::assertStringNotContainsString('shellcheck -s bash -', $hook);
    }

    public function testGateDeLinksNaoAceitaExcludesGlobaisDoGit(): void
    {
        $fonte = file_get_contents(dirname(__DIR__, 2) . '/scripts/quality/check_doc_links.php');
        self::assertIsString($fonte);
        self::assertStringContainsString('--exclude-per-directory=.gitignore', $fonte);
        self::assertStringNotContainsString('--exclude-standard', $fonte);
    }

    public function testProcessoColetaStdoutEStderrGrandesSemDeadlock(): void
    {
        $resultado = executarProcesso([
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, str_repeat("o", 262144)); fwrite(STDERR, str_repeat("e", 262144));',
        ]);

        self::assertSame(0, $resultado['status']);
        self::assertSame(262144, strlen($resultado['stdout']));
        self::assertSame(262144, strlen($resultado['stderr']));
    }

    public function testAuditoriaRepeteSomenteFalhaTransitoria(): void
    {
        $tentativas = 0;
        $esperas = [];
        $status = executarAuditoriaComRetry(
            'composer',
            [0, 1, 2],
            static function (array $comando) use (&$tentativas): array {
                self::assertSame('audit', $comando[1]);
                ++$tentativas;

                return $tentativas < 3
                    ? ['status' => 1, 'stdout' => '', 'stderr' => 'curl error 28: timed out']
                    : ['status' => 0, 'stdout' => '{}', 'stderr' => ''];
            },
            static function (int $atraso) use (&$esperas): void {
                $esperas[] = $atraso;
            },
            static function (string $_canal, string $_texto): void {
            },
        );

        self::assertSame(0, $status);
        self::assertSame(3, $tentativas);
        self::assertSame([1, 2], $esperas);
    }

    public function testAuditoriaNaoMascaraVulnerabilidadeComoFalhaDeRede(): void
    {
        $tentativas = 0;
        $status = executarAuditoriaComRetry(
            'composer',
            [0, 1, 2],
            static function () use (&$tentativas): array {
                ++$tentativas;

                return [
                    'status' => 1,
                    'stdout' => 'Found 1 security vulnerability advisory affecting 1 package.',
                    'stderr' => '',
                ];
            },
            static function (int $_atraso): void {
            },
            static function (string $_canal, string $_texto): void {
            },
        );

        self::assertSame(1, $status);
        self::assertSame(1, $tentativas);
    }

    public function testPoliticaDeLicencasAceitaPermissivasEExcecaoRevisada(): void
    {
        $relatorio = [
            'dependencies' => [
                'vendor/permissiva' => ['version' => '1.0.0', 'license' => ['MIT']],
                'tecnickcom/tcpdf' => ['version' => '7.0.0', 'license' => ['LGPL-3.0-or-later']],
                'vendor/reprovada' => ['version' => '2.0.0', 'license' => ['GPL-3.0-only']],
                'vendor/sem-licenca' => ['version' => '3.0.0', 'license' => []],
            ],
        ];

        self::assertSame(
            [
                'vendor/reprovada@2.0.0 (GPL-3.0-only)',
                'vendor/sem-licenca@3.0.0 (sem licença declarada)',
            ],
            dependenciasComLicencaReprovada($relatorio),
        );
    }

    public function testPoliticaDeLicencasReprovaRelatorioMalformado(): void
    {
        $this->expectException(UnexpectedValueException::class);
        dependenciasComLicencaReprovada([]);
    }

    public function testGateDeProducaoExecutaOCarregadorEPropagaFalha(): void
    {
        $carregou = false;
        validarCarregamentoDeProducao(static function () use (&$carregou): void {
            $carregou = true;
        });
        self::assertTrue($carregou);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('segredo ausente');
        validarCarregamentoDeProducao(static function (): never {
            throw new RuntimeException('segredo ausente');
        });
    }

    public function testEscopoQueEncolheEhReprovadoMesmoComOPlacarMaior(): void
    {
        // Omitir 55 itens de um inventário de 2343 reduz o total para 2288.
        // Essa queda de 2,3% não cabe na folga de 1%.
        self::assertTrue(escopoEncolheu(2288, 2343, 0.01));
        self::assertFalse(escopoEncolheu(2340, 2343, 0.01));
        self::assertFalse(escopoEncolheu(2400, 2343, 0.01));
        self::assertTrue(escopoEncolheu(2342, 2343));
    }

    public function testFolgaDeEscopoForaDaFaixaEhRecusada(): void
    {
        $this->expectException(UnexpectedValueException::class);
        escopoEncolheu(100, 100, 1.5);
    }

    public function testActionlintExigeAsDuasFerramentasEExecutaOGate(): void
    {
        $comandos = [];
        $mensagem = validarWorkflows(
            static function (array $comando) use (&$comandos): array {
                $comandos[] = $comando;

                return ['status' => 0, 'stdout' => '', 'stderr' => ''];
            },
            true,
        );

        self::assertSame('Workflows válidos com actionlint, shellcheck e estrutura no contrato.', $mensagem);
        self::assertSame(
            [
                ['shellcheck', '--version'],
                ['actionlint', '-version'],
                ['actionlint', '-no-color', '-shellcheck=shellcheck --norc'],
            ],
            $comandos,
        );
    }

    public function testEstruturaDosWorkflowsSegueContratoDaFrota(): void
    {
        $workflows = [
            'ci.yml' => "name: ci\non:\n  push:\n  pull_request:\n  workflow_dispatch:\njobs:\n  commit-messages:\n  tests:\n  quality:\n  workflows:\n  reports:\n",
            'security.yml' => "name: security\non:\n  push:\n  pull_request:\n  schedule:\n  workflow_dispatch:\njobs:\n  dependencies:\n  gitleaks:\n  semgrep:\n",
        ];

        self::assertSame([], problemasNaEstruturaDosWorkflows($workflows));

        $workflowsSemVarreduraSemanal = $workflows;
        $workflowsSemVarreduraSemanal['security.yml'] = str_replace(
            "  schedule:\n",
            '',
            $workflowsSemVarreduraSemanal['security.yml'],
        );
        self::assertContains(
            'security.yml: não dispara em schedule.',
            problemasNaEstruturaDosWorkflows($workflowsSemVarreduraSemanal),
        );

        $workflows['ci.yml'] = str_replace("  reports:\n", '', $workflows['ci.yml']);
        self::assertContains('ci.yml: falta o job reports.', problemasNaEstruturaDosWorkflows($workflows));
        $workflows['ci.yml'] .= "  reports:\n";

        unset($workflows['security.yml']);
        self::assertSame(['security.yml não existe.'], problemasNaEstruturaDosWorkflows($workflows));
    }

    public function testActionlintReprovaFerramentaAusenteESaidaDeErro(): void
    {
        try {
            validarWorkflows(
                static fn (array $comando): array => [
                    'status' => $comando[0] === 'shellcheck' ? 127 : 0,
                    'stdout' => '',
                    'stderr' => '',
                ],
                true,
            );
            self::fail('O gate deveria reprovar shellcheck ausente.');
        } catch (RuntimeException $erro) {
            self::assertStringContainsString('shellcheck ausente', $erro->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('arquivo.yml:1: erro');
        validarWorkflows(
            static fn (array $comando): array => [
                'status' => ($comando[1] ?? '') === '-no-color' ? 1 : 0,
                'stdout' => '',
                'stderr' => ($comando[1] ?? '') === '-no-color' ? 'arquivo.yml:1: erro' : '',
            ],
            true,
        );
    }

    public function testLinksLocaisValidosEInexistentesSaoDistinguidos(): void
    {
        $raiz = sys_get_temp_dir() . '/links_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($raiz . '/docs', 0700, true));
        self::assertNotFalse(file_put_contents($raiz . '/README.md', '[guia](docs/guia.md)'));
        self::assertNotFalse(file_put_contents($raiz . '/docs/guia.md', '[ausente](nao-existe.md)'));

        try {
            self::assertSame([], problemasNosLinks($raiz, ['README.md']));
            self::assertSame(
                ['docs/guia.md: link local inexistente: nao-existe.md'],
                problemasNosLinks($raiz, ['docs/guia.md']),
            );
        } finally {
            unlink($raiz . '/README.md');
            unlink($raiz . '/docs/guia.md');
            rmdir($raiz . '/docs');
            rmdir($raiz);
        }
    }
}
