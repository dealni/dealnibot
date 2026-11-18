<?php

declare(strict_types=1);

namespace Tests;

use App\Support\ProcessadorDeRedacao;
use App\Support\RedatorJson;
use PHPUnit\Framework\TestCase;

/**
 * A busca por blocos JSON dentro de um texto percorre a mensagem INTEIRA e
 * termina.
 *
 * O App\Support\RedatorJson varre a mensagem caractere a caractere procurando
 * `{` e `[`. O que o redator FAZ com o JSON que encontra está em
 * RedacaoDeObjetoTest; aqui só se prova que a varredura anda do primeiro ao
 * último caractere e para.
 *
 * Este arquivo é curto, não toca disco, banco, Redis nem logger, e o nome
 * começa em "Busca" porque essa é a operação que ele descreve — o que também o
 * coloca cedo na ordem de descoberta do PHPUnit, e isso importa. Se a condição
 * de parada da varredura for invertida, ela passa a girar para sempre sobre uma
 * mensagem VAZIA, e mensagem vazia é o que os testes de fluxo entregam ao
 * logger o tempo todo: quem chegar primeiro nesse estado TRAVA em vez de
 * reprovar. Travamento não é defeito detectado — na medição de mutação ele
 * consome o teto de espera e aparece como lentidão, não como falha. Com a
 * asserção abaixo sobre um texto não vazio, o mesmo defeito responde na hora,
 * devolvendo string vazia, e vira reprovação antes de qualquer teste com log.
 */
final class BuscaDeBlocosJsonTest extends TestCase
{
    public function testTextoSemBlocoJsonAtravessaAVarreduraInteiro(): void
    {
        $redator = new RedatorJson(
            ProcessadorDeRedacao::SUBSTITUTO,
            static fn (mixed $valor): mixed => $valor,
            static fn (string $chave): bool => false,
        );

        $this->assertSame(
            'falha ao falar com o provedor apos 3 tentativas',
            $redator->redigir('falha ao falar com o provedor apos 3 tentativas'),
            'Texto sem bloco JSON precisa sair igual ao que entrou, do primeiro ao último caractere.'
        );
    }
}
