<?php

declare(strict_types=1);

namespace App\Support;

use CurlHandle;

/** Tempos cumulativos observados pelo cURL, sem inferir entrega ou causa. */
final class DiagnosticoTransporteTelegram
{
    private const TEMPOS = [
        'namelookup_time' => [CURLINFO_NAMELOOKUP_TIME, 'curl_dns_ms'],
        'connect_time' => [CURLINFO_CONNECT_TIME, 'curl_conexao_ms'],
        'appconnect_time' => [CURLINFO_APPCONNECT_TIME, 'curl_tls_ms'],
        'starttransfer_time' => [CURLINFO_STARTTRANSFER_TIME, 'curl_primeiro_byte_ms'],
        'total_time' => [CURLINFO_TOTAL_TIME, 'curl_total_ms'],
    ];

    /** @return array<string, int> */
    public static function capturar(CurlHandle $curl): array
    {
        $dados = ['http_code' => curl_getinfo($curl, CURLINFO_HTTP_CODE)];
        foreach (self::TEMPOS as $campo => [$opcao]) {
            $dados[$campo] = curl_getinfo($curl, $opcao);
        }
        return self::resumir($dados);
    }

    /** @param array<string, mixed> $dados
     * @return array<string, int> */
    public static function resumir(array $dados): array
    {
        $resumo = ['curl_http_status' => $dados['http_code'] ?? null];
        foreach (self::TEMPOS as $origem => [, $campo]) {
            $valor = $dados[$origem] ?? null;
            if ((is_int($valor) || is_float($valor)) && is_finite((float) $valor) && $valor >= 0 && $valor <= 3600) {
                $resumo[$campo] = (int) round($valor * 1000);
            }
        }
        return self::filtrar($resumo);
    }

    /** @param array<mixed> $contexto
     * @return array<string, int> */
    public static function filtrar(array $contexto): array
    {
        $resumo = [];
        foreach (self::TEMPOS as [, $campo]) {
            $valor = $contexto[$campo] ?? null;
            if (is_int($valor) && $valor >= 0 && $valor <= 3600000) {
                $resumo[$campo] = $valor;
            }
        }
        $http = $contexto['curl_http_status'] ?? null;
        if (is_int($http) && ($http === 0 || ($http >= 100 && $http <= 599))) {
            $resumo['curl_http_status'] = $http;
        }
        return $resumo;
    }
}
