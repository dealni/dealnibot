<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';

/**
 * Testes do getUpdateType() da classe Telegram.
 *
 * Cobre os tipos clássicos (message, callback_query...), os tipos novos do
 * Bot API 10.2 (reações, business, pagamentos nativos, enquetes, boosts...) e a
 * ORDEM de classificação, que é contrato: os comandos dos bots ramificam por
 * ela e uma inversão silenciosa muda o fluxo de todos eles.
 * setData() apenas injeta o update em memória — não há efeito de rede.
 */
final class TelegramUpdateTypeTest extends TestCase
{
    private Telegram $telegram;

    protected function setUp(): void
    {
        // Token fake + log_errors=false: nenhuma chamada de rede é feita.
        $this->telegram = new Telegram('123456:FAKE_TOKEN', false);
    }

    /**
     * Tipos clássicos já suportados — comportamento não pode mudar.
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function tiposClassicosProvider(): array
    {
        return [
            'message com texto' => [['message' => ['text' => 'oi']], Telegram::MESSAGE],
            'callback_query' => [['callback_query' => ['id' => '1']], Telegram::CALLBACK_QUERY],
            'inline_query' => [['inline_query' => ['id' => '1']], Telegram::INLINE_QUERY],
            'edited_message' => [['edited_message' => ['text' => 'x']], Telegram::EDITED_MESSAGE],
            'channel_post' => [['channel_post' => ['text' => 'x']], Telegram::CHANNEL_POST],
            'my_chat_member' => [['my_chat_member' => ['chat' => []]], Telegram::MY_CHAT_MEMBER],
            'foto' => [['message' => ['photo' => []]], Telegram::PHOTO],
            'localizacao' => [['message' => ['location' => []]], Telegram::LOCATION],
            'video' => [['message' => ['video' => []]], Telegram::VIDEO],
            'audio' => [['message' => ['audio' => []]], Telegram::AUDIO],
            'voz' => [['message' => ['voice' => []]], Telegram::VOICE],
            'contato' => [['message' => ['contact' => []]], Telegram::CONTACT],
            'resposta' => [['message' => ['reply_to_message' => []]], Telegram::REPLY],
            'animacao' => [['message' => ['animation' => []]], Telegram::ANIMATION],
            'figurinha' => [['message' => ['sticker' => []]], Telegram::STICKER],
            'documento' => [['message' => ['document' => []]], Telegram::DOCUMENT],
            'novo membro (campo plural)' => [
                ['message' => ['new_chat_members' => [['id' => 2]]]],
                Telegram::NEW_CHAT_MEMBER,
            ],
            'saida de membro' => [['message' => ['left_chat_member' => ['id' => 2]]], Telegram::LEFT_CHAT_MEMBER],
        ];
    }

    /**
     * @param array<string, mixed> $update
     */
    #[DataProvider('tiposClassicosProvider')]
    public function testTiposClassicos(array $update, string $esperado): void
    {
        $this->telegram->setData($update);

        $this->assertSame($esperado, $this->telegram->getUpdateType());
    }

    public function testCampoPresenteComValorNuloNaoClassificaOUpdate(): void
    {
        // A Bot API omite campos ausentes em vez de mandá-los como null, então
        // "presente com null" é payload adulterado: a classificação compara o
        // resultado de dataPath() com null e trata os dois casos como ausência.
        $this->telegram->setData(['message' => ['photo' => null, 'text' => 'oi']]);

        $this->assertSame(Telegram::MESSAGE, $this->telegram->getUpdateType());
    }

    /**
     * Tipos novos do Bot API 10.2 em que o campo do Update é o próprio tipo.
     * @return array<string, array{string}>
     */
    public static function tiposDiretosProvider(): array
    {
        $tipos = [
            Telegram::EDITED_CHANNEL_POST,
            Telegram::BUSINESS_CONNECTION,
            Telegram::BUSINESS_MESSAGE,
            Telegram::EDITED_BUSINESS_MESSAGE,
            Telegram::DELETED_BUSINESS_MESSAGES,
            Telegram::GUEST_MESSAGE,
            Telegram::MESSAGE_REACTION,
            Telegram::MESSAGE_REACTION_COUNT,
            Telegram::CHOSEN_INLINE_RESULT,
            Telegram::SHIPPING_QUERY,
            Telegram::PRE_CHECKOUT_QUERY,
            Telegram::PURCHASED_PAID_MEDIA,
            Telegram::POLL,
            Telegram::POLL_ANSWER,
            Telegram::CHAT_MEMBER,
            Telegram::CHAT_JOIN_REQUEST,
            Telegram::CHAT_BOOST,
            Telegram::REMOVED_CHAT_BOOST,
            Telegram::MANAGED_BOT,
            Telegram::SUBSCRIPTION,
        ];

        $casos = [];
        foreach ($tipos as $tipo) {
            $casos[$tipo] = [$tipo];
        }

        return $casos;
    }

    #[DataProvider('tiposDiretosProvider')]
    public function testTiposDiretosDoBotApi(string $tipo): void
    {
        $this->telegram->setData(['update_id' => 1, $tipo => ['stub' => true]]);

        $this->assertSame($tipo, $this->telegram->getUpdateType());
    }

    public function testMensagemComTextoTemPrecedenciaSobreTiposDiretos(): void
    {
        // Um update real nunca traz dois payloads, mas a ordem de checagem
        // dos tipos clássicos não pode ser alterada pelos tipos novos.
        $this->telegram->setData([
            'message' => ['text' => 'oi'],
            'poll' => ['id' => '1'],
        ]);

        $this->assertSame(Telegram::MESSAGE, $this->telegram->getUpdateType());
    }

    public function testServeUpdateInvalidaCacheDoTipo(): void
    {
        $reflection = new \ReflectionClass($this->telegram);
        $updates = $reflection->getProperty('updates');
        $updates->setValue($this->telegram, [
            'ok' => true,
            'result' => [
                ['message' => ['text' => 'primeira', 'chat' => ['id' => 1], 'message_id' => 11]],
                ['callback_query' => ['data' => 'segunda', 'message' => ['chat' => ['id' => 2], 'message_id' => 22]]],
            ],
        ]);

        $this->telegram->serveUpdate(0);
        $this->assertSame(Telegram::MESSAGE, $this->telegram->getUpdateType());

        $this->telegram->serveUpdate(1);
        $this->assertSame(Telegram::CALLBACK_QUERY, $this->telegram->getUpdateType());
        $this->assertSame('segunda', $this->telegram->Text());
        $this->assertSame(2, $this->telegram->ChatID());
        $this->assertSame(22, $this->telegram->MessageID());
    }

    public function testUpdateDesconhecidoRetornaNull(): void
    {
        $this->telegram->setData(['update_id' => 1, 'tipo_inexistente' => []]);

        $this->assertNull($this->telegram->getUpdateType());
    }

    public function testUpdateVazioRetornaNull(): void
    {
        $this->telegram->setData([]);

        $this->assertNull($this->telegram->getUpdateType());
    }

    public function testMensagemSemCampoReconhecidoRetornaNull(): void
    {
        // Mensagem de serviço sem nenhum dos campos classificáveis (ex.:
        // pinned_message): não pode ser confundida com MESSAGE.
        $this->telegram->setData([
            'update_id' => 1,
            'message' => ['message_id' => 7, 'chat' => ['id' => 1], 'pinned_message' => ['message_id' => 3]],
        ]);

        $this->assertNull($this->telegram->getUpdateType());
    }

    public function testMessageQueNaoEArrayNaoQuebraAClassificacao(): void
    {
        // $this->data vem de json_decode (mixed): 'message' pode chegar como
        // string num payload adulterado. dataPath precisa devolver null em vez
        // de tentar indexar uma string.
        $this->telegram->setData(['update_id' => 1, 'message' => 'texto solto']);

        $this->assertNull($this->telegram->getUpdateType());
        $this->assertNull($this->telegram->Text());
        $this->assertNull($this->telegram->ChatID());
    }

    public function testAnimacaoTemPrecedenciaSobreDocumento(): void
    {
        // O Telegram envia animações também com o campo document preenchido;
        // a ordem de checagem precisa classificar como ANIMATION.
        $this->telegram->setData(['message' => ['animation' => [], 'document' => []]]);

        $this->assertSame(Telegram::ANIMATION, $this->telegram->getUpdateType());
    }

    public function testTextoTemPrecedenciaSobreReply(): void
    {
        // Resposta comum (texto respondendo outra mensagem) é classificada
        // como MESSAGE: text é checado antes de reply_to_message.
        $this->telegram->setData([
            'message' => ['text' => 'concordo', 'reply_to_message' => ['message_id' => 5]],
        ]);

        $this->assertSame(Telegram::MESSAGE, $this->telegram->getUpdateType());
    }

    public function testReplyTemPrecedenciaSobreFigurinhaEDocumento(): void
    {
        // Comportamento histórico da classe: reply_to_message é checado antes
        // de animation/sticker/document, então mídia enviada como resposta é
        // classificada REPLY (mudança aqui quebraria os fluxos dos comandos).
        $this->telegram->setData([
            'message' => ['sticker' => ['file_id' => 'S1'], 'reply_to_message' => ['message_id' => 5]],
        ]);

        $this->assertSame(Telegram::REPLY, $this->telegram->getUpdateType());
    }

    public function testFotoComLegendaContinuaSendoFoto(): void
    {
        // caption não participa da classificação: uma foto legendada tem de
        // continuar PHOTO, senão Caption() passaria a ler o ramo errado.
        $this->telegram->setData(['message' => ['photo' => [['file_id' => 'F']], 'caption' => 'legenda']]);

        $this->assertSame(Telegram::PHOTO, $this->telegram->getUpdateType());
        $this->assertSame('legenda', $this->telegram->Caption());
    }

    public function testSetDataReiniciaOCacheDoTipo(): void
    {
        $this->telegram->setData(['message' => ['text' => 'a']]);
        $this->assertSame(Telegram::MESSAGE, $this->telegram->getUpdateType());

        // Sem a invalidação do cache em setData(), o tipo antigo vazaria aqui.
        $this->telegram->setData(['poll' => ['id' => '1']]);
        $this->assertSame(Telegram::POLL, $this->telegram->getUpdateType());
    }

    public function testTipoNaoReconhecidoNaoFicaMemoizado(): void
    {
        // null NÃO é memoizado (o cache guarda string): um update não
        // reconhecido seguido de outro reconhecido continua classificando.
        $this->telegram->setData(['tipo_inexistente' => []]);
        $this->assertNull($this->telegram->getUpdateType());

        $this->telegram->setData(['message' => ['text' => 'agora sim']]);
        $this->assertSame(Telegram::MESSAGE, $this->telegram->getUpdateType());
    }
}
