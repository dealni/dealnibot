<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/inclusos/funcoes_snapshot_banco.php';

final class SnapshotBancoTest extends TestCase
{
    private string $diretorio;

    protected function setUp(): void
    {
        $this->diretorio = sys_get_temp_dir() . '/dump-teste-' . bin2hex(random_bytes(8));
        mkdir($this->diretorio, 0700);
    }

    protected function tearDown(): void
    {
        foreach (\ConsultaBanco::arquivos($this->diretorio . '/*') as $arquivo) {
            unlink($arquivo);
        }
        rmdir($this->diretorio);
    }

    public function testNomeDoDumpESeletorDeRotacao(): void
    {
        self::assertSame('bot-2023-11-14-221320.dump', nomeDoSnapshotBanco(1700000000));
        self::assertTrue(ehArquivoDeSnapshotBanco('bot-2023-11-14-221320.dump'));
        self::assertFalse(ehArquivoDeSnapshotBanco('bot-2023-11-14-221320.db'));
        self::assertFalse(ehArquivoDeSnapshotBanco('../bot-2023-11-14-221320.dump'));
    }

    public function testDumpCompletoTemPermissoesPrivadasEMetadados(): void
    {
        $dsn = \BancoTeste::dsn();
        $pdo = \BancoTeste::conectar($dsn);
        $pdo->exec('CREATE TABLE exemplo (id BIGINT PRIMARY KEY, texto TEXT)');
        $pdo->exec("INSERT INTO exemplo VALUES (1, 'ação 🚀')");
        $resultado = executarSnapshotBanco($dsn, $this->diretorio, 2, static fn (): int => 1700000000);
        self::assertTrue(snapshotBancoIntegro($resultado['arquivo']));
        self::assertSame(filesize($resultado['arquivo']), $resultado['bytes']);
        self::assertSame(0600, fileperms($resultado['arquivo']) & 0777);
        self::assertSame(0700, fileperms($this->diretorio) & 0777);
        $json = file_get_contents($resultado['arquivo'] . '.json');
        self::assertIsString($json);
        $manifesto = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifesto);
        self::assertSame('postgresql pg_dump custom', $manifesto['formato']);
        self::assertStringNotContainsString($dsn, $json);
        self::assertSame([], glob($this->diretorio . '/*.tmp-*'));
    }

    public function testDumpCorrompidoNaoEValidadoPelosMetadados(): void
    {
        $arquivo = $this->diretorio . '/bot-2023-11-14-221320.dump';
        file_put_contents($arquivo, 'PGDMP incompleto');
        gravarMetadadosSnapshotBanco('postgresql', $arquivo);
        self::assertTrue(snapshotBancoMetadadosConferem($arquivo));
        self::assertFalse(snapshotBancoIntegro($arquivo));
    }

    public function testColisaoNaoSobrescreveArquivo(): void
    {
        $arquivo = $this->diretorio . '/existente.dump';
        file_put_contents($arquivo, 'preservar');
        try {
            gravarSnapshotBanco(\BancoTeste::dsn(), $arquivo);
            self::fail('Destino existente foi aceito.');
        } catch (\RuntimeException) {
            self::assertSame('preservar', file_get_contents($arquivo));
        }
    }

    public function testRotacaoPreservaArquivosAlheiosESidecarsIncompletos(): void
    {
        foreach (['bot-2023-11-14-221320.dump', 'bot-2023-11-15-221320.dump'] as $nome) {
            $arquivo = $this->diretorio . '/' . $nome;
            file_put_contents($arquivo, 'fixture de metadados');
            gravarMetadadosSnapshotBanco('postgresql', $arquivo);
        }
        file_put_contents($this->diretorio . '/bot-2023-11-13-221320.db', 'preservar');
        file_put_contents($this->diretorio . '/manual.dump', 'preservar');
        file_put_contents($this->diretorio . '/bot-2023-11-11-221320.dump', 'sem metadados');
        file_put_contents($this->diretorio . '/bot-2023-11-12-221320.dump', 'incompleto');
        file_put_contents($this->diretorio . '/bot-2023-11-12-221320.dump.json', '{}');
        self::assertSame([], rotacionarSnapshotsBanco($this->diretorio, 7));
        self::assertFileExists($this->diretorio . '/bot-2023-11-13-221320.db');
        self::assertFileExists($this->diretorio . '/manual.dump');
        self::assertFileExists($this->diretorio . '/bot-2023-11-11-221320.dump');
        self::assertFileExists($this->diretorio . '/bot-2023-11-12-221320.dump');
    }

    public function testSnapshotRecusaSymlink(): void
    {
        $alvo = $this->diretorio . '/alvo';
        file_put_contents($alvo, 'preservar');
        $link = $this->diretorio . '/link.dump';
        symlink($alvo, $link);
        self::assertFalse(snapshotBancoIntegro($link));
        $this->expectException(\RuntimeException::class);
        gravarSnapshotBanco(\BancoTeste::dsn(), $link);
    }
    public function testColisaoDeMetadadosPreservaArquivoExistente(): void
    {
        $dsn = \BancoTeste::dsn();
        $pdo = \BancoTeste::conectar($dsn);
        $pdo->exec('CREATE TABLE exemplo (id BIGINT PRIMARY KEY)');
        $destino = $this->diretorio . '/bot-2023-11-14-221320.dump';
        file_put_contents($destino . '.json', 'preservar');
        try {
            gravarSnapshotBanco($dsn, $destino);
            self::fail('A colisão de metadados não foi recusada.');
        } catch (\RuntimeException) {
            self::assertSame('preservar', file_get_contents($destino . '.json'));
            self::assertFileDoesNotExist($destino);
            self::assertFileDoesNotExist($destino . '.sha256');
        }
    }

    public function testBackupDePreDeployTemPrefixoERetencaoProprios(): void
    {
        self::assertSame('pre-deploy-2023-11-14-221320.dump', nomeDoSnapshotBanco(1700000000, PREFIXO_SNAPSHOT_PRE_DEPLOY));
        self::assertFalse(ehArquivoDeSnapshotBanco('pre-deploy-2023-11-14-221320.dump'));
        self::assertTrue(ehArquivoDeSnapshotBanco('pre-deploy-2023-11-14-221320.dump', PREFIXO_SNAPSHOT_PRE_DEPLOY));
        self::assertFalse(ehArquivoDeSnapshotBanco('bot-2023-11-14-221320.dump', PREFIXO_SNAPSHOT_PRE_DEPLOY));

        $diarios = ['bot-2026-01-01-000000.dump', 'bot-2026-01-02-000000.dump', 'bot-2026-01-03-000000.dump'];
        $preDeploy = ['pre-deploy-2026-01-04-000000.dump', 'pre-deploy-2026-01-05-000000.dump', 'pre-deploy-2026-01-06-000000.dump', 'pre-deploy-2026-01-07-000000.dump'];
        foreach ([...$diarios, ...$preDeploy] as $nome) {
            $arquivo = $this->diretorio . '/' . $nome;
            file_put_contents($arquivo, 'fixture de metadados');
            gravarMetadadosSnapshotBanco('postgresql', $arquivo);
        }

        // Os quatro backups de deploy, mais novos, não empurram diário nenhum
        // para fora da semana: a rotação diária só conta os três `bot-`.
        self::assertSame([], rotacionarSnapshotsBanco($this->diretorio, 3));
        self::assertSame($diarios, listarSnapshotsBanco($this->diretorio));

        self::assertSame(['pre-deploy-2026-01-04-000000.dump'], rotacionarSnapshotsBanco($this->diretorio, BACKUPS_PRE_DEPLOY_A_MANTER, PREFIXO_SNAPSHOT_PRE_DEPLOY));
        self::assertSame(array_slice($preDeploy, 1), listarSnapshotsBanco($this->diretorio, PREFIXO_SNAPSHOT_PRE_DEPLOY));
        self::assertSame($diarios, listarSnapshotsBanco($this->diretorio));
    }

    public function testJobDiarioRegistraSucessoEFalhaNoCanalDoSnapshot(): void
    {
        $registros = new \Monolog\Handler\TestHandler();
        $log = new \Monolog\Logger('snapshot_banco', [$registros]);
        $saida = fopen('php://memory', 'w+b');
        $erros = fopen('php://memory', 'w+b');
        self::assertIsResource($saida);
        self::assertIsResource($erros);

        // Leituras de hrtime(true) em nanossegundos: 1,5 s no sucesso e 0,25 s
        // na falha, sem depender da hora real.
        $leituras = [10_000_000_000, 11_500_000_000, 20_000_000_000, 20_250_000_000];
        $relogio = static function () use (&$leituras): int {
            $leitura = array_shift($leituras);
            self::assertIsInt($leitura, 'O job leu o relógio mais vezes do que o esperado.');

            return $leitura;
        };

        $sucesso = rodarSnapshotDiarioBanco(
            static fn (): array => ['arquivo' => '/var/backups/dealnibot/bot-2026-09-28-081000.dump', 'bytes' => 42, 'removidos' => ['bot-2026-09-21-081000.dump']],
            $log,
            $saida,
            $erros,
            $relogio,
        );
        self::assertSame(0, $sucesso);
        self::assertTrue($registros->hasInfoThatContains('Snapshot PostgreSQL concluído'));
        self::assertSame(
            ['arquivo' => 'bot-2026-09-28-081000.dump', 'bytes' => 42, 'removidos' => ['bot-2026-09-21-081000.dump'], 'duration_ms' => 1500],
            $registros->getRecords()[0]->context,
        );
        rewind($saida);
        self::assertSame("Snapshot PostgreSQL: /var/backups/dealnibot/bot-2026-09-28-081000.dump\n", stream_get_contents($saida));

        $falha = rodarSnapshotDiarioBanco(
            static fn (): array => throw new \RuntimeException('pg_dump falhou'),
            $log,
            $saida,
            $erros,
            $relogio,
        );
        self::assertSame(1, $falha);
        self::assertSame([], $leituras);
        self::assertTrue($registros->hasErrorThatContains('Snapshot PostgreSQL falhou'));
        self::assertSame(['class' => \RuntimeException::class, 'message' => 'Falha ao executar snapshot PostgreSQL.'], $registros->getRecords()[1]->context['err']);
        self::assertSame(250, $registros->getRecords()[1]->context['duration_ms']);
        rewind($erros);
        self::assertSame("Falha ao executar snapshot PostgreSQL.\n", stream_get_contents($erros));
    }

    public function testFalhaDoJobMantemTipoNoFormatterSemMensagemDoDriver(): void
    {
        $handler = new \Monolog\Handler\TestHandler();
        $handler->setFormatter(new \App\Support\FormatadorDeContrato());
        $logger = new \Monolog\Logger('snapshot_banco', [$handler]);
        $saida = fopen('php://memory', 'w+b');
        $erros = fopen('php://memory', 'w+b');
        self::assertIsResource($saida);
        self::assertIsResource($erros);
        $status = rodarSnapshotDiarioBanco(
            static fn (): array => throw new \PDOException('driver-cru password=segredo-fixture host=privado'),
            $logger,
            $saida,
            $erros,
        );
        self::assertSame(1, $status);
        $formatado = $handler->getRecords()[0]->formatted;
        self::assertIsString($formatado);
        $evento = json_decode($formatado, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($evento);
        self::assertSame('error', $evento['level']);
        self::assertIsArray($evento['err']);
        self::assertSame(\PDOException::class, $evento['err']['type']);
        self::assertSame('Falha ao executar snapshot PostgreSQL.', $evento['err']['message']);
        self::assertStringNotContainsString('driver-cru', $formatado);
        self::assertStringNotContainsString('segredo-fixture', $formatado);
        rewind($erros);
        self::assertSame("Falha ao executar snapshot PostgreSQL.\n", stream_get_contents($erros));
        fclose($saida);
        fclose($erros);
    }

    public function testJobDiarioSemRelogioInjetadoMedeComOHrtime(): void
    {
        $registros = new \Monolog\Handler\TestHandler();
        $saida = fopen('php://memory', 'w+b');
        $erros = fopen('php://memory', 'w+b');
        self::assertIsResource($saida);
        self::assertIsResource($erros);

        rodarSnapshotDiarioBanco(
            static fn (): array => ['arquivo' => '/tmp/bot-2026-09-28-081000.dump', 'bytes' => 1, 'removidos' => []],
            new \Monolog\Logger('snapshot_banco', [$registros]),
            $saida,
            $erros,
        );

        $duracao = $registros->getRecords()[0]->context['duration_ms'] ?? null;
        self::assertIsInt($duracao);
        self::assertGreaterThanOrEqual(0, $duracao);
    }
}
