-- Sobras da importação do SQLite: o controle de migrations antigo (hoje é
-- schema_migrations_postgresql) e o registro da importação. Nenhum código as lê.
DROP TABLE IF EXISTS schema_migrations;
DROP TABLE IF EXISTS importacao_sqlite;
