<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ContratoApiPublica;

require_once dirname(__DIR__, 2) . '/inclusos/Telegram.php';

final class TelegramApiPublicaTest extends TestCase
{
    public function testAssinaturasPublicasCorrespondemAoSnapshotDoProduto(): void
    {
        $arquivo = file_get_contents(__DIR__ . '/../Fixtures/telegram-api-publica.json');
        $this->assertIsString($arquivo);
        $esperado = json_decode($arquivo, true, 512, JSON_THROW_ON_ERROR);
        $atual = ContratoApiPublica::extrair(new ReflectionClass(\Telegram::class));

        $this->assertCount(228, $atual);
        $this->assertSame($esperado, $atual, 'A assinatura pública mudou: revise a compatibilidade antes de atualizar o snapshot do produto.');
    }
    public function testComposicaoUsaTraitsDoRepositorioPeloAutoloadPsr4(): void
    {
        $reflexao = new ReflectionClass(\Telegram::class);
        $traits = $reflexao->getTraits();
        $this->assertNotEmpty($traits);
        foreach ($traits as $nome => $trait) {
            $this->assertStringStartsWith('App\\Support\\Telegram\\', $nome);
            $this->assertSame(
                dirname(__DIR__, 2) . '/src/Support/Telegram/' . $trait->getShortName() . '.php',
                $trait->getFileName()
            );
        }
        $this->assertTrue(trait_exists(\App\Support\Telegram\TransportTrait::class));
    }

}
