<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Saúde (`/health`) separada de prontidão (`/ready`), como manda a seção 4 do
 * contrato de observabilidade.
 *
 * A separação não é cerimônia: `/health` responde "o processo está vivo" SEM
 * tocar em dependência nenhuma, porque uma sonda de liveness que consulta o
 * Redis derruba o processo junto com o Redis — o supervisor mata um serviço que
 * estava perfeitamente capaz de responder assim que a dependência voltasse.
 * `/ready` é o oposto: ele existe justamente para dizer "estou de pé, mas não
 * atendo", e por isso checa cada dependência real.
 *
 * Nenhuma das duas exige autenticação, então nenhuma das duas pode devolver
 * detalhe interno: `checks` é um mapa de `ok`/`erro` e mais nada. Sem string de
 * conexão, sem host, sem versão de dependência, sem stack — a mensagem do erro
 * fica no log, onde só quem tem o host lê.
 *
 * As dependências chegam como closures em vez de serem consultadas aqui: as
 * dependências reais de cada bot moram em funções procedurais de `inclusos/`
 * (aqui, `inclusos/funcoes_sondas.php`), e `src/` não depende de `inclusos/`.
 * Dois efeitos, os dois desejados: a decisão de status fica testável sem banco
 * nem Redis, e este arquivo não carrega nenhum valor de produto — a lista de
 * dependências, que é o único valor por bot, mora do lado de fora.
 */
final class Sonda
{
    /**
     * Teto por dependência. O contrato pede ≤ 2 s; quem consulta `/ready` está
     * decidindo se manda tráfego, e uma sonda que pendura é pior que uma que
     * reprova.
     */
    public const TIMEOUT_SEGUNDOS = 2.0;

    /**
     * Liveness: nenhuma dependência, nenhuma I/O, sempre 200.
     *
     * @return array{status: int, corpo: array<string, mixed>}
     */
    public static function vivo(): array
    {
        return [
            'status' => 200,
            'corpo' => [
                'status' => 'ok',
                'service' => ContextoDeExecucao::servico(),
                'version' => ContextoDeExecucao::versao(),
            ],
        ];
    }

    /**
     * Readiness: 200 só quando TODAS as dependências respondem.
     *
     * A closure que lança é tratada como `erro` em vez de subir: uma sonda que
     * devolve 500 por exceção não distingue "dependência caiu" de "a sonda está
     * quebrada", e as duas exigem ações diferentes de quem está de plantão.
     *
     * @param array<string, callable(): bool> $dependencias
     * @return array{status: int, corpo: array<string, mixed>}
     */
    public static function pronto(array $dependencias): array
    {
        $checks = [];
        foreach ($dependencias as $nome => $sonda) {
            $checks[$nome] = self::checar($sonda) ? 'ok' : 'erro';
        }

        $tudoOk = !in_array('erro', $checks, true);

        return [
            'status' => $tudoOk ? 200 : 503,
            'corpo' => [
                'status' => $tudoOk ? 'ok' : 'erro',
                'service' => ContextoDeExecucao::servico(),
                'version' => ContextoDeExecucao::versao(),
                'checks' => $checks,
            ],
        ];
    }

    /**
     * @param callable(): bool $sonda
     */
    private static function checar(callable $sonda): bool
    {
        try {
            return $sonda() === true;
        } catch (Throwable $erro) {
            return false;
        }
    }

    /**
     * Escreve a resposta HTTP: status, JSON e o `X-Request-Id` do ciclo.
     *
     * `Cache-Control: no-store` porque uma sonda respondida do cache é uma
     * sonda que mente — e mente exatamente durante o incidente.
     *
     * @param array{status: int, corpo: array<string, mixed>} $resposta
     */
    public static function responder(array $resposta): void
    {
        if (!headers_sent()) {
            http_response_code($resposta['status']);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo json_encode(
            $resposta['corpo'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ), "\n";
    }
}
