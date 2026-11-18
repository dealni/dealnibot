<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * scripts/operacao/avisar_se_falhar.sh e a entrada scripts/operacao/avisar_falha.php.
 *
 * O PHP85_BIN do wrapper é um espião em shell: ele registra os argumentos e o
 * log que receberia, sem Telegram. A entrada PHP roda de verdade só nos
 * caminhos que não saem da máquina (argumentos inválidos e sucesso sem falha
 * anterior, que nem carrega a configuração do bot).
 */
final class AvisarSeFalharTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    private const PREFIXO = 'DEALNI';
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    private string $temporario;

    protected function setUp(): void
    {
        // Caminho canônico: o script recusa PHP85_BIN com symlink no caminho
        // (no macOS, /var aponta para /private/var).
        $this->temporario = (string) realpath(sys_get_temp_dir()) . '/avisar-se-falhar-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporario, 0700));
        $espiao = $this->temporario . '/php';
        file_put_contents($espiao, <<<'SH'
#!/bin/sh
# `-r` é a conferência de versão (ESPIAO_VERSAO_OK=0 simula outro PHP); o
# primeiro argumento .php do aviso registra; qualquer outro é o job via --php.
case "$1" in
    -r)
        [ "${ESPIAO_VERSAO_OK:-1}" = 1 ]
        exit $?
        ;;
    */avisar_falha.php)
        printf '%s\n' "$@" >> "$ESPIAO_DIR/chamadas"
        for argumento in "$@"; do
            case "$argumento" in
                --log=*) cat "${argumento#--log=}" >> "$ESPIAO_DIR/logs" ;;
            esac
        done
        exit "${ESPIAO_CODIGO_AVISO:-0}"
        ;;
esac
printf 'job-php:%s\n' "$*"
exit 0
SH);
        chmod($espiao, 0700);
    }

    protected function tearDown(): void
    {
        $achados = glob($this->temporario . '/*');
        foreach ($achados === false ? [] : $achados as $arquivo) {
            unlink($arquivo);
        }
        rmdir($this->temporario);
    }

    public function testFalhaPreservaCodigoESaidasEChamaOAvisoComOStderr(): void
    {
        $r = $this->executar(['backup', '/bin/sh', '-c', 'echo saida; echo "pg_dump falhou" >&2; exit 3']);

        self::assertSame(3, $r['codigo']);
        self::assertSame("saida\n", $r['saida']);
        self::assertStringContainsString('pg_dump falhou', $r['erro']);
        $chamadas = (string) file_get_contents($this->temporario . '/chamadas');
        self::assertStringContainsString("--job=backup\n--codigo=3\n--log=", $chamadas);
        self::assertStringEndsWith("avisar_falha.php\n--job=backup\n--codigo=3\n--log=" . $this->logRegistrado($chamadas) . "\n", $chamadas);
        self::assertSame("pg_dump falhou\n", file_get_contents($this->temporario . '/logs'));
        self::assertFileDoesNotExist($this->logRegistrado($chamadas), 'O log temporário é apagado no fim.');
    }

    public function testSucessoAindaConsultaOAvisoParaARecuperacao(): void
    {
        $r = $this->executar(['backup', '/bin/sh', '-c', 'exit 0']);

        self::assertSame(0, $r['codigo']);
        self::assertStringContainsString("--job=backup\n--codigo=0\n", (string) file_get_contents($this->temporario . '/chamadas'));
    }

    public function testAvisoQueFalhaNaoMudaOCodigoDoJob(): void
    {
        $r = $this->executar(['backup', '/bin/sh', '-c', 'exit 4'], ['ESPIAO_CODIGO_AVISO' => '1']);

        self::assertSame(4, $r['codigo']);
        self::assertStringContainsString('Aviso de backup não enviado', $r['erro']);
    }

    public function testModoPhpUsaOPhp85Bin(): void
    {
        $r = $this->executar(['snapshot', '--php', '/caminho/job.php', '--x']);

        self::assertSame(0, $r['codigo']);
        self::assertSame("job-php:/caminho/job.php --x\n", $r['saida']);
    }

    public function testSemPhp85BinRodaOJobSemAviso(): void
    {
        $r = $this->executar(['backup', '/bin/sh', '-c', 'exit 5'], ['PHP85_BIN' => '']);

        self::assertSame(5, $r['codigo']);
        self::assertStringContainsString('aviso de falha de backup fica desligado', $r['erro']);
        self::assertFileDoesNotExist($this->temporario . '/chamadas');
    }

    public function testPhp85BinComSymlinkDesligaOAvisoERecusaOModoPhp(): void
    {
        symlink($this->temporario . '/php', $this->temporario . '/php-atalho');
        $ambiente = ['PHP85_BIN' => $this->temporario . '/php-atalho'];

        $r = $this->executar(['backup', '/bin/sh', '-c', 'exit 5'], $ambiente);
        self::assertSame(5, $r['codigo']);
        self::assertStringContainsString('aviso de falha de backup fica desligado', $r['erro']);
        self::assertFileDoesNotExist($this->temporario . '/chamadas');

        $php = $this->executar(['snapshot', '--php', '/caminho/job.php'], $ambiente);
        self::assertSame(1, $php['codigo']);
        self::assertSame('', $php['saida'], 'O job não roda com PHP85_BIN reprovado.');
        self::assertStringContainsString('snapshot não roda com --php', $php['erro']);
    }

    public function testPhp85BinComCaminhoNaoCanonicoERecusado(): void
    {
        $r = $this->executar(['backup', '/bin/sh', '-c', 'exit 5'], ['PHP85_BIN' => $this->temporario . '/./php']);

        self::assertSame(5, $r['codigo']);
        self::assertStringContainsString('aviso de falha de backup fica desligado', $r['erro']);
        self::assertFileDoesNotExist($this->temporario . '/chamadas');
    }

    public function testPhp85BinQueNaoEPhp85DesligaOAvisoERecusaOModoPhp(): void
    {
        $ambiente = ['ESPIAO_VERSAO_OK' => '0'];

        $r = $this->executar(['backup', '/bin/sh', '-c', 'exit 5'], $ambiente);
        self::assertSame(5, $r['codigo']);
        self::assertStringContainsString('não é PHP 8.5', $r['erro']);
        self::assertFileDoesNotExist($this->temporario . '/chamadas');

        $php = $this->executar(['snapshot', '--php', '/caminho/job.php'], $ambiente);
        self::assertSame(1, $php['codigo']);
        self::assertSame('', $php['saida']);
    }

    public function testUsoInvalidoSaiComDois(): void
    {
        self::assertSame(2, $this->executar(['Backup', '/bin/true'])['codigo']);
        self::assertSame(2, $this->executar(['backup'])['codigo']);
        self::assertSame(2, $this->executar(['backup', '--php'])['codigo']);
    }

    public function testEntradaPhpRecusaArgumentosESucessoSemFalhaNaoFazNada(): void
    {
        $invalido = $this->php(['--job=backup']);
        self::assertSame(2, $invalido['codigo']);
        self::assertStringContainsString('Uso: avisar_falha.php', $invalido['erro']);

        $sucesso = $this->php(['--job=backup', '--codigo=0']);
        self::assertSame(0, $sucesso['codigo'], $sucesso['erro']);
        self::assertSame('', $sucesso['erro']);
    }

    public function testSemChatConfiguradoOAvisoFicaDesligado(): void
    {
        $falha = $this->php(['--job=snapshot', '--codigo=1'], '');
        self::assertSame(0, $falha['codigo']);
        self::assertStringContainsString('Aviso de snapshot desligado: configure ' . self::PREFIXO . '_ALERTA_CHAT_ID', $falha['erro']);
        self::assertFileDoesNotExist($this->temporario . '/aviso-snapshot.falhou');

        $sucesso = $this->php(['--job=snapshot', '--codigo=0'], '');
        self::assertSame(0, $sucesso['codigo']);
        self::assertSame('', $sucesso['erro']);
    }

    private function logRegistrado(string $chamadas): string
    {
        self::assertSame(1, preg_match('/--log=(\S+)/', $chamadas, $partes));

        return $partes[1];
    }

    /**
     * @param list<string> $argumentos
     * @param array<string, string> $extras
     * @return array{codigo: int, saida: string, erro: string}
     */
    private function executar(array $argumentos, array $extras = []): array
    {
        $ambiente = [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'PHP85_BIN' => $this->temporario . '/php',
            'ESPIAO_DIR' => $this->temporario,
            'TMPDIR' => $this->temporario,
        ];

        return $this->rodar(['/bin/bash', dirname(__DIR__, 2) . '/scripts/operacao/avisar_se_falhar.sh', ...$argumentos], $extras + $ambiente);
    }

    /**
     * @param list<string> $argumentos
     * @return array{codigo: int, saida: string, erro: string}
     */
    private function php(array $argumentos, string $chat = '-100123'): array
    {
        return $this->rodar([PHP_BINARY, dirname(__DIR__, 2) . '/scripts/operacao/avisar_falha.php', ...$argumentos], [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            self::PREFIXO . '_MONITOR_STATE_DIR' => $this->temporario,
            self::PREFIXO . '_ALERTA_CHAT_ID' => $chat,
        ]);
    }

    /**
     * @param list<string> $comando
     * @param array<string, string> $ambiente
     * @return array{codigo: int, saida: string, erro: string}
     */
    private function rodar(array $comando, array $ambiente): array
    {
        $pipes = [];
        $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->temporario, $ambiente);
        self::assertIsResource($processo);
        $saida = (string) stream_get_contents($pipes[1]);
        $erro = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['codigo' => proc_close($processo), 'saida' => $saida, 'erro' => $erro];
    }
}
