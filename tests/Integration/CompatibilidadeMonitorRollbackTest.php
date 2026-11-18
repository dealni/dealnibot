<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class CompatibilidadeMonitorRollbackTest extends TestCase
{
    private const BOT = 'dealnibot';
    private string $diretorio;
    private string $antigo;
    private string $compativel;

    protected function setUp(): void
    {
        $this->diretorio = sys_get_temp_dir() . '/monitor-rollback-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->diretorio, 0700));
        $this->rodar(['git', 'init', '--quiet']);
        $this->rodar(['git', 'config', 'user.name', 'Fixture']);
        $this->rodar(['git', 'config', 'user.email', 'fixture@example.test']);
        self::assertNotFalse(file_put_contents($this->diretorio . '/antigo', 'fixture'));
        $this->rodar(['git', 'add', '.']);
        $this->rodar(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'fixture antiga']);
        $this->antigo = trim($this->rodar(['git', 'rev-parse', 'HEAD'])['saida']);
        foreach (['scripts/operacao/verificar_snapshot_recente.php', 'inclusos/funcoes_snapshot_recencia.php',
            'scripts/deploy/deploy.sh', 'scripts/deploy/publicar.sh', 'scripts/deploy/rollback.sh'] as $arquivo) {
            $pasta = dirname($this->diretorio . '/' . $arquivo);
            if (!is_dir($pasta)) {
                self::assertTrue(mkdir($pasta, 0700, true));
            }
            self::assertNotFalse(file_put_contents($this->diretorio . '/' . $arquivo, self::BOT . '-snapshot-recencia.timer'));
        }
        $this->rodar(['git', 'add', '.']);
        $this->rodar(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'fixture compativel']);
        $this->compativel = trim($this->rodar(['git', 'rev-parse', 'HEAD'])['saida']);
    }

    protected function tearDown(): void
    {
        $itens = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->diretorio, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($itens as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($this->diretorio);
    }

    public function testHostSemTimerPodeVoltarAoAlvoAntigo(): void
    {
        self::assertSame(0, $this->validar('not-found', $this->antigo)['codigo']);
    }

    public function testTimerInstaladoAceitaDestinoCompativelERecusaAntigoAntesDaParada(): void
    {
        self::assertSame(0, $this->validar('loaded', $this->compativel)['codigo']);
        $recusa = $this->validar('loaded', $this->antigo);
        self::assertSame(1, $recusa['codigo']);
        self::assertStringContainsString('Rollback recusado antes da parada', $recusa['saida']);
        self::assertStringContainsString('nenhum serviço foi parado', $recusa['saida']);
    }

    public function testFalhaAoInspecionarTimerRecusaSemAcaoSystemd(): void
    {
        self::assertSame(1, $this->validar('erro', $this->compativel)['codigo']);
    }

    /** @return array{codigo: int, saida: string} */
    private function validar(string $estado, string $alvo): array
    {
        $script = <<<'SH'
set -euo pipefail
source "$1/scripts/deploy/publicacao_operacao.sh"
fixture_bot="$2"
systemctl() {
    if [[ "$*" != "show -p LoadState --value $fixture_bot-snapshot-recencia.timer" ]]; then
        echo 'Mutação systemd inesperada' >&2
        exit 99
    fi
    [[ "$FAKE_LOAD_STATE" != erro ]] || return 1
    printf '%s\n' "$FAKE_LOAD_STATE"
}
publicacao_validar_monitor_snapshot_no_destino "$2" "$3"
SH;
        return $this->rodar(['/bin/bash', '-c', $script, 'fixture', dirname(__DIR__, 2), self::BOT, $alvo], $estado);
    }

    /** @param list<string> $comando
     * @return array{codigo: int, saida: string} */
    private function rodar(array $comando, string $estado = ''): array
    {
        $ambiente = getenv();
        $ambiente['FAKE_LOAD_STATE'] = $estado;
        $pipes = [];
        $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->diretorio, $ambiente);
        self::assertIsResource($processo);
        $saida = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $erro = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        self::assertIsString($saida);
        self::assertIsString($erro);
        $saida .= $erro;
        return ['codigo' => proc_close($processo), 'saida' => $saida];
    }
}
