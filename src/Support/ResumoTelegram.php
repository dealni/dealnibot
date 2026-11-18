<?php

declare(strict_types=1);

namespace App\Support;

/** Metadados úteis para diagnóstico; conteúdo, entidades e mídia ficam fora do log. */
final class ResumoTelegram
{
    public static function descricao(string $descricao): string
    {
        $limpa = preg_replace('/[\x00-\x1F\x7F]/u', ' ', mb_scrub($descricao, 'UTF-8')) ?? 'erro indisponível';
        return mb_substr($limpa, 0, 500, 'UTF-8');
    }

    /** @param array<mixed> $resposta
     * @return array<string, bool|int|string>
     */
    public static function resposta(array $resposta): array
    {
        $resumo = [];
        foreach (['ok', 'description'] as $campo) {
            $valor = $resposta[$campo] ?? null;
            if (self::escalar($valor)) {
                $resumo[$campo] = is_string($valor) ? self::descricao($valor) : $valor;
            }
        }
        $codigo = self::codigo($resposta['error_code'] ?? null);
        if ($codigo !== null) {
            $resumo['error_code'] = $codigo;
        }
        $resumo += self::inteiroAninhado($resposta, 'result', 'message_id');
        $resumo += self::inteiroAninhado($resposta, 'parameters', 'retry_after');
        return $resumo;
    }

    public static function codigo(mixed $valor): int|string|null
    {
        return is_int($valor) || (is_string($valor) && preg_match('/^[0-9]{1,10}$/D', $valor) === 1)
            ? $valor : null;
    }

    /** @param array<mixed> $resposta
     * @return array<string, int>
     */
    private static function inteiroAninhado(array $resposta, string $bloco, string $campo): array
    {
        $objeto = $resposta[$bloco] ?? null;
        $valor = is_array($objeto) ? ($objeto[$campo] ?? null) : null;
        return is_int($valor) ? [$campo => $valor] : [];
    }

    /** @phpstan-assert-if-true bool|int|string $valor */
    private static function escalar(mixed $valor): bool
    {
        return is_bool($valor) || is_int($valor) || is_string($valor);
    }

    /** @param array<mixed> $contexto
     * @return array<string, int|string|list<string>>
     */
    public static function contexto(array $contexto): array
    {
        $resumo = [];
        foreach (['method', 'metodo', 'chat_id', 'message_id', 'update_id', 'attempt', 'job_id'] as $campo) {
            $valor = $contexto[$campo] ?? null;
            if (is_int($valor) || is_string($valor)) {
                $resumo[$campo] = is_string($valor) ? mb_substr($valor, 0, 128) : $valor;
            }
        }
        if (is_array($contexto['campos'] ?? null)) {
            $resumo['campos'] = array_slice(array_values(array_filter($contexto['campos'], static fn (mixed $campo): bool => is_string($campo) && preg_match('/^[a-z_]{1,64}$/', $campo) === 1)), 0, 32);
        }
        return $resumo + DiagnosticoTransporteTelegram::filtrar($contexto);
    }
}
