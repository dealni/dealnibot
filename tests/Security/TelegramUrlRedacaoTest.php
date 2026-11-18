<?php

declare(strict_types=1);

namespace Tests;

use App\Support\ProcessadorDeRedacao;
use PHPUnit\Framework\TestCase;

final class TelegramUrlRedacaoTest extends TestCase
{
    public function testTokenEmUrlNaoSobreviveEmTextoOuExcecao(): void
    {
        foreach ([':', '%3A', '%3a'] as $separador) {
            $url = 'https://api.telegram.org/file/bot123456' . $separador . 'SEGREDO_TESTE/arquivo.jpg';
            self::assertStringNotContainsString('SEGREDO_TESTE', ProcessadorDeRedacao::redigirTexto($url));
            $saida = json_encode(ProcessadorDeRedacao::redigirEstrutura(['erro' => new \RuntimeException($url)]));
            self::assertIsString($saida);
            self::assertStringNotContainsString('SEGREDO_TESTE', $saida);
        }
        self::assertSame('Arquivo não encontrado', ProcessadorDeRedacao::redigirTexto('Arquivo não encontrado'));
    }
}
