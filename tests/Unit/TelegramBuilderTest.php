<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';

/**
 * Testes dos métodos PUROS de construção de teclados/botões da classe Telegram.
 *
 * Estes métodos apenas montam arrays/JSON e NÃO fazem requisições à API.
 * O construtor recebe um token fake; getData() lê php://input (vazio no CLI),
 * portanto não há efeito de rede.
 *
 * Contratos fixados aqui:
 *   1. os campos opcionais dos botões são MUTUAMENTE EXCLUSIVOS (cadeia de
 *      elseif) e a ordem de precedência é parte do contrato;
 *   2. só danger/success/primary são estilos válidos;
 *   3. quando json_encode falha (texto de botão com UTF-8 inválido vindo de
 *      nome de usuário, por exemplo), os builders devolvem '{}' em vez de
 *      false — quem envia recebe um reply_markup vazio, não um erro de tipo.
 */
final class TelegramBuilderTest extends TestCase
{
    /** Sequência de bytes que não forma UTF-8 válido: json_encode devolve false. */
    private const BYTES_INVALIDOS = "\xB1\x31";

    private Telegram $telegram;

    protected function setUp(): void
    {
        // Token fake + log_errors=false: nenhuma chamada de rede é feita.
        $this->telegram = new Telegram('123456:FAKE_TOKEN', false);
    }

    /**
     * Decodifica o JSON de um builder e devolve o array já narrowado.
     *
     * @return array<string, mixed>
     */
    private function decodificar(string $json): array
    {
        $this->assertJson($json);
        $decodificado = json_decode($json, true);
        $this->assertIsArray($decodificado);

        $narrowado = [];
        foreach ($decodificado as $chave => $valor) {
            $this->assertIsString($chave);
            $narrowado[$chave] = $valor;
        }

        return $narrowado;
    }

    // === buildKeyBoard ===

    public function testBuildKeyBoardEstruturaJson(): void
    {
        $opcoes = [['A', 'B'], ['C']];
        $json = $this->telegram->buildKeyBoard($opcoes, true, true, false);

        // Estrutura completa: uma chave inesperada no JSON também falha aqui.
        $this->assertSame([
            'keyboard'          => $opcoes,
            'one_time_keyboard' => true,
            'resize_keyboard'   => true,
            'selective'         => false,
            'is_persistent'     => false,
        ], $this->decodificar($json));
    }

    public function testBuildKeyBoardPadroes(): void
    {
        $decoded = $this->decodificar($this->telegram->buildKeyBoard([['X']]));

        $this->assertFalse($decoded['one_time_keyboard']);
        $this->assertFalse($decoded['resize_keyboard']);
        $this->assertFalse($decoded['selective']);
        $this->assertFalse($decoded['is_persistent']);
        $this->assertArrayNotHasKey('input_field_placeholder', $decoded);
    }

    public function testBuildKeyBoardComSelectivePersistenteEPlaceholder(): void
    {
        $decoded = $this->decodificar(
            $this->telegram->buildKeyBoard([['X']], false, false, true, true, 'Digite algo')
        );

        $this->assertTrue($decoded['selective']);
        $this->assertTrue($decoded['is_persistent']);
        $this->assertFalse($decoded['one_time_keyboard']);
        $this->assertFalse($decoded['resize_keyboard']);
        $this->assertSame('Digite algo', $decoded['input_field_placeholder']);
    }

    public function testBuildKeyBoardDevolveObjetoVazioQuandoOJsonFalha(): void
    {
        $json = $this->telegram->buildKeyBoard([[self::BYTES_INVALIDOS]]);

        $this->assertSame('{}', $json);
    }

    // === buildInlineKeyboard ===

    public function testBuildInlineKeyBoardEnvolveEmInlineKeyboard(): void
    {
        $linhas = [
            [['text' => 'Btn', 'callback_data' => 'x']],
        ];

        $decoded = $this->decodificar($this->telegram->buildInlineKeyboard($linhas));

        $this->assertSame(['inline_keyboard' => $linhas], $decoded);
    }

    public function testBuildInlineKeyBoardDevolveObjetoVazioQuandoOJsonFalha(): void
    {
        $json = $this->telegram->buildInlineKeyboard([[['text' => self::BYTES_INVALIDOS]]]);

        $this->assertSame('{}', $json);
    }

    // === buildInlineKeyboardButton ===

    public function testBuildInlineKeyboardButtonSomenteTexto(): void
    {
        $botao = $this->telegram->buildInlineKeyboardButton('SoTexto');

        $this->assertSame(['text' => 'SoTexto'], $botao);
    }

    public function testBuildInlineKeyboardButtonComUrl(): void
    {
        $botao = $this->telegram->buildInlineKeyboardButton('Abrir', 'https://exemplo.com');

        $this->assertSame(['text' => 'Abrir', 'url' => 'https://exemplo.com'], $botao);
    }

    public function testBuildInlineKeyboardButtonComCallbackData(): void
    {
        $botao = $this->telegram->buildInlineKeyboardButton('Comprar', '', 'comprar_1');

        $this->assertSame(['text' => 'Comprar', 'callback_data' => 'comprar_1'], $botao);
    }

    public function testBuildInlineKeyboardButtonComSwitchInlineQuery(): void
    {
        $botao = $this->telegram->buildInlineKeyboardButton('Compartilhar', '', '', 'consulta');

        $this->assertSame(['text' => 'Compartilhar', 'switch_inline_query' => 'consulta'], $botao);
    }

    public function testBuildInlineKeyboardButtonAceitaSwitchInlineQueryVazio(): void
    {
        // '' é válido na API (insere só o @username do bot); apenas null omite o campo.
        $botao = $this->telegram->buildInlineKeyboardButton('Compartilhar', '', '', '');

        $this->assertSame(['text' => 'Compartilhar', 'switch_inline_query' => ''], $botao);
    }

    public function testBuildInlineKeyboardButtonComSwitchInlineQueryCurrentChat(): void
    {
        $botao = $this->telegram->buildInlineKeyboardButton('Aqui', '', '', null, 'busca');

        $this->assertSame(['text' => 'Aqui', 'switch_inline_query_current_chat' => 'busca'], $botao);
    }

    public function testBuildInlineKeyboardButtonAceitaSwitchInlineQueryCurrentChatVazio(): void
    {
        $botao = $this->telegram->buildInlineKeyboardButton('Aqui', '', '', null, '');

        $this->assertSame(['text' => 'Aqui', 'switch_inline_query_current_chat' => ''], $botao);
    }

    public function testBuildInlineKeyboardButtonComCallbackGame(): void
    {
        $botao = $this->telegram->buildInlineKeyboardButton('Jogar', '', '', null, null, 'jogo');

        $this->assertSame(['text' => 'Jogar', 'callback_game' => 'jogo'], $botao);
    }

    public function testBuildInlineKeyboardButtonComPay(): void
    {
        $botao = $this->telegram->buildInlineKeyboardButton('Pagar', '', '', null, null, '', true);

        $this->assertSame(['text' => 'Pagar', 'pay' => true], $botao);
    }

    public function testBuildInlineKeyboardButtonIgnoraPayFalse(): void
    {
        // false é o "não informado" de $pay: um botão de pagamento acidental
        // muda o comportamento do Telegram (exige provider de pagamento).
        $botao = $this->telegram->buildInlineKeyboardButton('Comum', '', '', null, null, '', false);

        $this->assertSame(['text' => 'Comum'], $botao);
    }

    public function testBuildInlineKeyboardButtonComCopyText(): void
    {
        // CopyTextButton (Bot API 8.0+): usado no PIX Copia-e-Cola.
        $botao = $this->telegram->buildInlineKeyboardButton('Copiar PIX', '', '', null, null, '', '', '', '', '000201265...');

        $this->assertSame(['text' => 'Copiar PIX', 'copy_text' => ['text' => '000201265...']], $botao);
    }

    public function testBuildInlineKeyboardButtonComWebApp(): void
    {
        $botao = $this->telegram->buildInlineKeyboardButton('Abrir', '', '', null, null, '', '', '', '', '', 'https://exemplo.com/app');

        $this->assertSame(['text' => 'Abrir', 'web_app' => ['url' => 'https://exemplo.com/app']], $botao);
    }

    /**
     * Precedência da cadeia de elseif: exatamente UM campo opcional sobrevive,
     * e é sempre o primeiro preenchido na ordem declarada. Cada caso preenche
     * dois opcionais adjacentes e espera só o primeiro.
     *
     * @return array<string, array{string|null, string, string|null, string|null, string, bool|string, string, string, array<string, mixed>}>
     */
    public static function precedenciaInlineProvider(): array
    {
        return [
            'url sobre callback_data' => [
                'https://exemplo.com', 'cb_ignorado', null, null, '', '', '', '',
                ['url' => 'https://exemplo.com'],
            ],
            'callback_data sobre switch' => [
                '', 'acao', 'sw_ignorado', null, '', '', '', '',
                ['callback_data' => 'acao'],
            ],
            'switch sobre switch_current_chat' => [
                '', '', 'sw', 'sw_atual_ignorado', '', '', '', '',
                ['switch_inline_query' => 'sw'],
            ],
            'switch_current_chat sobre callback_game' => [
                '', '', null, 'sw_atual', 'jogo_ignorado', '', '', '',
                ['switch_inline_query_current_chat' => 'sw_atual'],
            ],
            'callback_game sobre pay' => [
                '', '', null, null, 'jogo', true, '', '',
                ['callback_game' => 'jogo'],
            ],
            'pay sobre copy_text' => [
                '', '', null, null, '', true, 'copia_ignorada', '',
                ['pay' => true],
            ],
            'copy_text sobre web_app' => [
                '', '', null, null, '', '', 'copia', 'https://ignorado',
                ['copy_text' => ['text' => 'copia']],
            ],
        ];
    }

    /**
     * @param bool|string $pay
     * @param array<string, mixed> $campoEsperado
     */
    #[DataProvider('precedenciaInlineProvider')]
    public function testBuildInlineKeyboardButtonRespeitaAPrecedenciaDosOpcionais(
        ?string $url,
        string $callbackData,
        ?string $switchInline,
        ?string $switchInlineAtual,
        string $callbackGame,
        $pay,
        string $copyText,
        string $webAppUrl,
        array $campoEsperado
    ): void {
        $botao = $this->telegram->buildInlineKeyboardButton(
            'B',
            $url,
            $callbackData,
            $switchInline,
            $switchInlineAtual,
            $callbackGame,
            $pay,
            '',
            '',
            $copyText,
            $webAppUrl
        );

        $this->assertSame(array_merge(['text' => 'B'], $campoEsperado), $botao);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function estiloValidoProvider(): array
    {
        return [
            'danger'  => ['danger'],
            'success' => ['success'],
            'primary' => ['primary'],
        ];
    }

    #[DataProvider('estiloValidoProvider')]
    public function testBuildInlineKeyboardButtonComEstiloValido(string $estilo): void
    {
        $botao = $this->telegram->buildInlineKeyboardButton('B', 'https://e.x', '', null, null, '', '', $estilo);

        $this->assertSame(['text' => 'B', 'url' => 'https://e.x', 'style' => $estilo], $botao);
    }

    public function testBuildInlineKeyboardButtonIgnoraEstiloInvalido(): void
    {
        $botao = $this->telegram->buildInlineKeyboardButton('B', 'https://e.x', '', null, null, '', '', 'azul');

        $this->assertSame(['text' => 'B', 'url' => 'https://e.x'], $botao);
    }

    public function testBuildInlineKeyboardButtonComEstiloEIcone(): void
    {
        $botao = $this->telegram->buildInlineKeyboardButton(
            'B',
            '',
            'cb',
            null,
            null,
            '',
            '',
            'danger',
            '5368324170671202286'
        );

        // Ordem fixa das chaves: opcional exclusivo, depois style, depois ícone.
        $this->assertSame(
            [
                'text'                 => 'B',
                'callback_data'        => 'cb',
                'style'                => 'danger',
                'icon_custom_emoji_id' => '5368324170671202286',
            ],
            $botao
        );
    }

    // === buildKeyboardButton ===

    public function testBuildKeyboardButtonPadrao(): void
    {
        $botao = $this->telegram->buildKeyboardButton('Texto');

        $this->assertSame(['text' => 'Texto'], $botao);
    }

    public function testBuildKeyboardButtonComContato(): void
    {
        $botao = $this->telegram->buildKeyboardButton('Contato', true, false);

        $this->assertSame(['text' => 'Contato', 'request_contact' => true], $botao);
    }

    public function testBuildKeyboardButtonComLocalizacao(): void
    {
        $botao = $this->telegram->buildKeyboardButton('Local', false, true);

        $this->assertSame(['text' => 'Local', 'request_location' => true], $botao);
    }

    public function testBuildKeyboardButtonContatoTemPrioridadeSobreLocalizacao(): void
    {
        // A Bot API aceita no máximo UM de request_contact/request_location por
        // botão; mandar os dois faz o Telegram rejeitar o teclado inteiro.
        $botao = $this->telegram->buildKeyboardButton('Ambos', true, true);

        $this->assertSame(['text' => 'Ambos', 'request_contact' => true], $botao);
    }

    public function testBuildKeyboardButtonComEstiloEIcone(): void
    {
        $botao = $this->telegram->buildKeyboardButton('OK', false, false, 'danger', 'emoji9');

        $this->assertSame(
            ['text' => 'OK', 'style' => 'danger', 'icon_custom_emoji_id' => 'emoji9'],
            $botao
        );
    }

    public function testBuildKeyboardButtonIgnoraEstiloInvalido(): void
    {
        $botao = $this->telegram->buildKeyboardButton('OK', false, false, 'amarelo');

        $this->assertSame(['text' => 'OK'], $botao);
    }

    public function testBuildKeyboardButtonOrdemStyleIconIgualAoInline(): void
    {
        // Os dois builders de botão têm os dois últimos parâmetros na mesma
        // ordem: $style, depois $icon_custom_emoji_id.
        $normal = $this->telegram->buildKeyboardButton('OK', false, false, 'danger', '555');
        $inline = $this->telegram->buildInlineKeyboardButton('OK', '', 'x', null, null, '', '', 'danger', '555');

        $this->assertSame('danger', $normal['style']);
        $this->assertSame('555', $normal['icon_custom_emoji_id']);
        $this->assertSame($normal['style'], $inline['style']);
        $this->assertSame($normal['icon_custom_emoji_id'], $inline['icon_custom_emoji_id']);
    }

    // === buildKeyBoardHide / buildForceReply ===

    public function testBuildKeyBoardHide(): void
    {
        $this->assertSame(
            ['remove_keyboard' => true, 'selective' => false],
            $this->decodificar($this->telegram->buildKeyBoardHide(false))
        );
    }

    public function testBuildKeyBoardHideSeletivo(): void
    {
        $this->assertSame(
            ['remove_keyboard' => true, 'selective' => true],
            $this->decodificar($this->telegram->buildKeyBoardHide(true))
        );
    }

    public function testBuildForceReply(): void
    {
        $this->assertSame(
            ['force_reply' => true, 'selective' => false],
            $this->decodificar($this->telegram->buildForceReply())
        );
    }

    public function testBuildForceReplyComPlaceholder(): void
    {
        $this->assertSame(
            ['force_reply' => true, 'selective' => true, 'input_field_placeholder' => 'Responda aqui'],
            $this->decodificar($this->telegram->buildForceReply(true, 'Responda aqui'))
        );
    }

    public function testBuildForceReplyDevolveObjetoVazioQuandoOJsonFalha(): void
    {
        $json = $this->telegram->buildForceReply(false, self::BYTES_INVALIDOS);

        $this->assertSame('{}', $json);
    }

    public function testBuildInlineKeyboardButtonAceitaPayStringZero(): void
    {
        // $pay é bool|string por contrato. Só false e '' significam "não
        // informado"; "0" é valor informado e continua chegando ao Telegram.
        $botao = $this->telegram->buildInlineKeyboardButton('Pagar', '', '', null, null, '', '0');

        $this->assertSame(['text' => 'Pagar', 'pay' => '0'], $botao);
    }

    public function testBuildInlineKeyboardButtonAceitaUrlStringZero(): void
    {
        // Fronteira do falsy: "0" é uma url informada, ainda que inútil. Se a
        // guarda voltar a ser frouxa, o callback_data assume o botão em silêncio.
        $botao = $this->telegram->buildInlineKeyboardButton('Comum', '0', 'cb');

        $this->assertSame(['text' => 'Comum', 'url' => '0'], $botao);
    }

    public function testBuildInlineKeyboardButtonIgnoraUrlNula(): void
    {
        // $url é ?string e o padrão é null: o "não informado" tem que sair da
        // cadeia de elseif sem virar um botão com url vazia.
        $botao = $this->telegram->buildInlineKeyboardButton('Comum', null, 'cb');

        $this->assertSame(['text' => 'Comum', 'callback_data' => 'cb'], $botao);
    }

    /**
     * Sem argumento o teclado sai para TODO MUNDO no chat. O padrão `false` é o
     * da própria Bot API, e a chamada sem argumento é a forma usada em quase
     * todo comando: um padrão invertido esconderia o teclado só de quem foi
     * mencionado, e o resto do grupo ficaria com botão morto na tela.
     */
    public function testBuildKeyBoardHideSemArgumentoNaoESeletivo(): void
    {
        $this->assertSame(
            ['remove_keyboard' => true, 'selective' => false],
            $this->decodificar($this->telegram->buildKeyBoardHide())
        );
    }
}
