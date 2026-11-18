<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * O saneador que o deploy roda antes de validar o `environment` do host.
 *
 * Ele edita um arquivo de `/etc` com credenciais dentro, e por isso o que
 * importa aqui não é só "removeu": é que nada ALÉM das chaves legadas mude —
 * comentário, espaçamento, chave de nome parecido e ordem das linhas —, que a
 * permissão do arquivo sobreviva à troca, que exista cópia do conteúdo
 * anterior e que a segunda execução não escreva nada.
 */
final class SaneamentoDeAmbienteLegadoTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    private const COM_LEGADO = <<<'ENV'
        # Contrato de ambiente do host.
        DEALNI_PUBLIC_BASE_URL=https://bot.example.com
        DEALNI_SNAPSHOTS_DIR=/var/backups/dealnibot
        # Cópia externa do backup por rclone; comentário histórico.
        DEALNI_BACKUP_RCLONE_REMOTE=gdrive-crypt
        DEALNI_BACKUP_RCLONE_FOLDER=Backups_Chatbots/dealnibot
          DEALNI_BACKUP_RCLONE_MANTER = 7
        export DEALNI_BACKUP_OFFSITE_DIR=/mnt/externo
        DEALNI_BACKUP_RCLONE_REMOTE_NOVO=nao-e-legada

        PHP85_BIN=/usr/bin/php8.5
        ENV;

    private const SANEADO = <<<'ENV'
        # Contrato de ambiente do host.
        DEALNI_PUBLIC_BASE_URL=https://bot.example.com
        DEALNI_SNAPSHOTS_DIR=/var/backups/dealnibot
        # Cópia externa do backup por rclone; comentário histórico.
        DEALNI_BACKUP_RCLONE_REMOTE_NOVO=nao-e-legada

        PHP85_BIN=/usr/bin/php8.5
        ENV;

    /**
     * As chaves que o saneador deste repositório remove (bloco de dados do
     * script); a remoção de cada uma precisa aparecer no log.
     *
     * @var list<string>
     */
    private const CHAVES_LEGADAS = [
        'DEALNI_BACKUP_RCLONE_REMOTE',
        'DEALNI_BACKUP_RCLONE_FOLDER',
        'DEALNI_BACKUP_RCLONE_MANTER',
        'DEALNI_BACKUP_OFFSITE_DIR',
    ];
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    /** @var list<string> */
    private array $lixo = [];

    protected function tearDown(): void
    {
        foreach ($this->lixo as $caminho) {
            foreach ((array) glob($caminho . '/{,.}*', GLOB_BRACE) as $arquivo) {
                if (is_string($arquivo) && is_file($arquivo)) {
                    unlink($arquivo);
                }
            }
            if (is_dir($caminho)) {
                rmdir($caminho);
            }
        }
        $this->lixo = [];
    }

    public function testRemoveSomenteAsChavesLegadasEPreservaOResto(): void
    {
        $arquivo = $this->ambiente(self::COM_LEGADO);

        $resultado = $this->sanear($arquivo);

        $this->assertSame(0, $resultado['status'], $resultado['saida']);
        $this->assertSame(self::SANEADO . "\n", file_get_contents($arquivo));
        foreach (self::CHAVES_LEGADAS as $chave) {
            $this->assertStringContainsString($chave, $resultado['saida'], 'A remoção precisa aparecer no log.');
        }
        clearstatcache(true, $arquivo);
        $this->assertSame(0640, (int) fileperms($arquivo) & 0777, 'A troca atômica não pode afrouxar o modo.');
    }

    public function testGuardaCopiaDoConteudoAnteriorComAMesmaPermissao(): void
    {
        $arquivo = $this->ambiente(self::COM_LEGADO);

        $this->sanear($arquivo);

        $copias = $this->copias($arquivo);
        $this->assertCount(1, $copias);
        $this->assertSame(self::COM_LEGADO . "\n", file_get_contents($copias[0]));
        $this->assertSame(0640, (int) fileperms($copias[0]) & 0777, 'A cópia carrega as mesmas credenciais.');
    }

    public function testSegundaExecucaoNaoEscreveNemGeraNovaCopia(): void
    {
        $arquivo = $this->ambiente(self::COM_LEGADO);
        $this->sanear($arquivo);

        $resultado = $this->sanear($arquivo);

        $this->assertSame(0, $resultado['status'], $resultado['saida']);
        $this->assertStringContainsString('não foi alterado', $resultado['saida']);
        $this->assertSame(self::SANEADO . "\n", file_get_contents($arquivo));
        $this->assertCount(1, $this->copias($arquivo), 'Sem chave legada, nada é escrito.');
    }

    public function testArquivoSemChaveLegadaFicaIntacto(): void
    {
        $arquivo = $this->ambiente(self::SANEADO);
        $antes = (int) filemtime($arquivo);

        $resultado = $this->sanear($arquivo);

        $this->assertSame(0, $resultado['status'], $resultado['saida']);
        $this->assertStringContainsString('sem chave legada', $resultado['saida']);
        $this->assertSame(self::SANEADO . "\n", file_get_contents($arquivo));
        $this->assertSame([], $this->copias($arquivo));
        clearstatcache(true, $arquivo);
        $this->assertSame($antes, (int) filemtime($arquivo));
    }

    /** Cria um EnvironmentFile isolado com o modo que o host usa (0640). */
    private function ambiente(string $conteudo): string
    {
        $diretorio = sys_get_temp_dir() . '/ambiente-legado-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($diretorio, 0700));
        $this->lixo[] = $diretorio;
        $arquivo = $diretorio . '/environment';
        $this->assertNotFalse(file_put_contents($arquivo, $conteudo . "\n"));
        $this->assertTrue(chmod($arquivo, 0640));

        return $arquivo;
    }

    /** @return list<string> */
    private function copias(string $arquivo): array
    {
        $encontradas = [];
        foreach ((array) glob($arquivo . '.legado-*') as $copia) {
            if (is_string($copia)) {
                $encontradas[] = $copia;
            }
        }

        return $encontradas;
    }

    /** @return array{status: int, saida: string} */
    private function sanear(string $arquivo): array
    {
        $processo = proc_open(
            ['bash', dirname(__DIR__, 2) . '/scripts/deploy/sanear_ambiente_legado.sh', '--file=' . $arquivo],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $canos,
        );
        $this->assertIsResource($processo);
        fclose($canos[0]);
        $saida = (string) stream_get_contents($canos[1]) . (string) stream_get_contents($canos[2]);
        fclose($canos[1]);
        fclose($canos[2]);

        return ['status' => proc_close($processo), 'saida' => $saida];
    }
}
