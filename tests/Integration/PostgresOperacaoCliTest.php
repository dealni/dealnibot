<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCli;

require_once dirname(__DIR__, 2) . '/inclusos/funcoes_snapshot_banco.php';
require_once __DIR__ . '/../Support/ExecutorCli.php';

final class PostgresOperacaoCliTest extends TestCase
{
    private string $dir;
    private string $dsn;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/postgres-cli-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        $this->dsn = \BancoTeste::dsn();
        $pdo = \BancoTeste::conectar($this->dsn);
        $pdo->exec('CREATE TABLE marcador (valor TEXT)');
        $pdo->exec("INSERT INTO marcador VALUES ('preservar')");
    }

    protected function tearDown(): void
    {
        foreach (\ConsultaBanco::arquivos($this->dir . '/*') as $arquivo) {
            unlink($arquivo);
        }
        rmdir($this->dir);
    }

    /** @return array<string, string|false> */
    private function ambiente(): array
    {
        return ['TEST_DATABASE_DSN' => $this->dsn, 'DEALNI_SNAPSHOTS_DIR' => $this->dir, 'DB_RESTORE_CONFIRM' => false];
    }

    public function testBackupCliPublicaDumpValido(): void
    {
        $saida = ExecutorCli::executar(dirname(__DIR__, 2) . '/scripts/deploy/db_backup.php', ['--rotulo=pre-deploy'], $this->ambiente());
        self::assertSame(0, $saida['codigo'], $saida['stderr']);
        self::assertTrue(snapshotBancoIntegro(trim($saida['stdout'])));
        // O backup do deploy tem prefixo próprio e não entra na conta dos diários.
        self::assertStringStartsWith('pre-deploy-', basename(trim($saida['stdout'])));
        self::assertSame([], listarSnapshotsBanco($this->dir));
        self::assertCount(1, listarSnapshotsBanco($this->dir, PREFIXO_SNAPSHOT_PRE_DEPLOY));
    }

    public function testBackupCliRecusaArgumentoAntigoEDestinoNoCheckout(): void
    {
        $script = dirname(__DIR__, 2) . '/scripts/deploy/db_backup.php';
        $saida = ExecutorCli::executar($script, ['--banco=arquivo-antigo.db'], $this->ambiente());
        self::assertSame(1, $saida['codigo']);
        $ambiente = $this->ambiente();
        $ambiente['DEALNI_SNAPSHOTS_DIR'] = dirname(__DIR__, 2) . '/snapshots';
        self::assertSame(1, ExecutorCli::executar($script, [], $ambiente)['codigo']);
        self::assertSame([], listarSnapshotsBanco($this->dir));
    }

    public function testSnapshotCliEmiteResultadoEFalhaSemOcultarErro(): void
    {
        $script = dirname(__DIR__, 2) . '/processos/snapshot_banco.php';
        $saida = ExecutorCli::executar($script, [], $this->ambiente());
        self::assertSame(0, $saida['codigo'], $saida['stderr']);
        self::assertStringContainsString('Snapshot PostgreSQL:', $saida['stdout']);
        $ambiente = $this->ambiente();
        $ambiente['TEST_DATABASE_DSN'] = 'pgsql:host=/caminho-inexistente-chatbots;dbname=chatbots_test_ausente';
        $saida = ExecutorCli::executar($script, [], $ambiente);
        self::assertSame(1, $saida['codigo']);
        self::assertNotSame('', $saida['stderr']);
    }

    public function testRestoreCliRecusaSemConfirmacaoArquivoInvalidoEBancoOcupado(): void
    {
        $script = dirname(__DIR__, 2) . '/scripts/deploy/db_restore.php';
        $ambiente = $this->ambiente();
        self::assertSame(2, ExecutorCli::executar($script, [], $ambiente)['codigo']);
        $ambiente['DB_RESTORE_CONFIRM'] = 'dealnibot';
        self::assertSame(1, ExecutorCli::executar($script, [], $ambiente)['codigo']);
        self::assertSame(1, ExecutorCli::executar($script, ['--arquivo=' . $this->dir . '/ausente.dump'], $ambiente)['codigo']);
        $dump = executarSnapshotBanco($this->dsn, $this->dir, 1)['arquivo'];
        $saida = ExecutorCli::executar($script, ['--arquivo=' . $dump], $ambiente);
        self::assertSame(1, $saida['codigo']);
        self::assertStringContainsString('banco vazio', $saida['stderr']);
        self::assertSame('preservar', \ConsultaBanco::coluna(\BancoTeste::conectar($this->dsn), 'SELECT valor FROM marcador'));
    }

    public function testRestauracaoRealEmBancoDescartavelPreservaDadosEIds(): void
    {
        $base = getenv('CHATBOTS_PG_TEST_DSN');
        self::assertIsString($base);
        $administrador = \BancoTeste::conectar($base);
        $nome = 'chatbots_test_restore_' . bin2hex(random_bytes(10));
        $dsn = preg_replace('/dbname=chatbots_test_[a-z0-9_]+/', 'dbname=' . $nome, $base, 1);
        self::assertIsString($dsn);
        // Identificador gerado aqui, nunca recebido do produto ou do operador.
        $administrador->exec('CREATE DATABASE ' . $nome);
        try {
            $saida = ExecutorCli::executar(dirname(__DIR__, 2) . '/scripts/quality/check_restore_postgres.php', [], ['CHATBOTS_PG_TEST_DSN' => $dsn]);
            self::assertSame(0, $saida['codigo'], $saida['stderr']);
            self::assertStringContainsString('OK (dados sintéticos)', $saida['stdout']);
        } finally {
            $administrador->exec('DROP DATABASE ' . $nome . ' WITH (FORCE)');
        }
    }
}
