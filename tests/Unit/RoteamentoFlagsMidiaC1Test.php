<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Fronteiras das três flags de mídia de inclusos/roteamento.php.
 *
 * RoteadorRotasTest já cobre o caso feliz e a coleção vazia. Aqui ficam os
 * valores que o `empty()` original engolia junto — ausente, null, coleção
 * vazia e escalar —, porque é essa decisão que manda o update para um comando
 * que faz chamada PAGA à OpenAI: um falso positivo aqui vira gasto.
 */
final class RoteamentoFlagsMidiaC1Test extends TestCase
{
    /**
     * @return array<string, array{string, callable(array<array-key, mixed>): bool}>
     */
    private function flags(): array
    {
        return [
            'photo' => ['photo', static fn (array $m): bool => \roteamento_temFoto($m)],
            'voice' => ['voice', static fn (array $m): bool => \roteamento_temVoz($m)],
            'audio' => ['audio', static fn (array $m): bool => \roteamento_temAudio($m)],
        ];
    }

    public function testCampoAusenteNuloOuVazioNaoContaComoMidia(): void
    {
        foreach ($this->flags() as [$campo, $flag]) {
            $this->assertFalse($flag([]), "{$campo} ausente");
            $this->assertFalse($flag([$campo => null]), "{$campo} null");
            $this->assertFalse($flag([$campo => []]), "{$campo} coleção vazia");
        }
    }

    public function testEstruturaNaoVaziaContaComoMidia(): void
    {
        foreach ($this->flags() as [$campo, $flag]) {
            $this->assertTrue($flag([$campo => ['file_id' => 'abc']]), "{$campo} objeto");
            $this->assertTrue($flag([$campo => [['file_id' => 'abc']]]), "{$campo} lista");
        }
    }

    /**
     * Fronteira que MUDOU ao trocar o `empty()` por comparação estrita: antes,
     * `0`, `''`, `'0'` e `false` no campo contavam como "sem mídia" e o update
     * ia para o comando de texto (chamada PAGA); agora contam como mídia
     * malformada e param no comando de mídia, que só pede o arquivo de volta.
     * Nada disso o Telegram produz — o campo é sempre objeto ou lista.
     */
    public function testEscalarNoCampoParaNoComandoDeMidiaEmVezDeIrParaOTexto(): void
    {
        foreach ($this->flags() as [$campo, $flag]) {
            $this->assertTrue($flag([$campo => 'x']), "{$campo} string");
            $this->assertTrue($flag([$campo => 0]), "{$campo} zero");
            $this->assertTrue($flag([$campo => '']), "{$campo} string vazia");
            $this->assertTrue($flag([$campo => false]), "{$campo} false");
        }
    }
}
