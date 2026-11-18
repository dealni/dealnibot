<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class MonitorSaudeTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    private const BOT = 'dealnibot';
    private const PREFIXO = 'DEALNI';
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    private string $temporario;
    private string $bin;

    protected function setUp(): void
    {
        $this->temporario = sys_get_temp_dir() . '/chatbots-monitor-' . bin2hex(random_bytes(8));
        $this->bin = $this->temporario . '/bin';
        self::assertTrue(mkdir($this->bin, 0700, true));
        $this->executavel('curl', <<<'SH'
#!/bin/sh
saida=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output)
            saida="$2"
            shift 2
            ;;
        *)
            shift
            ;;
    esac
done
status="${FAKE_HTTP_STATUS:-500}"
if [ "$status" = '200' ] && [ -n "$saida" ]; then
    printf '{"status":"ok","service":"%s","version":"%s"}' \
        "${FAKE_SERVICE:-servico-do-teste}" "${FAKE_VERSION:-desconhecida}" > "$saida"
fi
printf '%s' "$status"
exit "${FAKE_CURL_EXIT:-0}"
SH);
        $this->executavel('date', "#!/bin/sh\nprintf '%s\\n' \"\$FAKE_NOW\"\n");
    }

    protected function tearDown(): void
    {
        $achados = glob($this->bin . '/*');
        $arquivos = $achados === false ? [] : $achados;
        foreach ($arquivos as $arquivo) {
            unlink($arquivo);
        }
        $achados = glob($this->temporario . '/*');
        $arquivos = $achados === false ? [] : $achados;
        foreach ($arquivos as $arquivo) {
            if (is_file($arquivo) || is_link($arquivo)) {
                unlink($arquivo);
            }
        }
        @unlink($this->temporario . '/estado/monitor-saude.estado');
        @rmdir($this->temporario . '/estado');
        @rmdir($this->bin);
        @rmdir($this->temporario);
    }

    public function testFalhaContinuaPreservaRelogioAteOProximoAlerta(): void
    {
        $primeira = $this->executar(100);
        self::assertSame(1, $primeira['codigo']);
        self::assertStringContainsString('ALERTA ' . self::BOT, $primeira['erro']);
        self::assertSame("falha 100\n", file_get_contents($this->temporario . '/estado/monitor-saude.estado'));

        $segunda = $this->executar(200);
        self::assertSame(1, $segunda['codigo']);
        self::assertStringNotContainsString('ALERTA ' . self::BOT, $segunda['erro']);
        self::assertSame("falha 100\n", file_get_contents($this->temporario . '/estado/monitor-saude.estado'));

        $terceira = $this->executar(3700);
        self::assertSame(1, $terceira['codigo']);
        self::assertStringContainsString('ALERTA ' . self::BOT, $terceira['erro']);
        self::assertSame("falha 3700\n", file_get_contents($this->temporario . '/estado/monitor-saude.estado'));
    }

    public function testRecuperacaoDepoisDeFalhaEmiteAvisoELimpaEstado(): void
    {
        $falha = $this->executar(100);
        self::assertSame(1, $falha['codigo']);

        $recuperacao = $this->executar(200, 200);
        self::assertSame(0, $recuperacao['codigo']);
        self::assertStringContainsString('RECUPERADO ' . self::BOT, $recuperacao['erro']);
        self::assertStringNotContainsString('ALERTA ' . self::BOT, $recuperacao['erro']);
        self::assertSame("ok 200\n", file_get_contents($this->temporario . '/estado/monitor-saude.estado'));
    }

    public function testVersaoAceitaTagOuCommitCurtoDoCarimbo(): void
    {
        $tag = $this->executar(
            100,
            200,
            ['tag' => 'v1.2.3', 'commit' => str_repeat('a', 40), 'commit_curto' => 'aaaaaaa'],
            'v1.2.3',
        );
        self::assertSame(0, $tag['codigo']);

        $commit = $this->executar(
            200,
            200,
            ['tag' => '', 'commit' => str_repeat('b', 40), 'commit_curto' => 'bbbbbbbb'],
            'bbbbbbbb',
        );
        self::assertSame(0, $commit['codigo']);
    }

    public function testSondaReprovaServicoDiferenteMesmoComStatusOk(): void
    {
        $resultado = $this->executar(100, 200, null, 'desconhecida', 'outro-servico');

        self::assertSame(1, $resultado['codigo']);
        self::assertStringContainsString('service=' . self::BOT, $resultado['erro']);
    }

    public function testTransporteFalhoReprovaMesmoComHttp200EJsonCompleto(): void
    {
        $resultado = $this->executar(100, 200, codigoCurl: 28);

        self::assertSame(1, $resultado['codigo']);
        self::assertStringContainsString('/health falhou no transporte (curl 28, HTTP 200)', $resultado['erro']);
        self::assertStringContainsString('/ready falhou no transporte (curl 28, HTTP 200)', $resultado['erro']);
        self::assertSame("falha 100\n", file_get_contents($this->temporario . '/estado/monitor-saude.estado'));
    }

    public function testRecusaCarimboSymlinkAntesDeConsultarAsSondas(): void
    {
        $resultado = $this->executar(
            100,
            200,
            ['tag' => 'v1.2.3', 'commit' => str_repeat('a', 40)],
            'v1.2.3',
            self::BOT,
            true,
        );

        self::assertSame(2, $resultado['codigo']);
        self::assertStringContainsString(self::PREFIXO . '_RELEASE_FILE não pode ser symlink', $resultado['erro']);
    }

    private function executavel(string $nome, string $conteudo): void
    {
        self::assertNotFalse(file_put_contents($this->bin . '/' . $nome, $conteudo));
        self::assertTrue(chmod($this->bin . '/' . $nome, 0700));
    }

    /**
     * @param array<string, mixed>|null $release
     * @return array{codigo: int, erro: string}
     */
    private function executar(
        int $agora,
        int $statusHttp = 500,
        ?array $release = null,
        string $versao = 'desconhecida',
        string $servico = self::BOT,
        bool $releaseSymlink = false,
        int $codigoCurl = 0,
    ): array {
        $raiz = dirname(__DIR__, 2);
        $releaseFile = $this->temporario . '/release-ausente.json';
        if ($release !== null) {
            self::assertNotFalse(file_put_contents($releaseFile, json_encode($release, JSON_THROW_ON_ERROR)));
        } else {
            @unlink($releaseFile);
        }
        if ($releaseSymlink) {
            $releaseReal = $releaseFile . '.real';
            self::assertTrue(rename($releaseFile, $releaseReal));
            self::assertTrue(symlink($releaseReal, $releaseFile));
        }
        $ambiente = getenv();
        $ambiente['PATH'] = $this->bin . ':' . ($ambiente['PATH'] ?? '/usr/bin:/bin');
        $ambiente['FAKE_NOW'] = (string) $agora;
        $ambiente['FAKE_HTTP_STATUS'] = (string) $statusHttp;
        $ambiente['FAKE_CURL_EXIT'] = (string) $codigoCurl;
        $ambiente[self::PREFIXO . '_PUBLIC_BASE_URL'] = 'https://' . self::BOT . '.example.test';
        $ambiente[self::PREFIXO . '_MONITOR_STATE_DIR'] = $this->temporario . '/estado';
        $ambiente[self::PREFIXO . '_RELEASE_FILE'] = $releaseFile;
        $ambiente[self::PREFIXO . '_MONITOR_ALERT_INTERVAL_SECONDS'] = '3600';
        $ambiente['FAKE_VERSION'] = $versao;
        $ambiente['FAKE_SERVICE'] = $servico;

        $pipes = [];
        $processo = null;
        $processoFechado = false;

        try {
            $processo = proc_open(
                [
                    '/bin/bash',
                    '-p',
                    'scripts/operacao/monitorar_saude.sh',
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $raiz,
                $ambiente,
            );
            self::assertIsResource($processo);
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $erro = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            self::assertIsString($erro);
            $codigo = proc_close($processo);
            $processoFechado = true;
            $processo = null;

            return ['codigo' => $codigo, 'erro' => $erro];
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($processo) && !$processoFechado) {
                $status = proc_get_status($processo);
                if ($status['running']) {
                    proc_terminate($processo);
                }
                @proc_close($processo);
            }
        }
    }
}
