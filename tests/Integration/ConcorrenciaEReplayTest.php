<?php

declare(strict_types=1);

namespace Tests;

use bdTelegram;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Invariantes de concorrência e de replay sobre o PostgreSQL real.
 *
 * Este bot não tem processo residente: quem escreve no banco são webhooks
 * atendidos em paralelo por pools do PHP-FPM, cada um com a SUA conexão. As
 * duas coisas que podem dar errado aí não aparecem em teste de unidade:
 *
 * - **dois escritores no mesmo recurso** — duas conversas privadas do mesmo
 *   usuário chegando juntos. O arranjo que sustenta isso é o `INSERT OR IGNORE`
 *   em `bdTelegram::registrarContato()`: sem ele, o
 *   `get`→`insert` de dois processos colidiria no índice UNIQUE e um dos dois
 *   morreria com PDOException no meio do fluxo do usuário.
 * - **replay** — o Telegram reentrega o update quando não recebe o ack a tempo.
 *   Reprocessar o mesmo cadastro não pode criar linha nova nem duplicar
 *   efeito.
 *
 * Duas conexões de verdade, no mesmo schema, com os timeouts e locks que a
 * classe aplica. Nada de dublê: o que se prova aqui é o comportamento do banco
 * sob duas conexões, e dublê não tem lock.
 */
final class ConcorrenciaEReplayTest extends TestCase
{
    private string $arquivo = '';
    private ?bdTelegram $primeira = null;
    private ?bdTelegram $segunda = null;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../inclusos/bdTelegram.php';

        $this->arquivo = (string) tempnam(sys_get_temp_dir(), 'dealnibot_corrida_');
        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        self::assertIsString($schema);
        $pdo = \BancoTeste::conectar($this->arquivo);
        $pdo->exec($schema);
        $pdo = null;

        $this->primeira = new bdTelegram(\BancoTeste::dsn($this->arquivo));
        $this->segunda = new bdTelegram(\BancoTeste::dsn($this->arquivo));
    }

    protected function tearDown(): void
    {
        $this->primeira = null;
        $this->segunda = null;
        if ($this->arquivo !== '' && file_exists($this->arquivo)) {
            @unlink($this->arquivo);
        }
    }

    private function conexao(?bdTelegram $bd): bdTelegram
    {
        self::assertInstanceOf(bdTelegram::class, $bd);

        return $bd;
    }

    /**
     * Valor de coluna do PDO: as bordas do banco são `mixed` por contrato, e
     * `is_numeric()`/cast explícito é a guarda correta (ver docs/CONVENCOES.md).
     *
     * @param array<string, mixed> $linha
     */
    private function valor(array $linha, string $coluna): mixed
    {
        self::assertArrayHasKey($coluna, $linha);

        return $linha[$coluna];
    }

    /**
     * Coluna numérica volta como int e coluna textual como string; o teste
     * compara texto para não depender de qual das duas o driver escolheu.
     *
     * @param array<string, mixed> $linha
     */
    private function valorTextual(array $linha, string $coluna): string
    {
        $valor = $this->valor($linha, $coluna);
        if (is_int($valor)) {
            return (string) $valor;
        }
        if (is_string($valor)) {
            return $valor;
        }

        self::fail("Coluna {$coluna} veio com tipo inesperado.");
    }

    /** @return list<array<string, mixed>> */
    private function linhasDoUsuario(int $idTelegram): array
    {
        $pdo = \BancoTeste::conectar($this->arquivo);
        $consulta = $pdo->prepare('SELECT * FROM usuarios WHERE id_telegram = ?');
        self::assertNotFalse($consulta);
        $consulta->execute([$idTelegram]);
        $linhas = $consulta->fetchAll(PDO::FETCH_ASSOC);

        /** @var list<array<string, mixed>> $linhas */
        return $linhas;
    }

    public function testDoisEscritoresNoMesmoUsuarioConvergemParaUmaLinha(): void
    {
        $id = 987654;

        $codigo = <<<'PHP'
            require $argv[1] . '/tests/bootstrap.php';
            require_once $argv[1] . '/inclusos/bdTelegram.php';
            $bd = new bdTelegram(\BancoTeste::dsn($argv[2]));
            fwrite(STDOUT, "pronto\n");
            if (fgets(STDIN) === false) {
                exit(2);
            }
            echo $bd->registrarContato((int) $argv[3]) ? '1' : '0';
            PHP;
        $filhos = [];

        try {
            for ($i = 0; $i < 8; $i++) {
                $processo = proc_open(
                    [PHP_BINARY, '-r', $codigo, dirname(__DIR__, 2), $this->arquivo, (string) $id],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($processo);
                $filhos[] = ['processo' => $processo, 'pipes' => $pipes];
                self::assertSame("pronto\n", fgets($pipes[1]));
            }

            foreach ($filhos as $filho) {
                fwrite($filho['pipes'][0], "iniciar\n");
                fclose($filho['pipes'][0]);
            }
            foreach ($filhos as $filho) {
                self::assertSame('1', stream_get_contents($filho['pipes'][1]));
                self::assertSame('', stream_get_contents($filho['pipes'][2]));
                self::assertSame(0, proc_close($filho['processo']));
            }
        } finally {
            foreach ($filhos as $filho) {
                foreach ($filho['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($filho['processo'])) {
                    proc_terminate($filho['processo']);
                    proc_close($filho['processo']);
                }
            }
        }

        $linhas = $this->linhasDoUsuario($id);
        self::assertCount(1, $linhas, 'Dois escritores criaram duas linhas: o INSERT OR IGNORE saiu do caminho.');
        self::assertSame('0', $this->valorTextual($linhas[0], 'consentiu_anuncios'));
    }

    /**
     * O escritor de fora do banco (outro FPM) entra ANTES: quando a segunda
     * conexão chega, a linha já existe. É o intercalamento que quebra o
     * `get`→`insert` ingênuo, e o que o `INSERT OR IGNORE` absorve.
     */
    public function testEscritaIntercaladaNaoEstouraOIndiceUnico(): void
    {
        $id = 55501;

        self::assertTrue($this->conexao($this->primeira)->addUsuario($id));
        self::assertTrue($this->conexao($this->segunda)->registrarContato($id));

        $linhas = $this->linhasDoUsuario($id);
        self::assertCount(1, $linhas);
        self::assertSame('0', $this->valorTextual($linhas[0], 'consentiu_anuncios'));
    }

    /**
     * Uma conexão escreve dentro de uma transação enquanto a outra lê. Com WAL
     * a leitura não bloqueia; sem ela, este teste ficaria pendurado até o
     * `busy_timeout` de 30 s — que é o modo de falha "database is locked" do
     * runbook.
     */
    public function testLeituraNaoBloqueiaDuranteEscritaEmTransacao(): void
    {
        $id = 55502;
        $escritor = \BancoTeste::conectar($this->arquivo);
        $escritor->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $escritor->exec("SET lock_timeout = '5000ms'");
        $escritor->exec('BEGIN IMMEDIATE');
        $escritor->exec('INSERT INTO usuarios (id_telegram) VALUES (' . $id . ')');

        // Ainda não houve COMMIT: o leitor tem de enxergar o estado anterior,
        // sem erro e sem espera.
        self::assertFalse($this->conexao($this->segunda)->getUsuario($id));

        $escritor->exec('COMMIT');
        $escritor = null;

        self::assertNotFalse($this->conexao($this->segunda)->getUsuario($id));
    }

    public function testReprocessarOMesmoCadastroNaoDuplicaEfeito(): void
    {
        $id = 55503;
        $bd = $this->conexao($this->primeira);

        $bd->registrarContato($id);
        $primeiraData = $this->valor($this->linhasDoUsuario($id)[0], 'consentido_em');

        // O mesmo update reentregue pelo Telegram: mesma linha, mesmo efeito.
        $bd->registrarContato($id);

        $linhas = $this->linhasDoUsuario($id);
        self::assertCount(1, $linhas);
        self::assertSame('0', $this->valorTextual($linhas[0], 'consentiu_anuncios'));
        self::assertNull($primeiraData);
        self::assertNull($this->valor($linhas[0], 'consentido_em'));
    }

}
