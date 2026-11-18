<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class PostgresExclusivoTest extends TestCase
{
    public function testConexoesNaoAceitamBancoEmArquivo(): void
    {
        $this->expectException(\RuntimeException::class);
        conectarBancoDoBot('/tmp/banco-nao-autorizado.db');
    }

    public function testDsnAusenteNaoAbreBancoLocal(): void
    {
        $anterior = getenv('DEALNI_DB_DSN');
        $teste = getenv('TEST_DATABASE_DSN');
        try {
            putenv('TEST_DATABASE_DSN');
            putenv('DEALNI_DB_DSN');
            $this->expectException(\RuntimeException::class);
            dsnBancoDoBot();
        } finally {
            putenv($anterior === false ? 'DEALNI_DB_DSN' : 'DEALNI_DB_DSN=' . $anterior);
            putenv('TEST_DATABASE_DSN=' . $teste);
        }
    }

    public function testCredenciaisNaoPodemEstarNoDsn(): void
    {
        $this->expectException(\RuntimeException::class);
        conectarBancoDoBot('pgsql:dbname=chatbots_test_fake;password=valor-sintetico');
    }

    public function testConexaoConfiguraTimeoutEReverteTransacao(): void
    {
        $pdo = \BancoTeste::conectar();
        self::assertSame('pgsql', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::assertSame('30s', \ConsultaBanco::coluna($pdo, 'SHOW lock_timeout'));
        $pdo->exec('CREATE TABLE prova (id BIGINT PRIMARY KEY)');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO prova VALUES (1)');
        $pdo->rollBack();
        self::assertSame(0, \ConsultaBanco::coluna($pdo, 'SELECT COUNT(*) FROM prova'));
    }

    public function testEscritoresDisputamLockESemTransacaoVazada(): void
    {
        $dsn = \BancoTeste::dsn();
        $primeiro = \BancoTeste::conectar($dsn);
        $segundo = \BancoTeste::conectar($dsn);
        $primeiro->exec('CREATE TABLE disputa (id BIGINT PRIMARY KEY)');
        $primeiro->beginTransaction();
        $segundo->exec("SET lock_timeout = '20ms'");
        try {
            $segundo->beginTransaction();
            self::fail('O segundo escritor ignorou o lock.');
        } catch (\PDOException $erro) {
            self::assertSame('55P03', $erro->getCode());
            self::assertFalse($segundo->inTransaction());
        } finally {
            $primeiro->rollBack();
        }
    }
    public function testTransacaoNaoTravaOWebhookNaInbox(): void
    {
        // O webhook grava na inbox ANTES do ACK. Com a inbox no LOCK TABLE, uma
        // migration em curso segurava esse INSERT até o lock_timeout e o
        // Telegram recebia 503; as demais tabelas continuam serializadas.
        $dsn = \BancoTeste::dsn();
        $migracao = \BancoTeste::conectar($dsn);
        $webhook = \BancoTeste::conectar($dsn);
        $migracao->exec('CREATE TABLE webhook_inbox (update_id BIGINT PRIMARY KEY)');
        $migracao->exec('CREATE TABLE usuarios (id BIGINT PRIMARY KEY)');
        $migracao->beginTransaction();
        $webhook->exec("SET lock_timeout = '20ms'");
        try {
            self::assertSame(1, $webhook->exec('INSERT INTO webhook_inbox VALUES (1)'));
            try {
                $webhook->exec('INSERT INTO usuarios VALUES (1)');
                self::fail('A escrita em outra tabela ignorou o lock da transação.');
            } catch (\PDOException $erro) {
                self::assertSame('55P03', $erro->getCode());
            }
        } finally {
            $migracao->rollBack();
        }
        self::assertSame(1, \ConsultaBanco::coluna($webhook, 'SELECT COUNT(*) FROM webhook_inbox'));
    }

    public function testResultadosNativosPreservamTiposENomes(): void
    {
        $pdo = \BancoTeste::conectar();
        self::assertSame(
            [['id' => 1, 'texto' => 'ação', 'opcional' => null], ['id' => 2, 'texto' => 'fim', 'opcional' => null]],
            \ConsultaBanco::linhas($pdo, "SELECT id, texto, NULL AS opcional FROM (VALUES (1, 'ação'), (2, 'fim')) AS dados(id, texto) ORDER BY id")
        );
        self::assertSame(['canais', 'grupos'], \ConsultaBanco::nomes($pdo, "SELECT nome FROM (VALUES ('canais'), ('grupos')) AS dados(nome) ORDER BY nome"));
        $this->expectException(\RuntimeException::class);
        \ConsultaBanco::nomes($pdo, 'SELECT 1');
    }

    public function testRelatorioRecusaFormatoTemporalNaoSuportado(): void
    {
        $this->expectException(\RuntimeException::class);
        \SqlPostgres::converter("SELECT strftime('%Y', criado_em) FROM eventos");
    }

}
