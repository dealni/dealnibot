<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';

/**
 * Testes dos wrappers de API da classe Telegram.
 *
 * endpoint() é público, então uma subclasse dublê intercepta as chamadas e
 * devolve uma resposta pronta — nenhum teste faz rede. Cada wrapper "fino"
 * (public function X(array $content) => endpoint('X', $content)) deve:
 *   1. chamar o endpoint HOMÔNIMO da Bot API (o nome do método é o contrato);
 *   2. repassar $content sem modificação;
 *   3. usar POST;
 *   4. devolver a resposta decodificada tal como veio do endpoint.
 *
 * A constante WRAPPERS_SIMPLES é conferida contra a própria classe em
 * testTodoWrapperFinoDaClasseEstaNaListaEChamaSeuHomonimo: os wrappers são
 * descobertos por reflexão e EXECUTADOS, então wrapper novo (ou removido) sem
 * atualizar a lista falha lá.
 */
final class TelegramEndpointsTest extends TestCase
{
    /** Wrappers finos onde método e endpoint têm o mesmo nome (todos POST). */
    private const WRAPPERS_SIMPLES = [
        'sendMessage',
        'copyMessage',
        'forwardMessage',
        'forwardMessages',
        'copyMessages',
        'sendPhoto',
        'sendLivePhoto',
        'sendAudio',
        'sendDocument',
        'sendAnimation',
        'sendSticker',
        'sendVideo',
        'sendPaidMedia',
        'sendVoice',
        'sendLocation',
        'editMessageLiveLocation',
        'stopMessageLiveLocation',
        'setChatStickerSet',
        'deleteChatStickerSet',
        'sendMediaGroup',
        'sendVenue',
        'sendContact',
        'sendPoll',
        'sendDice',
        'sendChatAction',
        'getUserProfilePhotos',
        'leaveChat',
        'banChatMember',
        'unbanChatMember',
        'getChat',
        'getChatAdministrators',
        'getChatMemberCount',
        'getChatMember',
        'answerInlineQuery',
        'answerWebAppQuery',
        'savePreparedInlineMessage',
        'setGameScore',
        'getGameHighScores',
        'answerCallbackQuery',
        'setMyCommands',
        'deleteMyCommands',
        'getMyCommands',
        'setMyName',
        'getMyName',
        'setMyDescription',
        'getMyDescription',
        'setMyShortDescription',
        'getMyShortDescription',
        'setChatMenuButton',
        'getChatMenuButton',
        'setMyDefaultAdministratorRights',
        'getMyDefaultAdministratorRights',
        'editMessageText',
        'editMessageCaption',
        'editMessageMedia',
        'editMessageReplyMarkup',
        'stopPoll',
        'sendInvoice',
        'answerShippingQuery',
        'answerPreCheckoutQuery',
        'createInvoiceLink',
        'setPassportDataErrors',
        'sendGame',
        'sendVideoNote',
        'restrictChatMember',
        'promoteChatMember',
        'setChatAdministratorCustomTitle',
        'banChatSenderChat',
        'unbanChatSenderChat',
        'setChatPermissions',
        'exportChatInviteLink',
        'createChatInviteLink',
        'editChatInviteLink',
        'revokeChatInviteLink',
        'createChatSubscriptionInviteLink',
        'editChatSubscriptionInviteLink',
        'approveChatJoinRequest',
        'declineChatJoinRequest',
        'answerChatJoinRequestQuery',
        'sendChatJoinRequestWebApp',
        'setChatPhoto',
        'deleteChatPhoto',
        'setChatTitle',
        'setChatDescription',
        'pinChatMessage',
        'unpinChatMessage',
        'unpinAllChatMessages',
        'getStickerSet',
        'uploadStickerFile',
        'createNewStickerSet',
        'addStickerToSet',
        'setStickerPositionInSet',
        'deleteStickerFromSet',
        'replaceStickerInSet',
        'setStickerEmojiList',
        'setStickerKeywords',
        'setStickerMaskPosition',
        'setStickerSetTitle',
        'setStickerSetThumbnail',
        'setCustomEmojiStickerSetThumbnail',
        'deleteStickerSet',
        'deleteMessage',
        'deleteMessages',
        'sendChecklist',
        'editMessageChecklist',
        'sendMessageDraft',
        'approveSuggestedPost',
        'declineSuggestedPost',
        'sendGift',
        'giftPremiumSubscription',
        'getUserGifts',
        'getChatGifts',
        'convertGiftToStars',
        'upgradeGift',
        'transferGift',
        'postStory',
        'repostStory',
        'editStory',
        'deleteStory',
        'readBusinessMessage',
        'deleteBusinessMessages',
        'setBusinessAccountName',
        'setBusinessAccountUsername',
        'setBusinessAccountBio',
        'setBusinessAccountProfilePhoto',
        'removeBusinessAccountProfilePhoto',
        'setBusinessAccountGiftSettings',
        'getBusinessAccountStarBalance',
        'transferBusinessAccountStars',
        'getBusinessAccountGifts',
        'getBusinessConnection',
        'getStarTransactions',
        'refundStarPayment',
        'editUserStarSubscription',
        'verifyUser',
        'verifyChat',
        'removeUserVerification',
        'removeChatVerification',
        'createForumTopic',
        'editForumTopic',
        'closeForumTopic',
        'reopenForumTopic',
        'deleteForumTopic',
        'unpinAllForumTopicMessages',
        'editGeneralForumTopic',
        'closeGeneralForumTopic',
        'reopenGeneralForumTopic',
        'hideGeneralForumTopic',
        'unhideGeneralForumTopic',
        'unpinAllGeneralForumTopicMessages',
        'setUserEmojiStatus',
        'getCustomEmojiStickers',
        'getUserChatBoosts',
        'setMessageReaction',
        'deleteMessageReaction',
        'deleteAllMessageReactions',
        'setChatMemberTag',
        'getUserProfileAudios',
        'setMyProfilePhoto',
        'getManagedBotToken',
        'replaceManagedBotToken',
        'getManagedBotAccessSettings',
        'setManagedBotAccessSettings',
        'getUserPersonalChatMessages',
        'answerGuestQuery',
        'sendRichMessage',
        'sendRichMessageDraft',
        'editEphemeralMessageText',
        'editEphemeralMessageMedia',
        'editEphemeralMessageCaption',
        'editEphemeralMessageReplyMarkup',
        'deleteEphemeralMessage',
        'savePreparedKeyboardButton',
    ];

    /** @return Telegram&object{chamadas: array<int, array{api: string, content: array<string, mixed>, post: bool}>} */
    private function duble()
    {
        return new class ('123456:FAKE_TOKEN', false) extends Telegram {
            /** @var array<int, array{api: string, content: array<string, mixed>, post: bool}> */
            public array $chamadas = [];

            /**
             * @param string $api
             * @param array<string, mixed> $content
             * @param bool $post
             * @return array{ok: bool, result?: mixed, description?: string, error_code?: int, parameters?: array<string, mixed>}
             */
            public function endpoint(string $api, array $content, bool $post = true): array
            {
                $this->chamadas[] = ['api' => $api, 'content' => $content, 'post' => $post];

                // O nome do endpoint volta em 'result' para que o teste dos
                // wrappers distinga a resposta de CADA um deles.
                return ['ok' => true, 'result' => $api];
            }
        };
    }

    public function testGetMeLogOutECloseUsamGetSemParametros(): void
    {
        $duble = $this->duble();

        $duble->getMe();
        $duble->logOut();
        $duble->close();

        $this->assertSame([
            ['api' => 'getMe', 'content' => [], 'post' => false],
            ['api' => 'logOut', 'content' => [], 'post' => false],
            ['api' => 'close', 'content' => [], 'post' => false],
        ], $duble->chamadas);
    }

    public function testTransporteDefineLimitesDeConexaoEDuracaoTotal(): void
    {
        // As constantes são lidas por reflexão, e não escritas como
        // `Telegram::CONNECT_TIMEOUT_SECONDS`: o compilador substitui a
        // referência pelo literal, e a asserção passaria a comparar 10 com 10
        // — vazia para quem lê e para a análise estática. Por reflexão o valor
        // vem do arquivo, que é o que este teste tranca.
        $constantes = (new ReflectionClass(Telegram::class))->getConstants();
        $this->assertSame(10, $constantes['CONNECT_TIMEOUT_SECONDS'] ?? null);
        // 15s, não 60s: com o teto antigo uma janela de instabilidade do
        // Telegram segurava os filhos do PHP-FPM até a pool fechar, e o bot
        // emudecia inteiro. Ver o docblock da constante.
        $this->assertSame(15, $constantes['REQUEST_TIMEOUT_SECONDS'] ?? null);
        $this->assertSame(10, $constantes['LONG_POLLING_MARGIN_SECONDS'] ?? null);

        // As opções são verificadas pelo valor aplicado ao cURL, retornado
        // por opcoesDaRequisicao(), para que remover uma delas reprove o teste.
        $opcoes = (new TelegramOpcoesExpostas('000000:TEST_TOKEN', false))
            ->opcoesDe('https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage', ['text' => 'oi'], true);

        $this->assertSame(CURL_IPRESOLVE_V4, $opcoes[CURLOPT_IPRESOLVE] ?? null);
        $this->assertSame(10, $opcoes[CURLOPT_CONNECTTIMEOUT] ?? null);
        // O teto total não é a constante crua: quem decide é
        // timeoutDaRequisicaoEmSegundos(), para o long polling não morrer no
        // teto curto. Aqui não há long polling, então vale o teto curto.
        $this->assertSame(15, $opcoes[CURLOPT_TIMEOUT] ?? null);
        $this->assertSame(60, (new TelegramOpcoesExpostas('000000:TEST_TOKEN', false))
            ->opcoesDe('https://api.telegram.org/bot000000:TEST_TOKEN/getUpdates', ['timeout' => 50], true)[CURLOPT_TIMEOUT] ?? null);
    }

    public function testDemaisEndpointsGetSemParametros(): void
    {
        // Endpoints GET restantes que não recebem conteúdo.
        $duble = $this->duble();

        $duble->deleteWebhook();
        $duble->getWebhookInfo();
        $duble->getAvailableGifts();
        $duble->getMyStarBalance();
        $duble->getForumTopicIconStickers();
        $duble->removeMyProfilePhoto();

        $this->assertSame([
            ['api' => 'deleteWebhook', 'content' => [], 'post' => false],
            ['api' => 'getWebhookInfo', 'content' => [], 'post' => false],
            ['api' => 'getAvailableGifts', 'content' => [], 'post' => false],
            ['api' => 'getMyStarBalance', 'content' => [], 'post' => false],
            ['api' => 'getForumTopicIconStickers', 'content' => [], 'post' => false],
            ['api' => 'removeMyProfilePhoto', 'content' => [], 'post' => false],
        ], $duble->chamadas);
    }

    public function testSetWebhookSemCertificadoEnviaSomenteAUrl(): void
    {
        $duble = $this->duble();

        $duble->setWebhook('https://exemplo.com/webhook');

        $this->assertSame(
            [['api' => 'setWebhook', 'content' => ['url' => 'https://exemplo.com/webhook'], 'post' => true]],
            $duble->chamadas
        );
    }

    public function testSetWebhookComCertificadoAnexaCurlFile(): void
    {
        // new CURLFile() apenas guarda o caminho: nada é lido nem enviado aqui.
        $duble = $this->duble();

        $duble->setWebhook('https://exemplo.com/webhook', '/caminho/cert.pem');

        $chamada = $duble->chamadas[0];
        $this->assertSame('setWebhook', $chamada['api']);
        $this->assertTrue($chamada['post']);
        $this->assertSame('https://exemplo.com/webhook', $chamada['content']['url']);

        $certificado = $chamada['content']['certificate'] ?? null;
        $this->assertInstanceOf(\CURLFile::class, $certificado);
        $this->assertSame('/caminho/cert.pem', $certificado->getFilename());
    }

    public function testSetWebhookRepassaOpcoesAtuaisDaApi(): void
    {
        $duble = $this->duble();

        $duble->setWebhook(
            'https://exemplo.com/webhook',
            '',
            '203.0.113.10',
            25,
            ['message', 'callback_query'],
            true,
            'segredo-webhook'
        );

        $this->assertSame(
            [['api' => 'setWebhook', 'content' => [
                'url' => 'https://exemplo.com/webhook',
                'ip_address' => '203.0.113.10',
                'max_connections' => 25,
                'allowed_updates' => '["message","callback_query"]',
                'drop_pending_updates' => true,
                'secret_token' => 'segredo-webhook',
            ], 'post' => true]],
            $duble->chamadas
        );
    }

    public function testDeleteWebhookPodeDescartarUpdatesPendentes(): void
    {
        $duble = $this->duble();

        $duble->deleteWebhook(true);

        $this->assertSame(
            [['api' => 'deleteWebhook', 'content' => ['drop_pending_updates' => true], 'post' => true]],
            $duble->chamadas
        );
    }

    public function testGetFileEmbrulhaOFileId(): void
    {
        $duble = $this->duble();

        $duble->getFile('ABC-123');

        $this->assertSame(
            [['api' => 'getFile', 'content' => ['file_id' => 'ABC-123'], 'post' => true]],
            $duble->chamadas
        );
    }

    public function testTodoWrapperFinoDaClasseEstaNaListaEChamaSeuHomonimo(): void
    {
        // Descoberta pela própria classe (reflexão), não pelo texto do arquivo:
        // todo método público com a assinatura de wrapper fino — (array): array
        // — é CHAMADO aqui contra o dublê. Wrapper novo fora da constante,
        // wrapper removido ou wrapper que chame endpoint de outro nome reprova.
        $duble = $this->duble();
        $descobertos = [];

        foreach ((new ReflectionClass(Telegram::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $metodo) {
            if ($metodo->isStatic() || $metodo->getNumberOfParameters() !== 1) {
                continue;
            }

            $parametro = $metodo->getParameters()[0]->getType();
            $retorno = $metodo->getReturnType();
            if (!$parametro instanceof ReflectionNamedType || $parametro->getName() !== 'array') {
                continue;
            }
            if (!$retorno instanceof ReflectionNamedType || $retorno->getName() !== 'array') {
                continue;
            }

            $nome = $metodo->getName();
            $descobertos[] = $nome;

            // Invocado pela própria ReflectionMethod: o nome do wrapper vem da
            // varredura, então não há lista fechada para um match() explícito.
            $retorno = $metodo->invoke($duble, ['sonda' => $nome]);
            $this->assertSame(
                ['api' => $nome, 'content' => ['sonda' => $nome], 'post' => true],
                $duble->chamadas[count($descobertos) - 1],
                "wrapper {$nome} descoberto por reflexão"
            );
            // O wrapper devolve a resposta do endpoint sem tocá-la.
            $this->assertSame(['ok' => true, 'result' => $nome], $retorno, "retorno de {$nome}");
        }

        // Traits mudam a ordem física; o contrato exige os mesmos endpoints.
        $esperados = self::WRAPPERS_SIMPLES;
        sort($esperados);
        sort($descobertos);
        $this->assertSame($esperados, $descobertos);
    }

    public function testSetWebhookAceitaListaVaziaDeAllowedUpdates(): void
    {
        // [] é documentado pela Bot API e diferente de null (parâmetro omitido):
        // sem serializar a lista vazia o webhook voltaria a receber tudo.
        $duble = $this->duble();

        $duble->setWebhook('https://exemplo.com/webhook', '', null, null, []);

        $this->assertSame(
            [['api' => 'setWebhook', 'content' => [
                'url' => 'https://exemplo.com/webhook',
                'allowed_updates' => '[]',
            ], 'post' => true]],
            $duble->chamadas
        );
    }

    public function testSetWebhookRecusaAllowedUpdatesQueNaoPodeSerJson(): void
    {
        // O valor inválido em UTF-8 fazia json_encode() devolver false e o
        // método simplesmente omitia o filtro, reconfigurando o webhook para
        // receber todos os tipos de update.
        $duble = $this->duble();

        $resultado = $duble->setWebhook('https://exemplo.com/webhook', '', null, null, ["\xB1\x31"]);

        $this->assertSame([
            'ok' => false,
            'description' => 'Não foi possível serializar allowed_updates como JSON',
        ], $resultado);
        $this->assertSame([], $duble->chamadas);
    }

    public function testSetWebhookOmiteOsOpcionaisNaoInformados(): void
    {
        // Cada opcional só entra no corpo quando não é null: mandar
        // max_connections/drop_pending_updates com valor default alteraria a
        // configuração do webhook em produção sem ninguém ter pedido.
        $duble = $this->duble();

        $duble->setWebhook('https://exemplo.com/webhook', '', null, null, null, null, null);

        $this->assertSame(
            ['url' => 'https://exemplo.com/webhook'],
            $duble->chamadas[0]['content']
        );
    }

    public function testDeleteWebhookSemArgumentoNaoDescartaUpdatesPendentes(): void
    {
        // drop_pending_updates ausente é o padrão seguro: descartar updates
        // pendentes por engano perde mensagens já recebidas pelo Telegram.
        $duble = $this->duble();

        $duble->deleteWebhook();

        $this->assertSame(
            [['api' => 'deleteWebhook', 'content' => [], 'post' => false]],
            $duble->chamadas
        );
    }

    public function testDeleteWebhookComFalseEnviaOParametroExplicitamente(): void
    {
        // false é diferente de null aqui: o parâmetro vai explícito e a chamada
        // passa a ser POST.
        $duble = $this->duble();

        $duble->deleteWebhook(false);

        $this->assertSame(
            [['api' => 'deleteWebhook', 'content' => ['drop_pending_updates' => false], 'post' => true]],
            $duble->chamadas
        );
    }

    /**
     * O long polling é o ÚNICO caso em que o teto do cURL tem de ser maior que
     * REQUEST_TIMEOUT_SECONDS: o `timeout` do getUpdates pede ao próprio
     * Telegram que segure a conexão aberta por N segundos, e cortá-la nos 15 s
     * curtos mataria todo ciclo com N maior que isso. Toda outra chamada — que
     * em produção é a totalidade delas, porque o bot roda por webhook — fica no
     * teto curto.
     */
    public function testTimeoutDaRequisicaoSoCresceNoLongPollingDoGetUpdates(): void
    {
        // Reflexão porque o método é protected e o que se quer medir é a regra,
        // não um wrapper de teste: uma subclasse pública aqui trancaria a
        // assinatura do dublê, não a do transporte de produção.
        $regra = new ReflectionMethod(Telegram::class, 'timeoutDaRequisicaoEmSegundos');
        $telegram = new Telegram('123456:FAKE_TOKEN', false);

        // getUpdates: o teto é o que foi PEDIDO ao Telegram mais a margem de
        // handshake e transferência do lote.
        $this->assertSame(60, $regra->invoke($telegram, ['timeout' => 50, 'offset' => 7]));
        $this->assertSame(11, $regra->invoke($telegram, ['timeout' => '1']));

        // Todo o resto fica no teto curto — inclusive um `timeout` que não seja
        // um número positivo, que não é pedido de long polling nenhum.
        $this->assertSame(15, $regra->invoke($telegram, ['chat_id' => 1, 'text' => 'oi']));
        $this->assertSame(15, $regra->invoke($telegram, []));
        $this->assertSame(15, $regra->invoke($telegram, ['timeout' => 0]));
        $this->assertSame(15, $regra->invoke($telegram, ['timeout' => -30]));
        $this->assertSame(15, $regra->invoke($telegram, ['timeout' => 'longo']));
    }

    /**
     * As travas de TLS não são observáveis sem rede — o `file://` da suíte não
     * faz handshake nenhum — e a regra de semgrep só pega o literal `false`.
     * Apagar as duas linhas passava por tudo. Por estas conexões vai o
     * TOKEN_BOT.
     */
    public function testTransporteExigeCertificadoValidoEHostConferido(): void
    {
        $opcoes = (new TelegramOpcoesExpostas('000000:TEST_TOKEN', false))
            ->opcoesDe('https://api.telegram.org/bot000000:TEST_TOKEN/getMe', [], true);

        $this->assertTrue($opcoes[CURLOPT_SSL_VERIFYPEER] ?? null);
        // 2, e não true: o cURL trata 1 como "existe um nome no certificado"
        // sem compará-lo com o host pedido, que é o mesmo que não verificar.
        $this->assertSame(2, $opcoes[CURLOPT_SSL_VERIFYHOST] ?? null);
    }

    /**
     * O corpo só vai quando é POST. Endpoint GET da Bot API não aceita corpo, e
     * mandar um faria o cURL abrir um POST — o inverso do pedido.
     */
    public function testCorpoSoAcompanhaChamadaPost(): void
    {
        $duble = new TelegramOpcoesExpostas('000000:TEST_TOKEN', false);
        $url = 'https://api.telegram.org/bot000000:TEST_TOKEN/sendMessage';

        $comPost = $duble->opcoesDe($url, ['text' => 'oi'], true);
        $this->assertTrue($comPost[CURLOPT_POST] ?? null);
        $this->assertSame(['text' => 'oi'], $comPost[CURLOPT_POSTFIELDS] ?? null);

        $semPost = $duble->opcoesDe($url, ['text' => 'oi'], false);
        $this->assertArrayNotHasKey(CURLOPT_POST, $semPost);
        $this->assertArrayNotHasKey(CURLOPT_POSTFIELDS, $semPost);
        // A URL e as travas continuam valendo nos dois casos.
        $this->assertSame($url, $semPost[CURLOPT_URL] ?? null);
        $this->assertTrue($semPost[CURLOPT_SSL_VERIFYPEER] ?? null);
    }

    /**
     * Cada opção de proxy entra só depois de provado o tipo que o cURL espera —
     * e some quando o tipo não bate, em vez de virar 0 ou string vazia.
     *
     * @param array<string, mixed> $proxy
     * @param array<int, mixed> $esperado
     */
    #[DataProvider('proxiesProvider')]
    public function testProxyAplicaSomenteOQueTemOTipoCerto(array $proxy, array $esperado): void
    {
        $opcoes = (new TelegramOpcoesExpostas('000000:TEST_TOKEN', false, $proxy))
            ->opcoesDe('https://api.telegram.org/bot000000:TEST_TOKEN/getMe', [], true);

        $doProxy = array_intersect_key(
            $opcoes,
            array_flip([CURLOPT_PROXY, CURLOPT_PROXYPORT, CURLOPT_PROXYTYPE, CURLOPT_PROXYUSERPWD])
        );

        $this->assertSame($esperado, $doProxy);
    }

    /**
     * @return array<string, array{array<string, mixed>, array<int, mixed>}>
     */
    public static function proxiesProvider(): array
    {
        return [
            'sem proxy não aplica nada' => [[], []],
            'completo aplica os quatro' => [
                ['url' => '127.0.0.1', 'port' => 8080, 'type' => 0, 'auth' => 'u:p'],
                [
                    CURLOPT_PROXYTYPE => 0,
                    CURLOPT_PROXYUSERPWD => 'u:p',
                    CURLOPT_PROXY => '127.0.0.1',
                    CURLOPT_PROXYPORT => 8080,
                ],
            ],
            // Porta como texto numérico é aceita e convertida; texto não
            // numérico é descartado, porque CURLOPT_PROXYPORT com 0 mandaria a
            // chamada para a porta errada em silêncio.
            'porta numérica em texto é convertida' => [
                ['url' => '127.0.0.1', 'port' => '8080'],
                [CURLOPT_PROXY => '127.0.0.1', CURLOPT_PROXYPORT => 8080],
            ],
            'porta não numérica é descartada' => [
                ['url' => '127.0.0.1', 'port' => 'oitomil'],
                [CURLOPT_PROXY => '127.0.0.1'],
            ],
            'porta fora do domínio do cURL é descartada' => [
                ['url' => '127.0.0.1', 'port' => 65536],
                [CURLOPT_PROXY => '127.0.0.1'],
            ],
            'tipo não numérico é descartado' => [
                ['url' => '127.0.0.1', 'type' => 'socks'],
                [CURLOPT_PROXY => '127.0.0.1'],
            ],
            // Auth vazia não vira PROXYUSERPWD: mandar ':' faria o cURL tentar
            // autenticar com usuário e senha vazios em vez de não autenticar.
            'auth vazia não é aplicada' => [
                ['url' => '127.0.0.1', 'auth' => ''],
                [CURLOPT_PROXY => '127.0.0.1'],
            ],
            'auth não escalar é descartada' => [
                ['url' => '127.0.0.1', 'auth' => ['u', 'p']],
                [CURLOPT_PROXY => '127.0.0.1'],
            ],
            // As três conversões existem porque a configuração de proxy chega
            // de fora (env, arquivo de config) e vem com o tipo que o parser
            // deu, não com o que o cURL exige. Sem elas, o cURL recebe o valor
            // cru e recusa a opção em silêncio.
            'tipo numérico em texto é convertido para int' => [
                ['url' => '127.0.0.1', 'type' => '4'],
                [CURLOPT_PROXYTYPE => 4, CURLOPT_PROXY => '127.0.0.1'],
            ],
            'tipo numérico desconhecido é descartado' => [
                ['url' => '127.0.0.1', 'type' => 999],
                [CURLOPT_PROXY => '127.0.0.1'],
            ],
            'auth numérica é convertida para texto' => [
                ['url' => '127.0.0.1', 'auth' => 12345],
                [CURLOPT_PROXYUSERPWD => '12345', CURLOPT_PROXY => '127.0.0.1'],
            ],
            'url numérica é convertida para texto' => [
                ['url' => 127001],
                [CURLOPT_PROXY => '127001'],
            ],
        ];
    }
}

/**
 * Expõe opcoesDaRequisicao(), que é protected.
 *
 * A subclasse não sobrescreve nada: só devolve o que o transporte de produção
 * montaria. Sem isto, as opções de cURL da classe compartilhada não são
 * observáveis por teste nenhum — o `file://`, único protocolo que a suíte
 * alcança sem rede, ignora POST, proxy e handshake de TLS.
 */
final class TelegramOpcoesExpostas extends Telegram
{
    /**
     * @param non-empty-string $url
     * @param array<string, mixed> $content
     * @return array<int, mixed>
     */
    public function opcoesDe(string $url, array $content, bool $post): array
    {
        return $this->opcoesDaRequisicao($url, $content, $post);
    }
}
