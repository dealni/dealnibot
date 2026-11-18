<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';

/**
 * Testes do fluxo de long polling: getUpdates(), serveUpdate() e UpdateCount().
 *
 * endpoint() é público, então uma subclasse dublê devolve respostas enfileiradas
 * e registra as chamadas — nenhum teste faz rede. O contrato fixado:
 *   1. getUpdates() consulta o endpoint getUpdates com offset/limit/timeout e,
 *      quando informado, allowed_updates serializado como JSON;
 *   2. havendo updates e update=true, confirma o consumo com uma segunda
 *      chamada de offset = último update_id + 1 e limit = 1, repetindo
 *      allowed_updates;
 *   3. o retorno é o da PRIMEIRA chamada, nunca o da confirmação;
 *   4. resposta malformada (sem result, update_id não-int) NÃO dispara confirmação;
 *   5. serveUpdate() seleciona um update da lista para os acessores.
 */
final class TelegramGetUpdatesTest extends TestCase
{
    /**
     * Dublê com fila de respostas: cada chamada a endpoint() consome a próxima.
     * Enfileirar respostas DIFERENTES é o que permite distinguir a resposta da
     * primeira chamada da resposta da confirmação de offset.
     *
     * @param array<int, array{ok: bool, result?: mixed, description?: string, error_code?: int, parameters?: array<string, mixed>}> $respostas
     * @return Telegram&object{chamadas: array<int, array{api: string, content: array<string, mixed>, post: bool}>, respostas: array<int, array{ok: bool, result?: mixed, description?: string, error_code?: int, parameters?: array<string, mixed>}>}
     */
    private function duble(array $respostas)
    {
        $duble = new class ('123456:FAKE_TOKEN', false) extends Telegram {
            /** @var array<int, array{api: string, content: array<string, mixed>, post: bool}> */
            public array $chamadas = [];
            /** @var array<int, array{ok: bool, result?: mixed, description?: string, error_code?: int, parameters?: array<string, mixed>}> */
            public array $respostas = [];

            /**
             * A forma do retorno é repetida à mão porque o alias
             * TelegramResponse é declarado na classe Telegram e não há como
             * importá-lo para dentro de uma classe anônima; um
             * `array<string, mixed>` aqui seria mais largo que o retorno do
             * método sobrescrito.
             *
             * @param string $api
             * @param array<string, mixed> $content
             * @param bool $post
             * @return array{ok: bool, result?: mixed, description?: string, error_code?: int, parameters?: array<string, mixed>}
             */
            public function endpoint(string $api, array $content, bool $post = true): array
            {
                $this->chamadas[] = ['api' => $api, 'content' => $content, 'post' => $post];
                $resposta = array_shift($this->respostas);

                return $resposta !== null ? $resposta : ['ok' => true, 'result' => []];
            }
        };
        $duble->respostas = $respostas;

        return $duble;
    }

    public function testGetUpdatesConfirmaOffsetDoUltimoUpdate(): void
    {
        $resposta = [
            'ok' => true,
            'result' => [
                ['update_id' => 10, 'message' => ['text' => 'a', 'chat' => ['id' => 1]]],
                ['update_id' => 11, 'message' => ['text' => 'b', 'chat' => ['id' => 2]]],
            ],
        ];
        $duble = $this->duble([$resposta]);

        $retorno = $duble->getUpdates();

        $this->assertSame($resposta, $retorno);
        $this->assertSame([
            ['api' => 'getUpdates', 'content' => ['offset' => 0, 'limit' => 100, 'timeout' => 0], 'post' => true],
            ['api' => 'getUpdates', 'content' => ['offset' => 12, 'limit' => 1, 'timeout' => 0], 'post' => true],
        ], $duble->chamadas);
        $this->assertSame(2, $duble->UpdateCount());
    }

    public function testGetUpdatesDevolveARespostaDaPrimeiraChamadaENaoADaConfirmacao(): void
    {
        // Com as duas chamadas devolvendo a MESMA coisa, comparar o retorno com
        // a resposta do dublê não provaria nada: passaria igual se getUpdates
        // devolvesse a segunda. Aqui cada chamada devolve algo diferente.
        $primeira = [
            'ok' => true,
            'result' => [['update_id' => 100, 'message' => ['text' => 'primeira', 'chat' => ['id' => 1]]]],
        ];
        $confirmacao = [
            'ok' => true,
            'result' => [['update_id' => 500, 'message' => ['text' => 'nao deve vazar', 'chat' => ['id' => 9]]]],
        ];
        $duble = $this->duble([$primeira, $confirmacao]);

        $retorno = $duble->getUpdates();

        $this->assertSame($primeira, $retorno);
        $this->assertCount(2, $duble->chamadas);
        // E são os updates da PRIMEIRA chamada que ficam disponíveis aos acessores.
        $this->assertSame(1, $duble->UpdateCount());
        $duble->serveUpdate(0);
        $this->assertSame('primeira', $duble->Text());
    }

    public function testGetUpdatesRepassaParametrosENaoConfirmaQuandoDesativado(): void
    {
        $resposta = ['ok' => true, 'result' => [['update_id' => 10, 'message' => ['text' => 'a']]]];
        $duble = $this->duble([$resposta]);

        $duble->getUpdates(5, 50, 30, false);

        $this->assertSame(
            [['api' => 'getUpdates', 'content' => ['offset' => 5, 'limit' => 50, 'timeout' => 30], 'post' => true]],
            $duble->chamadas
        );
        $this->assertSame(1, $duble->UpdateCount());
    }

    public function testGetUpdatesRepassaAllowedUpdatesComoJson(): void
    {
        $duble = $this->duble([['ok' => true, 'result' => []]]);

        $duble->getUpdates(0, 100, 0, false, ['message', 'subscription']);

        $this->assertSame(
            [['api' => 'getUpdates', 'content' => [
                'offset' => 0,
                'limit' => 100,
                'timeout' => 0,
                'allowed_updates' => '["message","subscription"]',
            ], 'post' => true]],
            $duble->chamadas
        );
    }

    public function testGetUpdatesRepeteAllowedUpdatesNaConfirmacaoDeOffset(): void
    {
        // A confirmação é uma chamada nova ao getUpdates: sem repetir
        // allowed_updates, o Telegram passaria a reinscrever o bot em TODOS os
        // tipos de update (o filtro anterior é esquecido a cada chamada).
        $duble = $this->duble([
            ['ok' => true, 'result' => [['update_id' => 41, 'message' => ['text' => 'a']]]],
        ]);

        $duble->getUpdates(0, 100, 15, true, ['message']);

        $this->assertSame([
            ['api' => 'getUpdates', 'content' => [
                'offset' => 0,
                'limit' => 100,
                'timeout' => 15,
                'allowed_updates' => '["message"]',
            ], 'post' => true],
            ['api' => 'getUpdates', 'content' => [
                'offset' => 42,
                'limit' => 1,
                'timeout' => 15,
                'allowed_updates' => '["message"]',
            ], 'post' => true],
        ], $duble->chamadas);
    }

    public function testGetUpdatesAceitaListaVaziaDeAllowedUpdates(): void
    {
        // [] é documentado pela Bot API (lista vazia = nenhum filtro herdado) e
        // é diferente de null (parâmetro omitido).
        $duble = $this->duble([['ok' => true, 'result' => []]]);

        $duble->getUpdates(0, 100, 0, false, []);

        $this->assertSame('[]', $duble->chamadas[0]['content']['allowed_updates'] ?? null);
    }

    public function testGetUpdatesRecusaAllowedUpdatesQueNaoPodeSerJson(): void
    {
        // O valor inválido em UTF-8 fazia json_encode() devolver false e o
        // método enviava a requisição sem filtro, alterando a assinatura do
        // polling silenciosamente.
        $duble = $this->duble([['ok' => true, 'result' => []]]);

        $resultado = $duble->getUpdates(0, 100, 0, false, ["\xB1\x31"]);

        $this->assertSame([
            'ok' => false,
            'description' => 'Não foi possível serializar allowed_updates como JSON',
        ], $resultado);
        $this->assertSame([], $duble->chamadas);
    }

    public function testGetUpdatesNaoConfirmaComListaVazia(): void
    {
        $duble = $this->duble([['ok' => true, 'result' => []]]);

        $duble->getUpdates();

        $this->assertCount(1, $duble->chamadas);
        $this->assertSame(0, $duble->UpdateCount());
    }

    public function testGetUpdatesNaoConfirmaComUpdateIdNaoInteiro(): void
    {
        $duble = $this->duble([
            ['ok' => true, 'result' => [['update_id' => '10', 'message' => ['text' => 'a']]]],
        ]);

        $duble->getUpdates();

        $this->assertCount(1, $duble->chamadas);
        $this->assertSame(1, $duble->UpdateCount());
    }

    public function testGetUpdatesNaoConfirmaQuandoOUltimoItemNaoEArray(): void
    {
        $duble = $this->duble([['ok' => true, 'result' => ['texto solto']]]);

        $duble->getUpdates();

        $this->assertCount(1, $duble->chamadas);
    }

    /**
     * Update que chega SEM 'update_id' (payload truncado ou tipo novo que a
     * API ainda não documenta): as duas condições precisam valer juntas para
     * ler a chave. Com uma só, o acesso a `update_id` sairia num item que não
     * a tem — aviso do PHP em produção a cada ciclo de polling.
     */
    public function testGetUpdatesNaoConfirmaQuandoOUltimoItemNaoTemUpdateId(): void
    {
        $duble = $this->duble([
            ['ok' => true, 'result' => [['message' => ['text' => 'sem update_id']]]],
        ]);

        $duble->getUpdates();

        $this->assertCount(1, $duble->chamadas);
        $this->assertSame(1, $duble->UpdateCount());
    }

    public function testGetUpdatesConfirmaPeloUltimoItemMesmoComResultNaoSequencial(): void
    {
        // json_decode devolve mixed: um 'result' que chegue como objeto JSON
        // ({"3": {...}}) vira array com chave 3, não 0. Indexar por
        // count($lista) - 1 acessaria a chave 0 inexistente — aviso do PHP e,
        // pior, offset nunca confirmado: o mesmo update volta em todo ciclo de
        // long polling.
        $duble = $this->duble([
            ['ok' => true, 'result' => [3 => ['update_id' => 7, 'message' => ['text' => 'a']]]],
        ]);

        $duble->getUpdates(0, 100, 0);

        $this->assertSame([
            ['api' => 'getUpdates', 'content' => ['offset' => 0, 'limit' => 100, 'timeout' => 0], 'post' => true],
            ['api' => 'getUpdates', 'content' => ['offset' => 8, 'limit' => 1, 'timeout' => 0], 'post' => true],
        ], $duble->chamadas);
    }

    public function testGetUpdatesComRespostaSemResult(): void
    {
        $resposta = ['ok' => false, 'description' => 'erro'];
        $duble = $this->duble([$resposta]);

        $retorno = $duble->getUpdates();

        $this->assertSame($resposta, $retorno);
        $this->assertCount(1, $duble->chamadas);
        $this->assertSame(0, $duble->UpdateCount());
    }

    public function testGetUpdatesComResultNaoArrayNaoConfirma(): void
    {
        $duble = $this->duble([['ok' => true, 'result' => 'texto']]);

        $duble->getUpdates();

        $this->assertCount(1, $duble->chamadas);
        $this->assertSame(0, $duble->UpdateCount());
    }

    public function testUpdateCountIgnoraChavesNaoInteirasDoResult(): void
    {
        $duble = $this->duble([
            ['ok' => true, 'result' => [
                0 => ['update_id' => 1],
                'meta' => 'ignorada',
                1 => ['update_id' => 2],
            ]],
        ]);

        $duble->getUpdates(0, 100, 0, false);

        $this->assertSame(2, $duble->UpdateCount());
    }

    public function testServeUpdatePercorreUpdatesRecebidos(): void
    {
        $duble = $this->duble([
            [
                'ok' => true,
                'result' => [
                    ['update_id' => 10, 'message' => ['text' => 'a', 'chat' => ['id' => 1], 'message_id' => 11]],
                    ['update_id' => 11, 'callback_query' => ['data' => 'b', 'message' => ['chat' => ['id' => 2], 'message_id' => 22]]],
                ],
            ],
        ]);
        $duble->getUpdates(0, 100, 0, false);

        $duble->serveUpdate(0);
        $this->assertSame(Telegram::MESSAGE, $duble->getUpdateType());
        $this->assertSame('a', $duble->Text());
        $this->assertSame(1, $duble->ChatID());
        $this->assertSame(11, $duble->MessageID());
        $this->assertSame(10, $duble->UpdateID());

        $duble->serveUpdate(1);
        $this->assertSame(Telegram::CALLBACK_QUERY, $duble->getUpdateType());
        $this->assertSame('b', $duble->Text());
        $this->assertSame(2, $duble->ChatID());
        $this->assertSame(22, $duble->MessageID());
        $this->assertSame(11, $duble->UpdateID());
    }

    public function testServeUpdateForaDoIntervaloLimpaOsDados(): void
    {
        $duble = $this->duble([
            ['ok' => true, 'result' => [['update_id' => 10, 'message' => ['text' => 'a']]]],
        ]);
        $duble->getUpdates(0, 100, 0, false);
        $duble->serveUpdate(0);

        $duble->serveUpdate(9);

        $this->assertNull($duble->getUpdateType());
        $this->assertNull($duble->Text());
        $this->assertSame([], $duble->getData());
    }

    public function testServeUpdateComUpdateNaoArrayLimpaOsDados(): void
    {
        $duble = $this->duble([['ok' => true, 'result' => ['texto solto']]]);
        $duble->getUpdates(0, 100, 0, false);

        $duble->serveUpdate(0);

        $this->assertSame([], $duble->getData());
        $this->assertNull($duble->getUpdateType());
    }
}
