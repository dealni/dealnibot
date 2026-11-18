<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class IndicesOperacionaisTest extends TestCase
{
    public function testMigrationPodeSerRepetidaESuportaOsPlanosDeConsulta(): void
    {
        $pdo = \BancoTeste::conectar();
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));
        $pdo->exec('DROP INDEX idx_webhook_inbox_retencao_processados');
        $pdo->exec('DROP INDEX idx_webhook_inbox_retencao_erros');

        $migration = (string) file_get_contents(__DIR__ . '/../../database/postgresql/002_indices_operacionais.sql');
        $pdo->exec($migration);
        $pdo->exec($migration);
        $pdo->exec("INSERT INTO webhook_inbox (update_id,payload_json,status,recebido_em,disponivel_em,processado_em)
            SELECT n,'{}','processado',n,n,n FROM generate_series(1,5000) n");
        $pdo->exec('ANALYZE webhook_inbox');
        $plano = $pdo->query("EXPLAIN SELECT update_id FROM webhook_inbox WHERE status='processado' AND processado_em<100 ORDER BY processado_em,update_id LIMIT 10");
        self::assertNotFalse($plano);
        self::assertStringContainsString('idx_webhook_inbox_retencao_processados', json_encode($plano->fetchAll(\PDO::FETCH_COLUMN), JSON_THROW_ON_ERROR));

        self::assertSame(5000, (int) \ConsultaBanco::coluna($pdo, 'SELECT count(*) FROM webhook_inbox'));
    }
}
