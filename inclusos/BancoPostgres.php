<?php

declare(strict_types=1);

/** PDO PostgreSQL para o contrato SQL legado dos bots. */
final class BancoPostgres extends \Pdo\Pgsql
{
    public function __construct(string $dsn, ?string $usuario, ?string $senha)
    {
        parent::__construct($dsn, $usuario, $senha, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Produção usa public; um search_path explícito no DSN permite fixtures isoladas.
        if (preg_match('/(?:^|;)options=[^;]*search_path=/', $dsn) !== 1) {
            parent::exec('SET search_path TO public');
        }
        parent::exec("SET default_transaction_isolation TO 'read committed'; SET timezone TO 'UTC'; SET lock_timeout TO '30s'; SET statement_timeout TO '60s'; SET idle_in_transaction_session_timeout TO '60s'");
    }

    /**
     * Preserva o contrato BEGIN IMMEDIATE: leituras que decidem saldo/lease
     * acontecem depois de escritores anteriores. Locks de tabela incluem
     * também UPDATEs avulsos, que não participariam de um advisory lock.
     * Leitores continuam livres. Cada bot tem seu próprio banco/usuário.
     *
     * `webhook_inbox` fica de fora. Ninguém a lê nem escreve dentro de
     * transação: registro, lease, confirmação e retenção são comandos únicos
     * (a lease é um UPDATE que reconfere o status na própria linha), e hoje o
     * único chamador deste método é o runner de migrations. Com a inbox no
     * lock, a migration inteira (ou a espera dela atrás de um VACUUM manual,
     * que enfileira os escritores seguintes) parava o `INSERT` do webhook até
     * o `lock_timeout`, e o Telegram recebia 503. Uma migration que altere a
     * inbox continua serializada pelo próprio DDL, que toma `ACCESS EXCLUSIVE`.
     */
    public function beginTransaction(): bool
    {
        $iniciada = parent::beginTransaction();
        try {
            // format(%I) cita cada identificador no servidor; nenhum nome
            // de tabela vira interpolação SQL no PHP.
            parent::exec(<<<'SQL'
                DO $locks$
                DECLARE tabelas text;
                BEGIN
                    SELECT string_agg(format('%I.%I', schemaname, tablename), ', ' ORDER BY tablename)
                      INTO tabelas FROM pg_catalog.pg_tables
                     WHERE schemaname = current_schema() AND tablename <> 'webhook_inbox';
                    IF tabelas IS NOT NULL THEN
                        EXECUTE 'LOCK TABLE ' || tabelas || ' IN SHARE ROW EXCLUSIVE MODE';
                    END IF;
                END;
                $locks$
                SQL);
        } catch (Throwable $erro) {
            parent::rollBack();
            throw $erro;
        }
        return $iniciada;
    }

    public function exec(string $statement): int|false
    {
        if (preg_match('/^\s*BEGIN(?:\s+IMMEDIATE)?(?:\s+TRANSACTION)?\s*;?\s*$/i', $statement) === 1) {
            $this->beginTransaction();
            return 0;
        }
        return parent::exec(SqlPostgres::converter($statement));
    }

    /** @param array<mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(SqlPostgres::converter($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return parent::query(SqlPostgres::converter($query), $fetchMode, ...$fetchModeArgs);
    }
}
