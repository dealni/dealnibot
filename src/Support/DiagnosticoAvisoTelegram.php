<?php

declare(strict_types=1);

namespace App\Support;

/** Metadados do aviso perdido; nunca registra corpo, descrição, URL ou destino. */
final class DiagnosticoAvisoTelegram
{
    /** @param list<string> $cabecalhos
     * @return array<string, int|string>|null */
    public static function resumir(string|false $resposta, array $cabecalhos, string $erro): ?array
    {
        $http = 0;
        foreach ($cabecalhos as $cabecalho) {
            if (preg_match('/^HTTP\/\S+ ([0-9]{3})\b/', $cabecalho, $partes) === 1) {
                $http = (int) $partes[1];
            }
        }
        $dados = is_string($resposta) ? json_decode($resposta, true) : null;
        if ($http === 200 && is_array($dados) && ($dados['ok'] ?? false) === true) {
            return null;
        }
        $resumo = ['evento' => 'aviso_telegram_nao_entregue', 'http_status' => $http,
            'motivo' => self::motivo($resposta, $dados, $erro)];
        return $resumo + self::metadados($dados);
    }

    /** @return array<string, int> */
    private static function metadados(mixed $dados): array
    {
        $resumo = [];
        if (is_array($dados)) {
            $codigo = $dados['error_code'] ?? null;
            if (is_int($codigo) && $codigo >= 100 && $codigo <= 599) {
                $resumo['telegram_error_code'] = $codigo;
            }
            $parametros = $dados['parameters'] ?? null;
            $espera = is_array($parametros) ? ($parametros['retry_after'] ?? null) : null;
            if (is_int($espera) && $espera > 0 && $espera <= 86400) {
                $resumo['retry_after'] = $espera;
            }
        }
        return $resumo;
    }

    private static function motivo(string|false $resposta, mixed $dados, string $erro): string
    {
        if ($resposta !== false) {
            return is_array($dados) ? 'recusa_telegram' : 'resposta_invalida';
        }
        if (preg_match('/timed? out|timeout/i', $erro) === 1) {
            return 'timeout';
        }
        if (preg_match('/ssl|tls|certificate|crypto/i', $erro) === 1) {
            return 'tls';
        }
        if (preg_match('/getaddrinfo|name resolution|resolve|name or service/i', $erro) === 1) {
            return 'dns';
        }
        return 'transporte';
    }
}
