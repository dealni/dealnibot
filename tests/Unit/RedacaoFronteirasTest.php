<?php

declare(strict_types=1);

namespace Tests;

use App\Support\ProcessadorDeRedacao;
use PHPUnit\Framework\TestCase;

final class RedacaoFronteirasTest extends TestCase
{
    public function testFalhaDoSerializadorNuncaDevolveObjetoComSegredo(): void
    {
        $objeto = new class () implements \JsonSerializable {
            public string $token = 'segredo-da-fixture';
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException($this->token);
            }
        };
        self::assertSame(['corpo' => $objeto::class], ProcessadorDeRedacao::redigirEstrutura(['corpo' => $objeto]));
    }

    public function testFalhaDoStringableNuncaVazaMensagemDaExcecao(): void
    {
        $objeto = new class () implements \Stringable {
            public function __toString(): string
            {
                throw new \RuntimeException('segredo-da-fixture');
            }
        };
        self::assertSame(['corpo' => $objeto::class], ProcessadorDeRedacao::redigirEstrutura(['corpo' => $objeto]));
    }

    public function testSerializadorQueRetornaASiMesmoTemRecursaoLimitada(): void
    {
        $objeto = new class () implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return $this;
            }
        };
        $resultado = ProcessadorDeRedacao::redigirEstrutura(['corpo' => $objeto]);
        self::assertSame(['corpo' => $objeto::class], $resultado);
    }

    public function testDataEThrowableMantemContratoSemCaminhoOuPilha(): void
    {
        $data = new \DateTimeImmutable('2026-09-13T00:00:00Z');
        $erro = new \RuntimeException('falha sintética');
        $resultado = ProcessadorDeRedacao::redigirEstrutura(['data' => $data, 'erro' => $erro]);
        self::assertSame('2026-09-13T00:00:00+00:00', $resultado['data']);
        self::assertIsArray($resultado['erro']);
        self::assertSame(\RuntimeException::class, $resultado['erro']['class']);
        self::assertSame('falha sintética', $resultado['erro']['message']);
        self::assertArrayNotHasKey('file', $resultado['erro']);
        self::assertArrayNotHasKey('trace', $resultado['erro']);
    }

    public function testProfundidadeExcessivaNaoVisitaGettersNemConteudoInterno(): void
    {
        self::assertSame([], ProcessadorDeRedacao::redigirEstrutura(['token' => 'segredo-da-fixture'], 100));
        self::assertSame('{"ok":true,"token":"' . ProcessadorDeRedacao::SUBSTITUTO . '"}', ProcessadorDeRedacao::redigirTexto('{"ok":true,"token":"segredo-da-fixture"}'));
    }
}
