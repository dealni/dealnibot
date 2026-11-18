<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';

/**
 * Regressão dos acessores que estavam presos a campos extintos da Bot API.
 *
 * A Bot API 7.0 trocou forward_from/forward_from_chat por forward_origin, e o
 * campo de entrada em grupo em Message sempre foi new_chat_members (plural).
 * setData() apenas injeta o update em memória — não há efeito de rede.
 */
final class TelegramAcessoresApiTest extends TestCase
{
    /** @var Telegram */
    private $telegram;

    protected function setUp(): void
    {
        $this->telegram = new Telegram('123456:FAKE_TOKEN', false);
    }

    // === forward_origin (Bot API 7.0) ===

    public function testFromIDLeSenderUserDoForwardOrigin(): void
    {
        $this->telegram->setData([
            'message' => [
                'forward_origin' => [
                    'type'        => 'user',
                    'sender_user' => ['id' => 4242],
                ],
            ],
        ]);

        $this->assertSame(4242, $this->telegram->FromID());
    }

    public function testFromIDeNuloQuandoOrigemEUsuarioOculto(): void
    {
        $this->telegram->setData([
            'message' => [
                'forward_origin' => [
                    'type'             => 'hidden_user',
                    'sender_user_name' => 'Alguém',
                ],
            ],
        ]);

        $this->assertNull($this->telegram->FromID());
    }

    public function testFromIDNuloSemForwardOrigin(): void
    {
        $this->telegram->setData(['message' => ['text' => 'oi']]);

        $this->assertNull($this->telegram->FromID());
    }

    public function testFromIDNuloQuandoIdNaoEInteiro(): void
    {
        $this->telegram->setData([
            'message' => [
                'forward_origin' => [
                    'type'        => 'user',
                    'sender_user' => ['id' => '4242'],
                ],
            ],
        ]);

        $this->assertNull($this->telegram->FromID());
    }

    public function testFromChatIDLeChatDeMessageOriginChannel(): void
    {
        $this->telegram->setData([
            'message' => [
                'forward_origin' => [
                    'type' => 'channel',
                    'chat' => ['id' => -100777],
                ],
            ],
        ]);

        $this->assertSame(-100777, $this->telegram->FromChatID());
    }

    public function testFromChatIDLeSenderChatDeMessageOriginChat(): void
    {
        $this->telegram->setData([
            'message' => [
                'forward_origin' => [
                    'type'        => 'chat',
                    'sender_chat' => ['id' => -100555],
                ],
            ],
        ]);

        $this->assertSame(-100555, $this->telegram->FromChatID());
    }

    public function testFromChatIDPrefereOChatDoCanalAoSenderChat(): void
    {
        // Um update real nunca traz os dois, mas a ordem de busca (chat antes
        // de sender_chat) é contrato e não pode inverter silenciosamente.
        $this->telegram->setData([
            'message' => [
                'forward_origin' => [
                    'type'        => 'channel',
                    'chat'        => ['id' => -100111],
                    'sender_chat' => ['id' => -100222],
                ],
            ],
        ]);

        $this->assertSame(-100111, $this->telegram->FromChatID());
    }

    public function testFromChatIDCaiNoSenderChatQuandoOChatTemIdInvalido(): void
    {
        // id não inteiro no primeiro caminho não pode "consumir" a busca: o
        // fallback para sender_chat só acontece porque intOrNull devolveu null.
        $this->telegram->setData([
            'message' => [
                'forward_origin' => [
                    'type'        => 'channel',
                    'chat'        => ['id' => '-100111'],
                    'sender_chat' => ['id' => -100222],
                ],
            ],
        ]);

        $this->assertSame(-100222, $this->telegram->FromChatID());
    }

    public function testFromChatIDNuloParaUsuarioOculto(): void
    {
        $this->telegram->setData([
            'message' => [
                'forward_origin' => [
                    'type'             => 'hidden_user',
                    'sender_user_name' => 'Alguém',
                ],
            ],
        ]);

        $this->assertNull($this->telegram->FromChatID());
    }

    public function testReplyToMessageFromUserIDLeForwardOrigin(): void
    {
        $this->telegram->setData([
            'message' => [
                'reply_to_message' => [
                    'forward_origin' => [
                        'type'        => 'user',
                        'sender_user' => ['id' => 99],
                    ],
                ],
            ],
        ]);

        $this->assertSame(99, $this->telegram->ReplyToMessageFromUserID());
    }

    public function testReplyToMessageFromUserIDNuloQuandoARespostaNaoEEncaminhada(): void
    {
        $this->telegram->setData([
            'message' => ['reply_to_message' => ['message_id' => 3, 'text' => 'original']],
        ]);

        $this->assertNull($this->telegram->ReplyToMessageFromUserID());
    }

    // === new_chat_members (plural) ===

    public function testCampoSingularExtintoNaoClassificaEntradaEmGrupo(): void
    {
        // new_chat_member (singular) só existe em ChatMemberUpdated; dentro de
        // Message ele não existe mais e não pode voltar a classificar nada.
        $this->telegram->setData([
            'message' => ['new_chat_member' => ['id' => 1, 'first_name' => 'Fulano']],
        ]);

        $this->assertNull($this->telegram->getUpdateType());
    }

    // === messageFromGroup ===

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function origemProvider(): array
    {
        return [
            'grupo'       => [['message' => ['chat' => ['type' => 'group']]], true],
            'supergrupo'  => [['message' => ['chat' => ['type' => 'supergroup']]], true],
            'privado'     => [['message' => ['chat' => ['type' => 'private']]], false],
            'canal'       => [['message' => ['chat' => ['type' => 'channel']]], false],
            'sem message' => [['callback_query' => ['id' => '1']], false],
        ];
    }

    /**
     * @param array<string, mixed> $update
     */
    #[DataProvider('origemProvider')]
    public function testMessageFromGroup(array $update, bool $esperado): void
    {
        $this->telegram->setData($update);

        $this->assertSame($esperado, $this->telegram->messageFromGroup());
    }

    // === LastName ===

    public function testLastNameCaiNoMessagePorPadrao(): void
    {
        $this->telegram->setData([
            'message' => ['from' => ['id' => 1, 'last_name' => 'Silva'], 'photo' => []],
        ]);

        $this->assertSame('Silva', $this->telegram->LastName());
    }

    public function testLastNameENuloQuandoAusente(): void
    {
        $this->telegram->setData([
            'message' => ['from' => ['id' => 1], 'text' => 'oi'],
        ]);

        $this->assertNull($this->telegram->LastName());
    }

    // === Campos novos do InlineKeyboardButton ===

    public function testTecladoComPlaceholderEPersistencia(): void
    {
        $json = $this->telegram->buildKeyBoard([['A']], false, false, false, true, 'Digite algo');
        $dados = json_decode($json, true);
        $this->assertIsArray($dados);

        $this->assertTrue($dados['is_persistent']);
        $this->assertSame('Digite algo', $dados['input_field_placeholder']);
    }

    // === update do tipo edited_message: acessores de legenda e remetente ===

    public function testCaptionLeALegendaDeUmEditedMessage(): void
    {
        $this->telegram->setData([
            'edited_message' => [
                'caption' => 'legenda editada',
            ],
        ]);

        $this->assertSame('legenda editada', $this->telegram->Caption());
    }

    public function testFirstNameLeORemetenteDeUmEditedMessage(): void
    {
        $this->telegram->setData([
            'edited_message' => [
                'from' => ['first_name' => 'Joana'],
            ],
        ]);

        $this->assertSame('Joana', $this->telegram->FirstName());
    }

    public function testLastNameLeORemetenteDeUmEditedMessage(): void
    {
        $this->telegram->setData([
            'edited_message' => [
                'from' => ['last_name' => 'Ribeiro'],
            ],
        ]);

        $this->assertSame('Ribeiro', $this->telegram->LastName());
    }

    public function testUsernameLeORemetenteDeUmEditedMessage(): void
    {
        $this->telegram->setData([
            'edited_message' => [
                'from' => ['username' => 'joana_r'],
            ],
        ]);

        $this->assertSame('joana_r', $this->telegram->Username());
    }

    // === update do tipo inline_query: acessores de remetente ===

    public function testFirstNameLeORemetenteDeUmInlineQuery(): void
    {
        $this->telegram->setData([
            'inline_query' => [
                'from' => ['first_name' => 'Marcos'],
            ],
        ]);

        $this->assertSame('Marcos', $this->telegram->FirstName());
    }

    public function testLastNameLeORemetenteDeUmInlineQuery(): void
    {
        $this->telegram->setData([
            'inline_query' => [
                'from' => ['last_name' => 'Prado'],
            ],
        ]);

        $this->assertSame('Prado', $this->telegram->LastName());
    }

    public function testUsernameLeORemetenteDeUmInlineQuery(): void
    {
        $this->telegram->setData([
            'inline_query' => [
                'from' => ['username' => 'marcos_p'],
            ],
        ]);

        $this->assertSame('marcos_p', $this->telegram->Username());
    }

}
