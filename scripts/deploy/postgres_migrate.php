<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inclusos/banco.php';

/** @param list<string> $argumentos */
function migrarPostgres(array $argumentos): int
{
    foreach ($argumentos as $arg) {
        if (!in_array($arg, ['--status', '--dry-run'], true)) {
            throw new RuntimeException('PostgreSQL aceita --status ou --dry-run; bootstrap pelas migrations nativas.');
        }
    }
    $pdo = conectarBancoDoBot();
    $arquivos = ConsultaBanco::arquivos(dirname(__DIR__, 2) . '/database/postgresql/*.sql');
    sort($arquivos, SORT_STRING);
    $existe = ConsultaBanco::coluna($pdo, "SELECT to_regclass('public.schema_migrations_postgresql')") !== null;
    $aplicadas = $existe ? ConsultaBanco::mapa($pdo, 'SELECT nome, sha256 FROM schema_migrations_postgresql') : [];
    $esperadas = [];
    foreach ($arquivos as $arquivo) {
        $esperadas[basename($arquivo)] = hash_file('sha256', $arquivo);
    }
    foreach ($aplicadas as $nome => $hash) {
        if (($esperadas[$nome] ?? null) !== $hash) {
            throw new RuntimeException('Migration PostgreSQL removida ou alterada: ' . $nome);
        }
    }
    $pendentes = array_diff_key($esperadas, $aplicadas);
    if (in_array('--status', $argumentos, true) || in_array('--dry-run', $argumentos, true)) {
        printf("PostgreSQL: %d de %d migration(s) aplicadas.\n", count($aplicadas), count($esperadas));
        foreach ($esperadas as $nome => $hash) {
            echo (isset($aplicadas[$nome]) ? '[ok] ' : '[pendente] ') . $nome . PHP_EOL;
        }
        return in_array('--status', $argumentos, true) && $pendentes !== [] ? 1 : 0;
    }
    $pdo->beginTransaction();
    try {
        // Dois deploys no banco vazio também precisam se excluir.
        $pdo->query('SELECT pg_advisory_xact_lock(724019, 2)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations_postgresql (nome TEXT PRIMARY KEY, sha256 TEXT NOT NULL, aplicada_em TEXT NOT NULL)');
        $atuais = ConsultaBanco::mapa($pdo, 'SELECT nome, sha256 FROM schema_migrations_postgresql');
        foreach ($esperadas as $nome => $hash) {
            if (isset($atuais[$nome])) {
                if ($atuais[$nome] !== $hash) {
                    throw new RuntimeException('Hash PostgreSQL divergente: ' . $nome);
                }
                continue;
            }
            // Baseline executado pelo PDO nativo: não reaplicar o adaptador de DDL.
            $sql = file_get_contents(dirname(__DIR__, 2) . '/database/postgresql/' . $nome);
            if ($sql === false) {
                throw new RuntimeException('Não foi possível ler migration.');
            }
            $pdo->exec($sql);
            $pdo->prepare('INSERT INTO schema_migrations_postgresql VALUES (?, ?, ?)')->execute([$nome, $hash, gmdate('c')]);
        }
        $pdo->commit();
    } catch (Throwable $erro) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $erro;
    }
    echo 'Migrations PostgreSQL confirmadas.' . PHP_EOL;
    return 0;
}
