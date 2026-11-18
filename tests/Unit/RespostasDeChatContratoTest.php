<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../comandos/funcoes_ia_comuns.php';
require_once __DIR__ . '/../../comandos/funcoes_respostas_ia.php';

final class RespostasDeChatContratoTest extends TestCase
{
    /** @return array<string, array{mixed, string}> */
    public static function errosDoProvedor(): array
    {
        return [
            'erro sem objeto' => [false, ''],
            'mensagem ausente' => [[], ''],
            'mensagem nula' => [['message' => null], ''],
            'objeto aninhado' => [['message' => ['token' => 'privado']], ''],
            'texto' => [['message' => 'sem crédito'], 'sem crédito'],
            'número' => [['message' => 401], '401'],
            'booleano' => [['message' => false], ''],
        ];
    }

    #[DataProvider('errosDoProvedor')]
    public function testDetalhePrivadoExigeEscalar(mixed $erro, string $esperado): void
    {
        $this->assertSame($esperado, mensagemPrivadaDeErroDeChat($erro));
    }

    /** @return array<string, array{array<array-key, mixed>}> */
    public static function respostasSemTexto(): array
    {
        return [
            'escolhas ausentes' => [[]],
            'escolhas escalares' => [['choices' => 'inválido']],
            'primeira escalar' => [['choices' => [false]]],
            'mensagem escalar' => [['choices' => [['message' => 5]]]],
            'conteúdo ausente' => [['choices' => [['message' => []]]]],
        ];
    }

    /** @param array<array-key, mixed> $dados */
    #[DataProvider('respostasSemTexto')]
    public function testJsonMalformadoNaoViraTexto(array $dados): void
    {
        $this->assertNull(conteudoDaPrimeiraRespostaDeChat($dados));
    }

    public function testConteudoZeroContinuaSendoResposta(): void
    {
        $this->assertSame(['tipo' => 'resposta', 'texto' => '0'], interpretarRespostaDeChat(
            '{"error":null,"choices":[{"message":{"content":"0"}}]}',
            'teste',
            'erro público'
        ));
    }
}
