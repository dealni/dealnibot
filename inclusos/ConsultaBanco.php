<?php

declare(strict_types=1);

/** Contrato tipado para os resultados PDO usados nas ferramentas de migração. */
final class ConsultaBanco
{
    public static function query(PDO $pdo, string $sql): PDOStatement
    {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('Consulta ao banco falhou.');
        }
        return $stmt;
    }

    /** @return array<string, scalar|null>|null */
    public static function linha(PDOStatement $stmt): ?array
    {
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : self::linhaEscalar($linha);
    }

    /**
     * Recebe `mixed` de propósito. O PHPStan tipa fetch(PDO::FETCH_ASSOC) como
     * array<string, mixed>, mas em runtime o PHP transforma nome de coluna
     * numérico (`SELECT 1 AS "123"`) em chave int, e um driver ou dublê pode
     * devolver algo que nem é array. A conferência vale para o que chega do
     * banco, não para o que o stub promete.
     *
     * @return array<string, scalar|null>
     */
    private static function linhaEscalar(mixed $linha): array
    {
        if (!is_array($linha)) {
            throw new RuntimeException('Linha do banco inválida.');
        }
        $resultado = [];
        foreach ($linha as $nome => $valor) {
            if (!is_string($nome) || (!is_scalar($valor) && $valor !== null)) {
                throw new RuntimeException('Valor do banco fora do contrato escalar.');
            }
            $resultado[$nome] = $valor;
        }
        return $resultado;
    }

    /** @return list<array<string, scalar|null>> */
    public static function linhas(PDO $pdo, string $sql): array
    {
        $stmt = self::query($pdo, $sql);
        $linhas = [];
        while (($linha = self::linha($stmt)) !== null) {
            $linhas[] = $linha;
        }
        return $linhas;
    }

    public static function coluna(PDO $pdo, string $sql): string|int|float|bool|null
    {
        $linha = self::query($pdo, $sql)->fetch(PDO::FETCH_NUM);
        $valor = is_array($linha) ? ($linha[0] ?? null) : false;
        if (!is_scalar($valor) && $valor !== null) {
            throw new RuntimeException('Coluna do banco inválida.');
        }
        return $valor;
    }

    /** @return list<string> */
    public static function nomes(PDO $pdo, string $sql): array
    {
        $stmt = self::query($pdo, $sql);
        $nomes = [];
        while (($valor = $stmt->fetchColumn()) !== false) {
            if (!is_string($valor)) {
                throw new RuntimeException('Nome do banco inválido.');
            }
            $nomes[] = $valor;
        }
        return $nomes;
    }

    /** @return array<string, string> */
    public static function mapa(PDO $pdo, string $sql): array
    {
        return self::mapaStatement(self::query($pdo, $sql));
    }

    /** @return array<string, string> */
    public static function mapaStatement(PDOStatement $stmt): array
    {
        $mapa = [];
        while (($linha = self::linha($stmt)) !== null) {
            $valores = array_values($linha);
            if (count($valores) !== 2 || !is_string($valores[0])) {
                throw new RuntimeException('Mapa do banco inválido.');
            }
            $mapa[$valores[0]] = (string) $valores[1];
        }
        return $mapa;
    }

    /** @return list<string> */
    public static function argumentos(): array
    {
        $brutos = $_SERVER['argv'] ?? [];
        if (!is_array($brutos)) {
            throw new RuntimeException('Argumentos CLI inválidos.');
        }
        $args = [];
        foreach ($brutos as $arg) {
            if (!is_string($arg)) {
                throw new RuntimeException('Argumento CLI inválido.');
            }
            $args[] = $arg;
        }
        return $args;
    }

    /** @return list<string> */
    public static function arquivos(string $padrao): array
    {
        $arquivos = glob($padrao);
        if ($arquivos === false) {
            throw new RuntimeException('Não foi possível listar migrations.');
        }
        sort($arquivos, SORT_STRING);
        return $arquivos;
    }
}
