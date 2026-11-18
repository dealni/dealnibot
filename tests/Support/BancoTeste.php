<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/inclusos/banco.php';

/** PostgreSQL descartável: cada identificador de fixture recebe um schema isolado. */
final class BancoTeste
{
    /** @var array<string, string> */
    private static array $schemas = [];
    private static ?PDO $administrador = null;
    /** @var array<string, int> Schemas criados por este processo, nunca os emprestados. */
    private static array $proprietarios = [];

    public static function dsn(?string $identificador = null): string
    {
        $base = getenv('CHATBOTS_PG_TEST_DSN');
        if (!is_string($base) || preg_match('/\bdbname=chatbots_test_[a-z0-9_]+(?:;|$)/', $base) !== 1) {
            throw new RuntimeException('Banco de teste exige um DSN chatbots_test_*.');
        }
        if (is_string($identificador) && str_starts_with($identificador, 'pgsql:')) {
            if ($identificador !== $base && preg_match('~^' . preg_quote($base, '~') . ';options=--search_path=teste_[a-f0-9]{32}$~', $identificador) !== 1) {
                throw new RuntimeException('DSN fora do cluster de testes autorizado.');
            }
            return $identificador;
        }
        if (getenv('APP_ENV') !== 'test' || getenv('CHATBOTS_TESTING') !== '1') {
            throw new RuntimeException('Banco de teste exige APP_ENV=test e CHATBOTS_TESTING=1.');
        }
        $identificador = $identificador === null || $identificador === ':memory:' || $identificador === 'memory'
            ? bin2hex(random_bytes(16)) : $identificador;
        $schema = 'teste_' . substr(hash('sha256', $identificador), 0, 32);
        if (self::$administrador === null) {
            self::$administrador = new PDO($base, self::credencial('DEALNI_DB_USER'), self::credencial('DEALNI_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $nome = ConsultaBanco::coluna(self::$administrador, 'SELECT current_database()');
            if (!is_string($nome) || !str_starts_with($nome, 'chatbots_test_')) {
                throw new RuntimeException('Banco de teste não autorizado.');
            }
            register_shutdown_function(self::limpar(...));
        }
        if (!isset(self::$schemas[$schema])) {
            try {
                self::$administrador->exec('CREATE SCHEMA ' . $schema);
                self::$proprietarios[$schema] = (int) getmypid();
            } catch (PDOException $erro) {
                // Outro processo pode compartilhar um identificador explícito.
                if ($erro->getCode() !== '42P06') {
                    throw $erro;
                }
            }
            self::$schemas[$schema] = $base . ';options=--search_path=' . $schema;
        }
        return self::$schemas[$schema];
    }

    /** Remove somente fixtures criadas por este processo no cluster já validado. */
    public static function limpar(): void
    {
        foreach (self::$proprietarios as $schema => $pid) {
            if ($pid !== getmypid()) {
                continue;
            }
            self::$administrador?->exec('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
            unset(self::$proprietarios[$schema], self::$schemas[$schema]);
        }
    }

    /** @param array<int, mixed> $opcoes */
    public static function conectar(?string $identificador = null, mixed $usuario = null, mixed $senha = null, array $opcoes = []): PDO
    {
        return new BancoPostgres(self::dsn($identificador), self::credencial('DEALNI_DB_USER'), self::credencial('DEALNI_DB_PASSWORD'));
    }
    public static function executarDDL(object $banco, string $sql): bool
    {
        $con = (new ReflectionProperty($banco, 'con'))->getValue($banco);
        if (!$con instanceof PDO) {
            throw new RuntimeException('Fixture sem conexão PDO.');
        }
        return $con->exec($sql) !== false;
    }
    private static function credencial(string $nome): ?string
    {
        $valor = getenv($nome);
        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
