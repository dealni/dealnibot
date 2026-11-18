<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/funcoes_aviso_de_falha.php';

/**
 * Aviso de falha de rotina (inclusos/funcoes_aviso_de_falha.php): o que entra
 * na mensagem, o que é redigido e quando o Telegram é chamado.
 */
final class AvisoDeFalhaTest extends TestCase
{
    private string $estado;

    protected function setUp(): void
    {
        $this->estado = sys_get_temp_dir() . '/aviso-falha-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->estado, 0700));
    }

    protected function tearDown(): void
    {
        $achados = glob($this->estado . '/{,.}*', GLOB_BRACE);
        foreach ($achados === false ? [] : $achados as $arquivo) {
            if (is_file($arquivo) || is_link($arquivo)) {
                unlink($arquivo);
            }
        }
        @rmdir($this->estado);
    }

    public function testArgumentosValidosViramOpcoes(): void
    {
        self::assertSame(
            ['job' => 'backup', 'codigo' => 3, 'log' => '/tmp/log'],
            lerArgumentosDoAviso(['--job=backup', '--codigo=3', '--log=/tmp/log']),
        );
        self::assertSame(['job' => 'snapshot-banco', 'codigo' => 0, 'log' => ''], lerArgumentosDoAviso(['--codigo=0', '--job=snapshot-banco']));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function argumentosInvalidos(): iterable
    {
        yield 'sem nada' => [[]];
        yield 'sem código' => [['--job=backup']];
        yield 'job com maiúscula' => [['--job=Backup', '--codigo=1']];
        yield 'job com barra' => [['--job=../x', '--codigo=1']];
        yield 'código negativo' => [['--job=backup', '--codigo=-1']];
        yield 'código com zero à esquerda' => [['--job=backup', '--codigo=01']];
        yield 'opção desconhecida' => [['--job=backup', '--codigo=1', '--chat=1']];
        yield 'opção repetida' => [['--job=backup', '--job=outro', '--codigo=1']];
    }

    /** @param list<string> $argumentos */
    #[DataProvider('argumentosInvalidos')]
    public function testArgumentosInvalidosSaoRecusados(array $argumentos): void
    {
        $this->expectException(InvalidArgumentException::class);
        lerArgumentosDoAviso($argumentos);
    }

    public function testSegredosDoLogSaoRedigidos(): void
    {
        $texto = redigirSegredosDoAviso(
            "https://api.telegram.org/bot123456:AAH-segredo_x/sendMessage\n"
            . "postgresql://bot:senhaforte@127.0.0.1/db\n"
            . "password=abc123; token: 'xyz' api_key=\"k1\"\n"
            . 'Authorization: Bearer tok.en',
        );

        foreach (['AAH-segredo_x', 'senhaforte', 'abc123', 'xyz', 'k1', 'tok.en'] as $segredo) {
            self::assertStringNotContainsString($segredo, $texto);
        }
        self::assertStringContainsString('bot<redigido>/sendMessage', $texto);
        self::assertStringContainsString('postgresql://<redigido>@127.0.0.1/db', $texto);
        self::assertStringContainsString('password=<redigido>;', $texto);
    }

    public function testFimDoLogPegaAsUltimasLinhasSemControleENemUtf8Quebrado(): void
    {
        $linhas = [];
        for ($i = 1; $i <= 20; $i++) {
            $linhas[] = "linha {$i}";
        }
        $fim = fimDoLogDoAviso(implode("\r\n", $linhas) . "\n\x1b[31mcor\x07 \xC3\n");

        self::assertStringStartsWith('linha 7', $fim);
        self::assertStringNotContainsString("\r", $fim);
        self::assertStringNotContainsString("\x1b", $fim);
        self::assertSame(15, substr_count($fim, "\n") + 1);
        self::assertTrue(mb_check_encoding($fim, 'UTF-8'));
        self::assertSame('(log vazio)', fimDoLogDoAviso(" \n"));
    }

    public function testFimDoLogRespeitaOTetoDaMensagem(): void
    {
        $fim = fimDoLogDoAviso(str_repeat('é', AVISO_BYTES_DO_LOG) . "\nfinal");

        self::assertStringStartsWith('...', $fim);
        self::assertStringEndsWith('final', $fim);
        self::assertLessThanOrEqual(AVISO_BYTES_DO_LOG + 3, strlen($fim));
        self::assertTrue(mb_check_encoding($fim, 'UTF-8'));
    }

    public function testCorteDoLogNoMeioDeUmCaractereNaoDeixaInterrogacao(): void
    {
        // 1500 "é" (3000 bytes) + "\nfinal!" (7): o corte cai no segundo byte de um "é".
        $fim = fimDoLogDoAviso(str_repeat('é', AVISO_BYTES_DO_LOG) . "\nfinal!");

        self::assertStringStartsWith('...é', $fim);
        self::assertStringNotContainsString('?', $fim);
        self::assertStringEndsWith('final!', $fim);
        self::assertSame(AVISO_BYTES_DO_LOG - 1 + 3, strlen($fim));
        self::assertTrue(mb_check_encoding($fim, 'UTF-8'));
    }

    public function testTransporteDoAvisoForcaIpv4ComTimeout(): void
    {
        $opcoes = opcoesDoTransporteDoAviso('chat_id=1&text=x');

        self::assertSame('0.0.0.0:0', $opcoes['socket']['bindto']);
        self::assertSame('POST', $opcoes['http']['method']);
        self::assertSame('chat_id=1&text=x', $opcoes['http']['content']);
        self::assertSame(10, $opcoes['http']['timeout']);
        self::assertTrue($opcoes['http']['ignore_errors']);
    }

    public function testArquivoDeLogLidoSoPeloFimESemSeguirSymlink(): void
    {
        $log = $this->estado . '/log';
        file_put_contents($log, str_repeat('a', AVISO_BYTES_LIDOS) . 'FIM');
        $lido = lerFimDoArquivoDoAviso($log);
        self::assertSame(AVISO_BYTES_LIDOS, strlen($lido));
        self::assertStringEndsWith('FIM', $lido);

        symlink($log, $this->estado . '/atalho');
        self::assertSame('', lerFimDoArquivoDoAviso($this->estado . '/atalho'));
        self::assertSame('', lerFimDoArquivoDoAviso($this->estado . '/ausente'));
        self::assertSame('', lerFimDoArquivoDoAviso(''));
    }

    public function testEnvioSoConfirmaComOkDoTelegram(): void
    {
        $chamadas = [];
        $transporte = static function (string $url, string $corpo) use (&$chamadas): string {
            $chamadas[] = [$url, $corpo];

            return '{"ok":true,"result":{}}';
        };

        self::assertTrue(enviarAvisoTelegram('1:abc', '-100123', 'olá', $transporte));
        self::assertSame('https://api.telegram.org/bot1:abc/sendMessage', $chamadas[0][0]);
        parse_str($chamadas[0][1], $campos);
        self::assertSame('-100123', $campos['chat_id'] ?? null);
        self::assertSame('olá', $campos['text'] ?? null);
        self::assertArrayNotHasKey('parse_mode', $campos);

        self::assertFalse(enviarAvisoTelegram('1:abc', '42', 'x', static fn (): string => '{"ok":false}'));
        self::assertFalse(enviarAvisoTelegram('1:abc', '42', 'x', static fn (): false => false));
        self::assertFalse(enviarAvisoTelegram('', '42', 'x', $transporte));
        self::assertFalse(enviarAvisoTelegram('1:abc', '', 'x', $transporte));
        self::assertFalse(enviarAvisoTelegram('1:abc', '12a', 'x', $transporte));
        self::assertCount(1, $chamadas, 'Token ou chat inválido não chama o Telegram.');
    }

    public function testFalhaAvisaComSistemaJobHostCodigoEFimDoLog(): void
    {
        $log = $this->estado . '/stderr';
        file_put_contents($log, "pg_dump: erro\nsenha=segredo\n");
        $mensagens = [];

        $codigo = processarAvisoDeFalha(
            ['job' => 'backup', 'codigo' => 1, 'log' => $log],
            'dealnibot',
            'srv-teste',
            $this->estado,
            static function (string $texto) use (&$mensagens): bool {
                $mensagens[] = $texto;

                return true;
            },
        );

        self::assertSame(0, $codigo);
        self::assertCount(1, $mensagens);
        foreach (['[FALHA]', 'Sistema: dealnibot', 'Job: backup', 'Host: srv-teste', 'Código de saída: 1', 'pg_dump: erro', 'senha=<redigido>'] as $trecho) {
            self::assertStringContainsString($trecho, $mensagens[0]);
        }
        self::assertStringNotContainsString('segredo', $mensagens[0]);
        self::assertFileExists($this->estado . '/aviso-backup.falhou');
        self::assertSame('600', decoct(fileperms($this->estado . '/aviso-backup.falhou') & 0777));
    }

    public function testFalhaSemEntregaDevolveUmMasMarcaParaARecuperacao(): void
    {
        $codigo = processarAvisoDeFalha(['job' => 'backup', 'codigo' => 2, 'log' => ''], 's', 'h', $this->estado, static fn (): bool => false);

        self::assertSame(1, $codigo);
        self::assertFileExists($this->estado . '/aviso-backup.falhou');
    }

    public function testSucessoSemFalhaAnteriorNaoChamaOTelegram(): void
    {
        $chamou = false;
        $enviar = static function () use (&$chamou): bool {
            $chamou = true;

            return true;
        };

        self::assertSame(0, processarAvisoDeFalha(['job' => 'backup', 'codigo' => 0, 'log' => ''], 's', 'h', $this->estado, $enviar));
        self::assertFalse($chamou);
    }

    public function testSucessoDepoisDeFalhaAvisaUmaVezEApagaAMarca(): void
    {
        $marca = $this->estado . '/aviso-backup.falhou';
        file_put_contents($marca, '1');
        $mensagens = [];
        $enviar = static function (string $texto) use (&$mensagens): bool {
            $mensagens[] = $texto;

            return true;
        };

        self::assertSame(0, processarAvisoDeFalha(['job' => 'backup', 'codigo' => 0, 'log' => ''], 'bot', 'srv', $this->estado . '/', $enviar));
        self::assertSame(0, processarAvisoDeFalha(['job' => 'backup', 'codigo' => 0, 'log' => ''], 'bot', 'srv', $this->estado, $enviar));

        self::assertSame(["[RECUPERADO] Rotina voltou a funcionar\nSistema: bot\nJob: backup\nHost: srv"], $mensagens);
        self::assertFileDoesNotExist($marca);
    }

    public function testRecuperacaoNaoEntregueMantemAMarca(): void
    {
        $marca = $this->estado . '/aviso-backup.falhou';
        file_put_contents($marca, '1');

        self::assertSame(1, processarAvisoDeFalha(['job' => 'backup', 'codigo' => 0, 'log' => ''], 's', 'h', $this->estado, static fn (): bool => false));
        self::assertFileExists($marca);
    }

    public function testMesmaFalhaDentroDaJanelaNaoVoltaAoTelegram(): void
    {
        /** @var \ArrayObject<int, string> $mensagens */
        $mensagens = new \ArrayObject();
        $enviar = static function (string $texto) use ($mensagens): bool {
            $mensagens[] = $texto;

            return true;
        };
        $falha = ['job' => 'monitor-saude', 'codigo' => 1, 'log' => ''];

        self::assertSame(0, processarAvisoDeFalha($falha, 's', 'h', $this->estado, $enviar, 1_000_000));
        self::assertSame(0, processarAvisoDeFalha($falha, 's', 'h', $this->estado, $enviar, 1_000_000 + AVISO_JANELA_DE_REPETICAO - 1));
        self::assertCount(1, $mensagens, 'A segunda falha dentro da janela não pode chamar o Telegram.');
        self::assertSame('1000000', file_get_contents($this->estado . '/aviso-monitor-saude.falhou'));

        self::assertSame(0, processarAvisoDeFalha($falha, 's', 'h', $this->estado, $enviar, 1_000_000 + AVISO_JANELA_DE_REPETICAO));
        self::assertCount(2, $mensagens, 'Passada a janela, a falha que continua é lembrada.');
    }

    public function testEnvioQueFalhouNaoSeguraAProximaTentativa(): void
    {
        $falha = ['job' => 'webhook-inbox', 'codigo' => 1, 'log' => ''];
        self::assertSame(1, processarAvisoDeFalha($falha, 's', 'h', $this->estado, static fn (): bool => false, 1_000_000));
        self::assertSame('0', file_get_contents($this->estado . '/aviso-webhook-inbox.falhou'));

        $entregas = 0;
        $enviar = static function () use (&$entregas): bool {
            $entregas++;

            return true;
        };
        self::assertSame(0, processarAvisoDeFalha($falha, 's', 'h', $this->estado, $enviar, 1_000_010));
        self::assertSame(1, $entregas);
    }

    public function testMarcaEstranhaOuDoFuturoNaoSeguraOAviso(): void
    {
        $marca = $this->estado . '/marca';
        self::assertFalse(avisoRecenteNaMarca($marca, 5000), 'Sem marca não há aviso recente.');
        foreach (['', 'abc', '0', '-5', '6000'] as $conteudo) {
            file_put_contents($marca, $conteudo);
            self::assertFalse(avisoRecenteNaMarca($marca, 5000), "Conteúdo '{$conteudo}' não pode segurar o aviso.");
        }
        file_put_contents($marca, "4000\n");
        self::assertTrue(avisoRecenteNaMarca($marca, 5000));
        unlink($marca);
        symlink($this->estado . '/alvo', $marca);
        file_put_contents($this->estado . '/alvo', '4000');
        self::assertFalse(avisoRecenteNaMarca($marca, 5000), 'Marca em symlink é ignorada.');
    }
}
