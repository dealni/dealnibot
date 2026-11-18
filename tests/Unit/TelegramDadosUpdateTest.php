<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';

/**
 * Testes dos acessores de leitura do update atual da classe Telegram.
 *
 * Cobre Text/Caption/Chat/ChatID/MessageID, a família Callback_*, os campos
 * de remetente (FirstName/LastName/Username/UserID) por tipo de update e os
 * utilitários getData/setData/UpdateCount/respondSuccess. Também fixa o
 * contrato de "tipos honestos": todo valor vem de json_decode (mixed), então
 * valor com tipo errado vira null e nunca vaza para quem chama.
 * setData() apenas injeta o update em memória — não há efeito de rede.
 */
final class TelegramDadosUpdateTest extends TestCase
{
    private Telegram $telegram;

    protected function setUp(): void
    {
        // Token fake + log_errors=false: nenhuma chamada de rede é feita.
        $this->telegram = new Telegram('123456:FAKE_TOKEN', false);
    }

    // === getData / setData ===

    public function testGetDataDevolveOUpdateInjetado(): void
    {
        $update = ['update_id' => 5, 'message' => ['text' => 'oi']];
        $this->telegram->setData($update);

        $this->assertSame($update, $this->telegram->getData());
    }

    public function testGetDataSemEntradaDevolveArrayVazio(): void
    {
        // php://input é vazio no CLI: json_decode falha e o retorno é [].
        $this->assertSame([], $this->telegram->getData());
    }

    /**
     * Quando a leitura do corpo da requisição FALHA (não apenas vem vazia), o
     * update tem de ser [] em vez de propagar false — todo acessor a jusante
     * depende disso.
     *
     * O wrapper de stream "php" é substituído por um dublê que recusa a
     * abertura e é SEMPRE restaurado em seguida; o E_WARNING de fopen é
     * engolido porque o contrato testado é o retorno silencioso.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetDataDevolveArrayVazioQuandoALeituraDoCorpoFalha(): void
    {
        $telegram = new Telegram('123456:FAKE_TOKEN', false);

        $this->assertTrue(stream_wrapper_unregister('php'));
        set_error_handler(static function (int $codigo, string $mensagem): bool {
            return true;
        });
        try {
            $this->assertTrue(stream_wrapper_register('php', FluxoPhpRecusado::class));
            $dados = $telegram->getData();
        } finally {
            restore_error_handler();
            stream_wrapper_restore('php');
        }

        $this->assertSame([], $dados);
    }

    /**
     * Sem update injetado, getData() lê o corpo da requisição do webhook e
     * devolve o JSON decodificado. É o caminho de produção do bot_web.php: se
     * ele devolvesse [], todo acessor a jusante veria um update vazio e o bot
     * ficaria mudo sem nenhum erro visível.
     *
     * O wrapper "php" é substituído por um dublê que serve um corpo fixo e é
     * SEMPRE restaurado em seguida.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetDataDecodificaOCorpoDaRequisicaoQuandoNaoHaUpdateInjetado(): void
    {
        // Construído ANTES da troca do wrapper: o construtor já chama getData()
        // e precisa ver o php://input real (vazio no CLI), senão o update
        // ficaria em memória e o corpo do dublê nem seria lido.
        $telegram = new Telegram('123456:FAKE_TOKEN', false);

        $this->assertTrue(stream_wrapper_unregister('php'));
        try {
            $this->assertTrue(stream_wrapper_register('php', FluxoPhpComCorpo::class));
            $dados = $telegram->getData();
        } finally {
            stream_wrapper_restore('php');
        }

        $this->assertSame(
            ['update_id' => 77, 'message' => ['text' => 'do corpo', 'chat' => ['id' => -100]]],
            $dados
        );
    }

    /**
     * Corpo que decodifica para algo que NÃO é objeto JSON (lista, escalar,
     * null): o update tem de virar [], nunca um mixed solto.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetDataComCorpoQueNaoEObjetoJsonVemVazio(): void
    {
        $telegram = new Telegram('123456:FAKE_TOKEN', false);

        $this->assertTrue(stream_wrapper_unregister('php'));
        try {
            FluxoPhpComCorpo::$corpo = '"apenas texto"';
            $this->assertTrue(stream_wrapper_register('php', FluxoPhpComCorpo::class));
            $dados = $telegram->getData();
        } finally {
            stream_wrapper_restore('php');
            FluxoPhpComCorpo::$corpo = FluxoPhpComCorpo::CORPO_PADRAO;
        }

        $this->assertSame([], $dados);
    }

    // === Text ===

    /**
     * @return array<string, array{array<string, mixed>, string|null}>
     */
    public static function textoProvider(): array
    {
        return [
            'message'            => [['message' => ['text' => 'oi']], 'oi'],
            'callback_query'     => [['callback_query' => ['id' => '1', 'data' => 'acao_x']], 'acao_x'],
            'channel_post'       => [['channel_post' => ['text' => 'post']], 'post'],
            'edited_message'     => [['edited_message' => ['text' => 'editado']], 'editado'],
            'message sem texto'  => [['message' => ['photo' => []]], null],
            'texto não-string'   => [['message' => ['text' => 123]], null],
            'texto "0"'          => [['message' => ['text' => '0']], '0'],
        ];
    }

    /**
     * @param array<string, mixed> $update
     */
    #[DataProvider('textoProvider')]
    public function testTextPorTipoDeUpdate(array $update, ?string $esperado): void
    {
        $this->telegram->setData($update);

        $this->assertSame($esperado, $this->telegram->Text());
    }

    // === Caption ===

    public function testCaptionDeMensagemComFoto(): void
    {
        $this->telegram->setData(['message' => ['photo' => [], 'caption' => 'foto legal']]);

        $this->assertSame('foto legal', $this->telegram->Caption());
    }

    public function testCaptionDeChannelPost(): void
    {
        $this->telegram->setData(['channel_post' => ['photo' => [], 'caption' => 'legenda do canal']]);

        $this->assertSame('legenda do canal', $this->telegram->Caption());
    }

    public function testCaptionAusenteVemNula(): void
    {
        $this->telegram->setData(['message' => ['text' => 'oi']]);

        $this->assertNull($this->telegram->Caption());
    }

    // === Chat / ChatID / MessageID por tipo de update ===

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function chatEMensagemProvider(): array
    {
        $chat = ['id' => -100123, 'type' => 'supergroup'];

        return [
            'message'        => [['message' => ['text' => 'oi', 'chat' => $chat, 'message_id' => 77]]],
            'callback_query' => [['callback_query' => ['id' => '1', 'message' => ['chat' => $chat, 'message_id' => 77]]]],
            'edited_message' => [['edited_message' => ['text' => 'x', 'chat' => $chat, 'message_id' => 77]]],
            'channel_post'   => [['channel_post' => ['text' => 'x', 'chat' => $chat, 'message_id' => 77]]],
        ];
    }

    /**
     * @param array<string, mixed> $update
     */
    #[DataProvider('chatEMensagemProvider')]
    public function testChatChatIDEMessageIDPorTipoDeUpdate(array $update): void
    {
        $this->telegram->setData($update);

        $this->assertSame(['id' => -100123, 'type' => 'supergroup'], $this->telegram->Chat());
        $this->assertSame(-100123, $this->telegram->ChatID());
        $this->assertSame(77, $this->telegram->MessageID());
    }

    public function testChatEmMyChatMember(): void
    {
        $this->telegram->setData(['my_chat_member' => ['chat' => ['id' => 42, 'type' => 'private']]]);

        $this->assertSame(['id' => 42, 'type' => 'private'], $this->telegram->Chat());
        $this->assertSame(42, $this->telegram->ChatID());
    }

    public function testChatNuloEmCallbackSemMessage(): void
    {
        $this->telegram->setData(['callback_query' => ['id' => '1']]);

        $this->assertNull($this->telegram->Chat());
        $this->assertNull($this->telegram->ChatID());
        $this->assertNull($this->telegram->MessageID());
    }

    public function testChatDescartaChavesNaoString(): void
    {
        // arrayOrNull() só preserva chaves string (objetos JSON da Bot API).
        $this->telegram->setData(['message' => ['text' => 'oi', 'chat' => [0 => 'lixo', 'id' => 5]]]);

        $this->assertSame(['id' => 5], $this->telegram->Chat());
    }

    public function testChatEMessageIDNulosEmMensagemSemChat(): void
    {
        $this->telegram->setData(['message' => ['text' => 'oi']]);

        $this->assertNull($this->telegram->Chat());
        $this->assertNull($this->telegram->ChatID());
        $this->assertNull($this->telegram->MessageID());
    }

    public function testChatNuloQuandoOCampoNaoEArray(): void
    {
        $this->telegram->setData(['message' => ['text' => 'oi', 'chat' => 'nao sou objeto']]);

        $this->assertNull($this->telegram->Chat());
        $this->assertNull($this->telegram->ChatID());
    }

    public function testChatIDNaoInteiroVemNulo(): void
    {
        $this->telegram->setData(['message' => ['text' => 'oi', 'chat' => ['id' => '123']]]);

        $this->assertNull($this->telegram->ChatID());
    }

    public function testAcessoresNaoQuebramComNivelIntermediarioNaoArray(): void
    {
        // dataPath prova cada nível antes de indexar: 'from' como string não
        // pode virar "Trying to access array offset on string".
        $this->telegram->setData([
            'callback_query' => ['id' => '1', 'from' => 'nao sou objeto', 'message' => 'idem'],
        ]);

        $this->assertNull($this->telegram->Callback_FromID());
        $this->assertNull($this->telegram->Callback_Message());
        $this->assertNull($this->telegram->Callback_ChatID());
        $this->assertNull($this->telegram->FirstName());
    }

    // === Família Callback_* ===

    public function testAcessoresDeCallbackQuery(): void
    {
        $mensagem = ['message_id' => 77, 'chat' => ['id' => -100123, 'type' => 'group']];
        $callback = [
            'id'      => 'cb99',
            'from'    => ['id' => 55, 'first_name' => 'Ana'],
            'message' => $mensagem,
            'data'    => 'confirmar',
        ];
        $this->telegram->setData(['update_id' => 10, 'callback_query' => $callback]);

        $this->assertSame($callback, $this->telegram->Callback_Query());
        $this->assertSame('cb99', $this->telegram->Callback_ID());
        $this->assertSame('confirmar', $this->telegram->Callback_Data());
        $this->assertSame($mensagem, $this->telegram->Callback_Message());
        $this->assertSame(-100123, $this->telegram->Callback_ChatID());
        $this->assertSame(55, $this->telegram->Callback_FromID());
        $this->assertSame('confirmar', $this->telegram->Text());
    }

    public function testAcessoresDeCallbackNulosSemCallback(): void
    {
        $this->telegram->setData(['message' => ['text' => 'oi']]);

        $this->assertNull($this->telegram->Callback_Query());
        $this->assertNull($this->telegram->Callback_ID());
        $this->assertNull($this->telegram->Callback_Data());
        $this->assertNull($this->telegram->Callback_Message());
        $this->assertNull($this->telegram->Callback_ChatID());
        $this->assertNull($this->telegram->Callback_FromID());
    }

    public function testCallbackIDNaoStringVemNulo(): void
    {
        // A Bot API envia o id do callback como string; int não deve vazar.
        $this->telegram->setData(['callback_query' => ['id' => 123]]);

        $this->assertNull($this->telegram->Callback_ID());
    }

    public function testCallbackDataNaoDependeDaClassificacaoDoUpdate(): void
    {
        // Diferente de Text(), Callback_Data() lê callback_query.data direto,
        // mesmo quando o update é classificado como outro tipo (inline_query
        // é o único checado antes de callback_query em getUpdateType()).
        $this->telegram->setData([
            'inline_query'   => ['id' => '1', 'query' => 'q'],
            'callback_query' => ['data' => 'direto'],
        ]);

        $this->assertSame(Telegram::INLINE_QUERY, $this->telegram->getUpdateType());
        $this->assertNull($this->telegram->Text());
        $this->assertSame('direto', $this->telegram->Callback_Data());
    }

    /**
     * InlineQuery não tem campo `chat` — só `from`, que é um User. O retorno
     * precisa ser null POR CLASSIFICAÇÃO, e não por acaso: um payload que
     * traga `message` junto não pode fazer o Chat() de uma inline query
     * responder com o chat da mensagem, ou a resposta iria para a conversa
     * errada. Para o id de quem consultou existe UserID().
     */
    public function testChatENuloEmInlineQueryMesmoComMessageChatNoPayload(): void
    {
        $this->telegram->setData([
            'inline_query' => ['id' => '1', 'from' => ['id' => 7], 'query' => 'q'],
            'message'      => ['text' => 'oi', 'chat' => ['id' => 4242]],
        ]);

        $this->assertSame(Telegram::INLINE_QUERY, $this->telegram->getUpdateType());
        $this->assertNull($this->telegram->Chat());
        $this->assertNull($this->telegram->ChatID());
        $this->assertSame(7, $this->telegram->UserID());
    }

    // === Inline_Query ===

    public function testInlineQueryDevolveOPayloadCompleto(): void
    {
        $inline = ['id' => '9', 'from' => ['id' => 7], 'query' => 'buscar'];
        $this->telegram->setData(['inline_query' => $inline]);

        $this->assertSame($inline, $this->telegram->Inline_Query());
    }

    public function testInlineQueryNulaEmUpdateDeMensagem(): void
    {
        $this->telegram->setData(['message' => ['text' => 'oi']]);

        $this->assertNull($this->telegram->Inline_Query());
    }

    // === Remetente (FirstName/LastName/Username/UserID) por tipo ===

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function remetenteProvider(): array
    {
        $from = ['id' => 55, 'first_name' => 'Ana', 'last_name' => 'Souza', 'username' => 'ana_s'];

        return [
            'message'        => [['message' => ['text' => 'oi', 'from' => $from]]],
            'callback_query' => [['callback_query' => ['id' => '1', 'from' => $from]]],
            'edited_message' => [['edited_message' => ['text' => 'x', 'from' => $from]]],
            'channel_post'   => [['channel_post' => ['text' => 'x', 'from' => $from]]],
        ];
    }

    /**
     * @param array<string, mixed> $update
     */
    #[DataProvider('remetenteProvider')]
    public function testDadosDoRemetentePorTipoDeUpdate(array $update): void
    {
        $this->telegram->setData($update);

        $this->assertSame('Ana', $this->telegram->FirstName());
        $this->assertSame('Souza', $this->telegram->LastName());
        $this->assertSame('ana_s', $this->telegram->Username());
        $this->assertSame(55, $this->telegram->UserID());
    }

    public function testUserIDNaoInteiroVemNulo(): void
    {
        $this->telegram->setData(['message' => ['text' => 'x', 'from' => ['id' => '55']]]);

        $this->assertNull($this->telegram->UserID());
    }

    // === Date / UpdateID / Location / ReplyToMessageID ===

    public function testDateEUpdateID(): void
    {
        $this->telegram->setData(['update_id' => 987, 'message' => ['text' => 'oi', 'date' => 1700000000]]);

        $this->assertSame(1700000000, $this->telegram->Date());
        $this->assertSame(987, $this->telegram->UpdateID());
    }

    public function testDateEUpdateIDNaoInteirosVemNulos(): void
    {
        $this->telegram->setData(['update_id' => '987', 'message' => ['text' => 'oi', 'date' => '1700000000']]);

        $this->assertNull($this->telegram->Date());
        $this->assertNull($this->telegram->UpdateID());
    }

    public function testDateNuloForaDeMensagem(): void
    {
        // Date() lê sempre message.date; um callback_query não tem esse campo.
        $this->telegram->setData(['callback_query' => ['id' => '1']]);

        $this->assertNull($this->telegram->Date());
    }

    public function testLocationDevolveOPayloadCompleto(): void
    {
        $local = ['latitude' => -23.5, 'longitude' => -46.6];
        $this->telegram->setData(['message' => ['location' => $local]]);

        $this->assertSame($local, $this->telegram->Location());
    }

    public function testLocationNulaSemLocalizacao(): void
    {
        $this->telegram->setData(['message' => ['text' => 'oi']]);

        $this->assertNull($this->telegram->Location());
    }

    public function testReplyToMessageID(): void
    {
        $this->telegram->setData(['message' => ['reply_to_message' => ['message_id' => 31]]]);

        $this->assertSame(31, $this->telegram->ReplyToMessageID());
    }

    public function testReplyToMessageIDNuloSemResposta(): void
    {
        $this->telegram->setData(['message' => ['text' => 'oi']]);

        $this->assertNull($this->telegram->ReplyToMessageID());
    }

    // === getContactPhoneNumber ===

    public function testGetContactPhoneNumber(): void
    {
        $this->telegram->setData(['message' => ['contact' => ['phone_number' => '+5511999999999']]]);

        $this->assertSame('+5511999999999', $this->telegram->getContactPhoneNumber());
    }

    public function testGetContactPhoneNumberVazioForaDeContato(): void
    {
        $this->telegram->setData(['message' => ['text' => 'oi']]);

        $this->assertSame('', $this->telegram->getContactPhoneNumber());
    }

    public function testGetContactPhoneNumberVazioSemNumero(): void
    {
        $this->telegram->setData(['message' => ['contact' => ['first_name' => 'X']]]);

        $this->assertSame('', $this->telegram->getContactPhoneNumber());
    }

    public function testGetContactPhoneNumberVazioQuandoNumeroNaoEString(): void
    {
        $this->telegram->setData(['message' => ['contact' => ['phone_number' => 5511999999999]]]);

        $this->assertSame('', $this->telegram->getContactPhoneNumber());
    }

    // === messageFromGroupTitle ===

    public function testMessageFromGroupTitleEmGrupo(): void
    {
        $this->telegram->setData(['message' => ['chat' => ['type' => 'group', 'title' => 'Meu Grupo']]]);

        $this->assertSame('Meu Grupo', $this->telegram->messageFromGroupTitle());
    }

    public function testMessageFromGroupTitleVazioEmChatPrivado(): void
    {
        $this->telegram->setData(['message' => ['chat' => ['type' => 'private', 'title' => 'ignorado']]]);

        $this->assertSame('', $this->telegram->messageFromGroupTitle());
    }

    public function testMessageFromGroupTitleVazioSemTitulo(): void
    {
        $this->telegram->setData(['message' => ['chat' => ['type' => 'group']]]);

        $this->assertSame('', $this->telegram->messageFromGroupTitle());
    }

    public function testMessageFromGroupTitleVazioQuandoOTituloNaoEString(): void
    {
        $this->telegram->setData(['message' => ['chat' => ['type' => 'group', 'title' => 123]]]);

        $this->assertSame('', $this->telegram->messageFromGroupTitle());
    }

    // === UpdateCount ===

    public function testUpdateCountZeroSemGetUpdates(): void
    {
        $this->assertSame(0, $this->telegram->UpdateCount());
    }

    public function testUpdateCountContaApenasChavesInteiras(): void
    {
        // Mesmo padrão de injeção via reflexão usado em TelegramUpdateTypeTest:
        // popula a lista interna sem passar por rede.
        $reflection = new \ReflectionClass($this->telegram);
        $updates = $reflection->getProperty('updates');
        $updates->setValue($this->telegram, [
            'ok'     => true,
            'result' => [
                0      => ['update_id' => 1],
                1      => ['update_id' => 2],
                'meta' => 'ignorada',
            ],
        ]);

        $this->assertSame(2, $this->telegram->UpdateCount());
    }

    // === respondSuccess ===

    /**
     * Em processo separado nenhuma saída foi emitida ainda, então
     * http_response_code(200) não dispara o aviso "headers already sent".
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRespondSuccessDevolveJsonDeSucessoEDefineOStatus(): void
    {
        $telegram = new Telegram('123456:FAKE_TOKEN', false);

        $this->assertSame('{"status":"success"}', $telegram->respondSuccess());
        $this->assertSame(200, http_response_code());
    }

    public function testRespondSuccessDevolveJsonExatoNoProcessoPrincipal(): void
    {
        // O teste isolado abaixo também confere o status HTTP. Esta asserção
        // fica no processo principal para que a cobertura usada na seleção de
        // testes associe respondSuccess() a um teste rápido e determinístico.
        $this->assertSame('{"status":"success"}', $this->telegram->respondSuccess());
    }
}

/**
 * Dublê de wrapper de stream "php" que recusa toda abertura.
 *
 * stream_wrapper_register() exige uma classe NOMEADA (não aceita anônima) e o
 * autoload-dev PSR-4 não precisa alcançá-la: é definida junto do único teste
 * que a usa. Os métodos em snake_case são o contrato fixo da API de wrappers.
 */
/**
 * Wrapper "php" que serve um corpo fixo para php://input, imitando o POST do
 * webhook do Telegram — no CLI o php://input real é sempre vazio.
 */
final class FluxoPhpComCorpo
{
    public const CORPO_PADRAO = '{"update_id":77,"message":{"text":"do corpo","chat":{"id":-100}}}';

    public static string $corpo = self::CORPO_PADRAO;

    /** @var resource|null Preenchido pelo PHP; obrigatório na API de wrappers. */
    public $context;

    private int $posicao = 0;

    /**
     * @param string $caminho
     * @param string $modo
     * @param int $opcoes
     * @param string|null $caminho_aberto
     */
    public function stream_open($caminho, $modo, $opcoes, &$caminho_aberto): bool
    {
        $this->posicao = 0;

        return true;
    }

    /**
     * @param int $quantidade
     */
    public function stream_read($quantidade): string
    {
        $trecho = substr(self::$corpo, $this->posicao, $quantidade);
        $this->posicao += strlen($trecho);

        return $trecho;
    }

    public function stream_eof(): bool
    {
        return $this->posicao >= strlen(self::$corpo);
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}

final class FluxoPhpRecusado
{
    /** @var resource|null Preenchido pelo PHP; obrigatório na API de wrappers. */
    public $context;

    /**
     * @param string $caminho
     * @param string $modo
     * @param int $opcoes
     * @param string|null $caminho_aberto
     */
    public function stream_open($caminho, $modo, $opcoes, &$caminho_aberto): bool
    {
        return false;
    }
}
