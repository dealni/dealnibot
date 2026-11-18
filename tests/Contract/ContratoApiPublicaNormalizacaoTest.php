<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ContratoApiPublica;

final class ContratoApiPublicaNormalizacaoTest extends TestCase
{
    public function testDetectaMudancasDeAssinaturaSemAlterarONomeDoMetodo(): void
    {
        $original = new class () {
            public function enviar(string $texto, int $tentativas = 1): string
            {
                return $texto;
            }
        };
        $alteracoes = [
            'ordem' => new class () {
                public function enviar(int $tentativas, string $texto = ''): string
                {
                    return $texto;
                }
            },
            'nome do parâmetro' => new class () {
                public function enviar(string $mensagem, int $tentativas = 1): string
                {
                    return $mensagem;
                }
            },
            'tipo do parâmetro' => new class () {
                public function enviar(?string $texto, int $tentativas = 1): string
                {
                    return $texto ?? '';
                }
            },
            'default' => new class () {
                public function enviar(string $texto, int $tentativas = 2): string
                {
                    return $texto;
                }
            },
            'parâmetro obrigatório' => new class () {
                public function enviar(string $texto, int $tentativas): string
                {
                    return $texto;
                }
            },
            'variadic' => new class () {
                public function enviar(string $texto, int ...$tentativas): string
                {
                    return $texto;
                }
            },
            'referência do parâmetro' => new class () {
                public function enviar(string &$texto, int $tentativas = 1): string
                {
                    return $texto;
                }
            },
            'retorno' => new class () {
                public function enviar(string $texto, int $tentativas = 1): ?string
                {
                    return $texto === '' ? null : $texto;
                }
            },
            'referência do retorno' => new class () {
                public function &enviar(string $texto, int $tentativas = 1): string
                {
                    return $texto;
                }
            },
            'static' => new class () {
                public static function enviar(string $texto, int $tentativas = 1): string
                {
                    return $texto;
                }
            },
        ];
        $esperado = ContratoApiPublica::extrair(new ReflectionClass($original));
        $this->assertSame(['enviar' => 'enviar(string $texto, int $tentativas = 1): string'], $esperado);
        foreach ($alteracoes as $motivo => $alteracao) {
            $atual = ContratoApiPublica::extrair(new ReflectionClass($alteracao));
            $this->assertSame(array_keys($esperado), array_keys($atual), $motivo);
            $this->assertNotSame($esperado, $atual, $motivo);
        }
    }

    public function testNormalizaTiposCompostosEValoresDefaultSemPerderInformacao(): void
    {
        $exemplo = new class () {
            /** @param array<string, mixed> $opcoes */
            public function exemplo((\Countable&\Iterator)|null $objeto, array $opcoes = [], bool|string $pay = false, string $texto = '', mixed $valor = null, int $modo = \PHP_INT_MAX): void
            {
            }
        };
        $this->assertSame([
            'exemplo' => 'exemplo((Countable&Iterator)|null $objeto, array $opcoes = [], bool|string $pay = false, string $texto = "", mixed $valor = null, int $modo = ' . PHP_INT_MAX . ' [PHP_INT_MAX]): void',
        ], ContratoApiPublica::extrair(new ReflectionClass($exemplo)));
    }
}
