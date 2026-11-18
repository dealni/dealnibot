<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class TabelasLegadasSqliteTest extends TestCase
{
    public function testMigrationRemoveAsSobrasDaImportacaoSemTocarNoEstadoDoBot(): void
    {
        $pdo = \BancoTeste::conectar();
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));
        // Formato legado dos bancos SQLite.
        $pdo->exec('CREATE TABLE schema_migrations (nome TEXT PRIMARY KEY, sha256 TEXT NOT NULL, aplicada_em TEXT NOT NULL)');
        $pdo->exec("INSERT INTO schema_migrations VALUES ('20260825_consentimento_anuncios.sql', 'a', 'b'), ('20260904_webhook_inbox.sql', 'c', 'd')");
        $pdo->exec('CREATE TABLE importacao_sqlite (concluida_em TEXT NOT NULL, relatorio_json TEXT NOT NULL)');
        $pdo->exec("INSERT INTO importacao_sqlite VALUES ('2026-09-08', '{}')");
        $pdo->exec('INSERT INTO usuarios (id_telegram) VALUES (9876543210123)');
        $pdo->exec("INSERT INTO webhook_inbox (update_id, payload_json, recebido_em, disponivel_em) VALUES (1, '{}', 1, 1)");
        $pdo->exec("INSERT INTO broadcast_entregas (campanha, id_telegram, disponivel_em) VALUES ('c', 1, 1)");

        $migration = (string) file_get_contents(__DIR__ . '/../../database/postgresql/003_remove_tabelas_legadas_sqlite.sql');
        self::assertStringNotContainsString('CASCADE', $migration, 'Dependência inesperada deve abortar o deploy, não sumir junto.');
        $pdo->exec($migration);
        $pdo->exec($migration);

        $tabelas = $pdo->query('SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename');
        self::assertNotFalse($tabelas);
        self::assertSame(['broadcast_entregas', 'telegram_contatos', 'usuarios', 'webhook_inbox'], $tabelas->fetchAll(\PDO::FETCH_COLUMN));
        foreach (['usuarios', 'webhook_inbox', 'broadcast_entregas'] as $tabela) {
            self::assertSame(1, (int) \ConsultaBanco::coluna($pdo, 'SELECT count(*) FROM ' . $tabela), $tabela);
        }
    }
}
