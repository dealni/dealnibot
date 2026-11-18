<?php

declare(strict_types=1);

namespace Tests;

use App\Support\ReciboRecuperacao;
use PHPUnit\Framework\TestCase;

final class ReciboRecuperacaoTest extends TestCase
{
    public function testSinteticoNaoCertificaProducaoOuOffsite(): void
    {
        $recibo = ReciboRecuperacao::sintetico('/privado/fixture.dump', str_repeat('a', 64), 'chatbots_test_fixture');
        self::assertSame('fixture.dump', $recibo['conjunto']['identificador']);
        self::assertSame('sintetica', $recibo['restauracao']['origemDados']);
        self::assertFalse($recibo['restauracao']['conjuntoProducaoRestaurado']);
        self::assertSame('nao_verificado_neste_ensaio', $recibo['integridade']['transporteOffsite']);
        self::assertSame('nao_ensaiado', $recibo['escopo']['redis']);
    }

    public function testRecusaBancoNaoSintetico(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReciboRecuperacao::sintetico('fixture', str_repeat('a', 64), 'producao');
    }

    public function testRecusaHashInvalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReciboRecuperacao::sintetico('fixture', 'invalido', 'chatbots_test_fixture');
    }
}
