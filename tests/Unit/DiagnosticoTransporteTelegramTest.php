<?php

declare(strict_types=1);

namespace Tests;

use App\Support\DiagnosticoTransporteTelegram;
use App\Support\ResumoTelegram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiagnosticoTransporteTelegramTest extends TestCase
{
    public function testResumoConverteTemposCumulativosSemInferirEntrega(): void
    {
        self::assertSame([
            'curl_dns_ms' => 0,
            'curl_conexao_ms' => 12,
            'curl_tls_ms' => 47,
            'curl_primeiro_byte_ms' => 123,
            'curl_total_ms' => 15000,
            'curl_http_status' => 200,
        ], DiagnosticoTransporteTelegram::resumir([
            'namelookup_time' => 0,
            'connect_time' => 0.012,
            'appconnect_time' => 0.047,
            'starttransfer_time' => 0.123,
            'total_time' => 15,
            'http_code' => 200,
        ]));
    }

    /** @return array<string, array{mixed}> */
    public static function temposInvalidos(): array
    {
        return [
            'negativo' => [-0.001], 'texto' => ['0.2'], 'booleano' => [false],
            'infinito' => [INF], 'nao numerico' => [NAN], 'fora do teto' => [3600.001],
            'array' => [[]], 'objeto' => [new \stdClass()], 'nulo' => [null],
        ];
    }

    #[DataProvider('temposInvalidos')]
    public function testMetadadoInvalidoOmitidoSemFabricarZero(mixed $valor): void
    {
        self::assertSame([], DiagnosticoTransporteTelegram::resumir(array_fill_keys([
            'namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'total_time',
        ], $valor)));
    }

    public function testMetadadoAusenteOuParcialNaoViraAfirmacaoDeFase(): void
    {
        self::assertSame([], DiagnosticoTransporteTelegram::resumir([]));
        self::assertSame(['curl_total_ms' => 10000], DiagnosticoTransporteTelegram::resumir(['total_time' => 10]));
        self::assertSame(['curl_total_ms' => 3600000], DiagnosticoTransporteTelegram::resumir(['total_time' => 3600]));
    }

    /** @return array<string, array{mixed, array<string, int>}> */
    public static function codigosHttp(): array
    {
        return [
            'nao observado' => [0, ['curl_http_status' => 0]],
            'inicio' => [100, ['curl_http_status' => 100]],
            'sucesso observado' => [200, ['curl_http_status' => 200]],
            'limite' => [599, ['curl_http_status' => 599]],
            'errno nao e http' => [28, []], 'negativo' => [-1, []], 'fora' => [600, []],
            'texto' => ['200', []], 'float' => [200.0, []], 'array' => [[], []],
        ];
    }

    /** @param array<string, int> $esperado */
    #[DataProvider('codigosHttp')]
    public function testHttpObservadoTemTipoEDominioProprios(mixed $codigo, array $esperado): void
    {
        self::assertSame($esperado, DiagnosticoTransporteTelegram::resumir(['http_code' => $codigo]));
    }

    public function testAllowlistNaoCarregaUrlIpCabecalhoOuConteudo(): void
    {
        $dados = ['total_time' => 0.001, 'http_code' => 0, 'url' => 'TOKEN_SECRETO',
            'primary_ip' => 'IP_SECRETO', 'request_header' => 'AUTH_SECRETO', 'content' => 'CONTEUDO_SECRETO'];
        self::assertSame(['curl_total_ms' => 1, 'curl_http_status' => 0], DiagnosticoTransporteTelegram::resumir($dados));
        self::assertSame([], DiagnosticoTransporteTelegram::filtrar($dados));
        self::assertSame(['curl_total_ms' => 1], ResumoTelegram::contexto([
            'curl_total_ms' => 1, 'curl_tls_ms' => INF, 'curl_dns_ms' => -1,
            'curl_primeiro_byte_ms' => 3600001, 'curl_conexao_ms' => '5',
            'curl_http_status' => '200', 'url' => 'TOKEN_SECRETO', 'curl_error' => 'CONTEUDO_SECRETO',
        ]));
        self::assertSame(['ok' => false], ResumoTelegram::resposta(['ok' => false, 'curl_total_ms' => 15000, 'curl_http_status' => 200]));
    }
}
