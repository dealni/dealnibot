<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

use function array_fill;
use function file_get_contents;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function substr_count;

/** Contrato dos pins e das ferramentas instaladas pela action de PHP. */
final class SetupPhpContratoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';
    private const ACTION_SHA = 'eb7c497e18156a6bbabfef1d3a82760b9eda3962';
    private const ACTION_TAG = '2.40.0';
    private const COMPOSER_TOOL = 'composer:2.10.3';

    /**
     * Cada job declara explicitamente se realmente precisa do Composer.
     * Assim, uma atualização da action não volta a instalar uma ferramenta
     * desnecessária nos scanners, nem deixa um job Composer dependente da
     * versão estável que a action publicar no futuro.
     */
    public function testUsosEEntradasMantemContratoDeVersaoEDeFerramentas(): void
    {
        $jobs = [
            '.github/workflows/ci.yml' => [
                'postgresql' => self::COMPOSER_TOOL,
                'tests' => self::COMPOSER_TOOL,
                'quality' => self::COMPOSER_TOOL,
                'workflows' => self::COMPOSER_TOOL,
                'reports' => self::COMPOSER_TOOL,
            ],
            '.github/workflows/security.yml' => [
                'dependencies' => self::COMPOSER_TOOL,
                'gitleaks' => 'none',
                'semgrep' => 'none',
            ],
        ];
        foreach ($jobs as $arquivo => $jobsDoWorkflow) {
            $workflow = $this->lerWorkflow($arquivo);
            $usos = preg_match_all(
                '/uses:\s+shivammathur\/setup-php@([0-9a-f]{40})\s+#\s+([^\s]+)/u',
                $workflow,
                $matches,
            );
            $quantidade = count($jobsDoWorkflow);
            self::assertSame($quantidade, $usos, $arquivo);
            self::assertSame(array_fill(0, $quantidade, self::ACTION_SHA), $matches[1], $arquivo);
            self::assertSame(array_fill(0, $quantidade, self::ACTION_TAG), $matches[2], $arquivo);
            foreach ($jobsDoWorkflow as $job => $tool) {
                $setup = $this->passoSetupPhp($workflow, $job, $arquivo);
                self::assertSame(1, substr_count($setup, 'tools: ' . $tool), $arquivo . ':' . $job);
                self::assertStringContainsString('php-version:', $setup, $arquivo . ':' . $job);
            }
        }

    }

    public function testServicosLocaisPreservamRedisEValidamCgiDoSetupPhp(): void
    {
        foreach (['.github/workflows/ci.yml' => 4] as $arquivo => $quantidade) {
            $workflow = $this->lerWorkflow($arquivo);
            self::assertStringNotContainsString('php8.5-cgi', $workflow, $arquivo);
            self::assertSame($quantidade, substr_count($workflow, 'sudo apt-get install -y redis-server'), $arquivo);
            self::assertSame($quantidade, substr_count($workflow, 'bash scripts/quality/check_php_cgi.sh'), $arquivo);
        }
    }

    public function testPreflightCgiAceitaVersaoCorretaERecusaAusenciaDivergenciaOuFalha(): void
    {
        $diretorio = sys_get_temp_dir() . '/php-cgi-contrato-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($diretorio, 0700));
        $php = $diretorio . '/php';
        $cgi = $diretorio . '/php-cgi';
        $phpFake = <<<'SH'
            #!/bin/sh
            case "$2" in
              *PHP_VERSION*) printf '8.5.12' ;;
              *) printf '%s/php-cgi' "$CGI_FIXTURE_PATH" ;;
            esac
            SH;
        $cgiFake = <<<'SH'
            #!/bin/sh
            printf '%s\n' "$CGI_FIXTURE_BANNER"
            exit "$CGI_FIXTURE_STATUS"
            SH;
        try {
            self::assertNotFalse(file_put_contents($php, $phpFake . "\n"));
            self::assertTrue(chmod($php, 0700));
            foreach ([
                ['presente' => true, 'banner' => 'PHP 8.5.12 (cgi-fcgi) (built: test)', 'codigo' => 0, 'esperado' => 0],
                ['presente' => false, 'banner' => '', 'codigo' => 0, 'esperado' => 1],
                ['presente' => true, 'banner' => 'PHP 8.4.12 (cgi-fcgi)', 'codigo' => 0, 'esperado' => 1],
                ['presente' => true, 'banner' => 'PHP 8.5.12 (cli)', 'codigo' => 0, 'esperado' => 1],
                ['presente' => true, 'banner' => 'PHP 8.5.12 (cgi-fcgi)', 'codigo' => 2, 'esperado' => 2],
            ] as $cenario) {
                if ($cenario['presente']) {
                    self::assertNotFalse(file_put_contents($cgi, $cgiFake . "\n"));
                    self::assertTrue(chmod($cgi, 0700));
                } elseif (is_file($cgi)) {
                    self::assertTrue(unlink($cgi));
                }
                $ambiente = getenv();
                $ambiente['PATH'] = $diretorio . ':' . ($ambiente['PATH'] ?? '/usr/bin:/bin');
                $ambiente['CGI_FIXTURE_PATH'] = $diretorio;
                $ambiente['CGI_FIXTURE_BANNER'] = $cenario['banner'];
                $ambiente['CGI_FIXTURE_STATUS'] = (string) $cenario['codigo'];
                $processo = proc_open(
                    ['bash', self::RAIZ . '/scripts/quality/check_php_cgi.sh'],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    self::RAIZ,
                    $ambiente,
                );
                self::assertIsResource($processo);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame($cenario['esperado'], proc_close($processo), $stderr . $stdout);
            }
        } finally {
            foreach ([$php, $cgi] as $arquivo) {
                if (is_file($arquivo)) {
                    unlink($arquivo);
                }
            }
            rmdir($diretorio);
        }
    }

    public function testIsolamentoDeRedePreservaUsuarioDoRunner(): void
    {
        foreach (['.github/workflows/ci.yml' => 1] as $arquivo => $quantidade) {
            $workflow = $this->lerWorkflow($arquivo);
            self::assertSame($quantidade, substr_count($workflow, 'sudo /usr/bin/unshare --net -- /usr/bin/setpriv --reuid="$(id -u)" --regid="$(id -g)" --clear-groups --'), $arquivo);
            self::assertSame($quantidade, substr_count($workflow, 'sudo /usr/bin/unshare --net --'), $arquivo);
            self::assertStringNotContainsString('unshare --net --setuid', $workflow, $arquivo);
            self::assertSame($quantidade, substr_count($workflow, 'bash scripts/quality/check_ci_namespace.sh'), $arquivo);
            foreach (['CHATBOTS_CI_EXPECTED_UID=', 'CHATBOTS_CI_EXPECTED_GID=', 'CHATBOTS_CI_PARENT_NETNS='] as $variavel) {
                self::assertSame($quantidade, substr_count($workflow, $variavel), $arquivo);
            }
            self::assertSame($quantidade, substr_count($workflow, '/usr/bin/env HOME="$HOME" USER="$(id -un)" LOGNAME="$(id -un)"'), $arquivo);
            self::assertStringNotContainsString(';user=', $workflow, $arquivo);
            self::assertStringNotContainsString(';password=', $workflow, $arquivo);
        }

        $workflow = $this->lerWorkflow('.github/workflows/ci.yml');
        $inicio = strpos($workflow, "  tests:\n");
        $fim = strpos($workflow, "  quality:\n");
        self::assertIsInt($inicio);
        self::assertIsInt($fim);
        $testes = substr($workflow, $inicio, $fim - $inicio);
        self::assertStringContainsString('coverage: none', $testes);
    }

    public function testPreflightDaNamespaceRecusaUidRootAntesDeExecutarComando(): void
    {
        $ambiente = getenv();
        $ambiente['CHATBOTS_CI_EXPECTED_UID'] = '0';
        $ambiente['CHATBOTS_CI_EXPECTED_GID'] = '0';
        $ambiente['CHATBOTS_CI_PARENT_NETNS'] = 'net:[1]';
        $processo = proc_open(
            ['bash', self::RAIZ . '/scripts/quality/check_ci_namespace.sh', PHP_BINARY, '-r', 'echo "COMANDO_EXECUTADO";'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::RAIZ,
            $ambiente,
        );
        self::assertIsResource($processo);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(0, proc_close($processo), $stderr . $stdout);
        self::assertStringNotContainsString('COMANDO_EXECUTADO', $stdout);
    }

    private function lerWorkflow(string $arquivo): string
    {
        $conteudo = file_get_contents(self::RAIZ . '/' . $arquivo);
        self::assertIsString($conteudo);

        return $conteudo;
    }

    private function passoSetupPhp(string $workflow, string $job, string $arquivo): string
    {
        $encontrado = preg_match(
            '/^  ' . preg_quote($job, '/') . ':\R(?<job>.*?)(?=^  [a-z0-9_-]+:|\z)/msu',
            $workflow,
            $jobMatches,
        );
        self::assertSame(1, $encontrado, $arquivo . ': job não encontrado: ' . $job);

        $encontrado = preg_match(
            '/^      - name: Setup PHP\R(?<step>.*?)(?=^      - name:|\z)/msu',
            $jobMatches['job'],
            $stepMatches,
        );
        self::assertSame(1, $encontrado, $arquivo . ':' . $job . ': Setup PHP não encontrado');

        return $stepMatches['step'];
    }
}
