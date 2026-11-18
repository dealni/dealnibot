<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require_once dirname(__DIR__, 2) . '/inclusos/banco.php';
require_once dirname(__DIR__, 2) . '/inclusos/funcoes_snapshot_banco.php';
try {
    $argv = ConsultaBanco::argumentos();
    if (getenv('DB_RESTORE_CONFIRM') !== 'dealnibot') {
        fwrite(STDERR, 'Restauração não iniciada. Use DB_RESTORE_CONFIRM=dealnibot e aponte o DSN para um banco PostgreSQL vazio.' . PHP_EOL);
        exit(2);
    }
    if (count($argv) !== 2 || !str_starts_with($argv[1], '--arquivo=')) {
        throw new RuntimeException('Uso: db_restore.sh --arquivo=/caminho/backup.dump');
    }
    $arquivo = substr($argv[1], 10);
    if (!snapshotBancoIntegro($arquivo)) {
        throw new RuntimeException('Arquivo ou metadados de backup inválidos.');
    }
    $pdo = conectarBancoDoBot();
    if ((int) ConsultaBanco::coluna($pdo, "SELECT count(*) FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')") !== 0) {
        throw new RuntimeException('Restauração exige banco vazio; não sobrescreve dados existentes.');
    }
    executarUtilitarioPostgres(['pg_restore', '--dbname=' . str_replace(';', ' ', substr(dsnBancoDoBot(), 6)), '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error', $arquivo], dsnBancoDoBot());
    echo "Restauração PostgreSQL concluída.\n";
} catch (Throwable $erro) {
    fwrite(STDERR, $erro->getMessage() . PHP_EOL);
    exit(1);
}
