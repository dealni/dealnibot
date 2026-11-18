<?php

declare(strict_types=1);

namespace Tests;

use bdTelegram;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use stdClass;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';
require_once __DIR__ . '/../../inclusos/bdTelegram.php';

/**
 * Testes do roteador.php (roteamento compartilhado entre bot.php e bot_web.php).
 *
 * O roteador é um SCRIPT incluído, não uma função. Aqui só são exercitados os
 * caminhos comprovadamente SEM efeito colateral:
 *   1. a guarda de contexto — lança LogicException antes de qualquer ação;
 *   2. chat privado — registra o contato no schema descartável e devolve
 *      uma orientação, sem acionar a IA;
 *   3. mensagem fora de grupo — é ignorada antes dos gatilhos
 *      (require devolve null).
 *
 * Os demais caminhos fazem `require` de comandos/*.php (efeitos reais:
 * OpenAI/Telegram) e por isso são INTESTÁVEIS neste formato — jamais monte um
 * update que caia neles (fotos ou captions/textos contendo "dealni", replies
 * ao bot, chats group/supergroup).
 *
 * Que os terminadores do roteador devolvem o controle ao chamador em vez de
 * encerrar o processo (o `exit` matava o laço de polling de bot.php) é
 * verificado em subprocesso por InfraRoteadorPollingTest.
 *
 * O contexto usa instâncias reais: Telegram com token fake e setData (só
 * memória, sem rede), bdTelegram sobre schema PostgreSQL descartável (padrão
 * do BdTelegramTest) e Redis sem connect() (nenhuma conexão é aberta).
 */
final class RoteadorTest extends TestCase
{
    /** @var string Identificador do schema PostgreSQL descartável deste teste. */
    private $tmp;

    protected function setUp(): void
    {
        // Identificador único: BancoTeste deriva dele um schema isolado por
        // teste, com o schema real.
        $tmp = tempnam(sys_get_temp_dir(), 'dealnibot_roteador_');
        $this->assertIsString($tmp, 'tempnam precisa conseguir criar o identificador do schema de teste.');
        $this->tmp = $tmp;

        $sql = file_get_contents(__DIR__ . '/../../database/schema.sql');
        $this->assertIsString($sql, 'database/schema.sql precisa existir e ser legível para semear o schema.');
        $pdo = \BancoTeste::conectar($this->tmp);
        $pdo->exec($sql);
        $pdo = null;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmp)) {
            @unlink($this->tmp);
        }

        // Os testes do sorteio de grupo semeiam o gerador global; sem esta
        // linha a semente fixa vazaria para os demais testes da suíte.
        mt_srand();
    }

    /**
     * Inclui o roteador com contexto VÁLIDO e o update informado.
     *
     * Devolve o valor do require (null quando o script executa `return;`,
     * 1 quando atravessa até o fim do arquivo) e as variáveis que o script
     * definiu no escopo — permitindo asserções exatas sobre o roteamento.
     *
     * @param array<string, mixed> $update
     * @return array{retorno: mixed, vars: array<string, mixed>}
     */
    private function incluirRoteador(array $update): array
    {
        // Token fake + log_errors=false: nenhuma chamada de rede é feita.
        $telegram = new Telegram('123456:FAKE_TOKEN', false);
        $telegram->setData($update);
        $bd = new bdTelegram(\BancoTeste::dsn($this->tmp));
        $redis = new Redis();

        return (static function () use (&$update, &$telegram, &$bd, &$redis): array {
            $retorno = require __DIR__ . '/../../roteador.php';
            return ['retorno' => $retorno, 'vars' => get_defined_vars()];
        })();
    }

    /**
     * Igual a incluirRoteador(), mas SEMEIA o gerador de números aleatórios na
     * última instrução antes do `require`.
     *
     * O ramo de grupo/supergrupo do roteador sorteia `rand(1, 10)` e só inclui
     * um comando quando sai 1. Sem controlar o sorteio, um teste desse ramo
     * incluiria comandos/*.php em ~10% das execuções — chamada real à OpenAI e
     * ao Telegram. Com a semente fixa o sorteio é determinístico, e quem chama
     * PRECISA provar antes (assertSorteioNuncaCaiNoComando) que a semente não
     * produz 1 — se produzir, o teste falha ANTES de incluir o roteador.
     *
     * @param array<string, mixed> $update
     * @return array{retorno: mixed, vars: array<string, mixed>}
     */
    private function incluirRoteadorComSorteio(array $update, int $semente, ?Redis $redis = null): array
    {
        $telegram = new Telegram('123456:FAKE_TOKEN', false);
        $telegram->setData($update);
        $bd = new bdTelegram(\BancoTeste::dsn($this->tmp));
        $redis ??= new Redis();

        mt_srand($semente);
        return (static function () use (&$update, &$semente, &$telegram, &$bd, &$redis): array {
            $retorno = require __DIR__ . '/../../roteador.php';
            return ['retorno' => $retorno, 'vars' => get_defined_vars()];
        })();
    }

    /**
     * Prova que a semente não faz `rand(1, 10)` devolver 1 nos primeiros
     * sorteios — margem para o caso de o roteador passar a consumir o gerador
     * antes do ramo de grupo. Só depois disso é seguro incluir o roteador.
     */
    private function assertSorteioNuncaCaiNoComando(int $semente): void
    {
        // Guarda 1: o roteador sorteia uma única vez (senão os 5 sorteios
        // conferidos abaixo poderiam não cobrir o que ele consome).
        $this->assertSame(1, $this->chamadasDeRandNoRoteador());

        // Guarda 2: nenhum dos primeiros sorteios dessa semente dá 1.
        mt_srand($semente);
        $sorteios = [];
        for ($i = 0; $i < 5; $i++) {
            $sorteios[] = rand(1, 10);
        }

        $this->assertNotContains(
            1,
            $sorteios,
            'A semente escolhida faria o roteador incluir comandos/*.php (rede real).'
        );
    }

    /**
     * Quantas vezes o roteador chama rand(), lido por tokens (a menção a rand()
     * no comentário do topo não conta).
     */
    private function chamadasDeRandNoRoteador(): int
    {
        $fonte = file_get_contents(__DIR__ . '/../../roteador.php');
        $this->assertIsString($fonte, 'roteador.php precisa ser legível.');

        $chamadas = 0;
        foreach (token_get_all($fonte) as $token) {
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'rand') {
                $chamadas++;
            }
        }

        return $chamadas;
    }

    /**
     * Conta as linhas da tabela usuarios do schema descartável.
     */
    private function contarUsuarios(): int
    {
        $pdo = \BancoTeste::conectar($this->tmp);
        $consulta = $pdo->query('SELECT COUNT(*) FROM usuarios');
        $this->assertNotFalse($consulta, 'A consulta de contagem de usuários precisa executar.');
        $total = $consulta->fetchColumn();

        return is_numeric($total) ? (int) $total : -1;
    }

    // ---------------------------------------------------------------------
    // Guarda de contexto
    // ---------------------------------------------------------------------

    public function testGuardaLancaExcecaoSemNenhumContexto(): void
    {
        // Nenhum $telegram/$bd/$redis no escopo: a guarda dispara ANTES de
        // qualquer efeito colateral.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Contexto inválido para o roteador.');

        (static function (): mixed {
            return require __DIR__ . '/../../roteador.php';
        })();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function contextoInvalidoProvider(): array
    {
        return [
            'telegram de tipo errado' => ['telegram'],
            'bd de tipo errado' => ['bd'],
            'redis de tipo errado' => ['redis'],
        ];
    }

    /**
     * Cada dependência com tipo errado, individualmente, dispara a guarda.
     */
    #[DataProvider('contextoInvalidoProvider')]
    public function testGuardaLancaExcecaoComDependenciaDeTipoErrado(string $invalida): void
    {
        $telegram = $invalida === 'telegram' ? new stdClass() : new Telegram('123456:FAKE_TOKEN', false);
        $bd = $invalida === 'bd' ? new stdClass() : new bdTelegram(\BancoTeste::dsn($this->tmp));
        $redis = $invalida === 'redis' ? new stdClass() : new Redis();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Contexto inválido para o roteador.');

        (static function () use (&$invalida, &$telegram, &$bd, &$redis): mixed {
            return require __DIR__ . '/../../roteador.php';
        })();
    }

    // ---------------------------------------------------------------------
    // Chat privado: cadastro persistente para avisos
    // ---------------------------------------------------------------------

    public function testChatPrivadoSemUserIdRetornaSemTocarOBanco(): void
    {
        $resultado = $this->incluirRoteador([
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                // Sem 'from': UserID() devolve null.
                'chat' => ['id' => 10, 'type' => 'private'],
                'text' => 'oi',
            ],
        ]);

        // `return;` dentro do script faz o require devolver null
        // (se atravessasse até o fim do arquivo, devolveria 1).
        $this->assertNull($resultado['retorno']);
        $this->assertSame('private', $resultado['vars']['chatType']);
        $this->assertNull($resultado['vars']['id_telegram']);
        $this->assertSame('oi', $resultado['vars']['texto_recebido']);
        // Sem UserID não há o que registrar: o privado só cadastra quem o Telegram
        // identifica. Nenhum comando é carregado em nenhum dos dois casos.
        $this->assertArrayNotHasKey('user', $resultado['vars']);
        $this->assertSame(0, $this->contarUsuarios());
    }

    public function testChatPrivadoRegistraContatoSemComandoDeConsentimento(): void
    {
        $resultado = $this->incluirRoteador([
            'update_id' => 3,
            'message' => [
                'message_id' => 3,
                'from' => ['id' => 4242],
                'chat' => ['id' => 4242, 'type' => 'private'],
                'text' => '/start',
            ],
        ]);

        // A IA não é acionada no privado; o cadastro é feito antes do retorno.
        $this->assertNull($resultado['retorno']);
        $this->assertSame('private', $resultado['vars']['chatType']);
        // Uma conversa privada registra o contato.
        $this->assertSame(1, $this->contarUsuarios());
    }

    public function testComandosAntigosMantemOCadastroSemDuplicarContato(): void
    {
        foreach (['/start', '/stop', '/remover_dados', '/excluir_conta', '/aceitar_avisos'] as $indice => $comando) {
            $this->incluirRoteador([
                'update_id' => 30 + $indice,
                'message' => [
                    'message_id' => 30 + $indice,
                    'from' => ['id' => 4242],
                    'chat' => ['id' => 4242, 'type' => 'private'],
                    'text' => $comando,
                ],
            ]);
            $this->assertSame(1, $this->contarUsuarios());
        }

        $bd = new bdTelegram(\BancoTeste::dsn($this->tmp));
        $usuario = $bd->getUsuario(4242);
        $this->assertIsArray($usuario);
        $this->assertSame(4242, $usuario['id_telegram']);
    }

    public function testCallbackEmChatPrivadoSemUserIdUsaChatDoCallbackERetorna(): void
    {
        $resultado = $this->incluirRoteador([
            'update_id' => 2,
            'callback_query' => [
                'id' => '99',
                // Sem 'from': UserID() devolve null.
                'data' => 'acao_qualquer',
                'message' => [
                    'message_id' => 2,
                    'chat' => ['id' => 11, 'type' => 'private'],
                ],
            ],
        ]);

        $this->assertNull($resultado['retorno']);
        // O chat precisa ser extraído de callback_query.message.chat.
        $this->assertSame('private', $resultado['vars']['chatType']);
        $this->assertSame(['id' => 11, 'type' => 'private'], $resultado['vars']['chatRecebido']);
        $this->assertSame(Telegram::CALLBACK_QUERY, $resultado['vars']['tipo_de_update']);
        // Em callback_query, Text() devolve o campo data.
        $this->assertSame('acao_qualquer', $resultado['vars']['texto_recebido']);
        $this->assertNull($resultado['vars']['id_telegram']);
        // Sem UserID, nada a registrar — ver o teste da mensagem privada acima.
        $this->assertArrayNotHasKey('user', $resultado['vars']);
        $this->assertSame(0, $this->contarUsuarios());
    }

    // ---------------------------------------------------------------------
    // Fluxos que atravessam o arquivo inteiro sem ação (sem exit/require)
    // ---------------------------------------------------------------------

    public function testMensagemDeCanalEhIgnoradaAntesDosGatilhos(): void
    {
        $resultado = $this->incluirRoteador([
            'update_id' => 3,
            'message' => [
                'message_id' => 3,
                'from' => ['id' => 111, 'is_bot' => false, 'first_name' => 'Fulano'],
                'chat' => ['id' => -50, 'type' => 'channel'],
                // ATENÇÃO: o texto NÃO pode conter "dealni" (dispararia chatgpt.php).
                'text' => 'Bom dia, pessoal!',
            ],
        ]);

        // Canal não é um grupo autorizado: o require devolve null.
        $this->assertNull($resultado['retorno']);
        $this->assertSame('channel', $resultado['vars']['chatType']);
        $this->assertSame(111, $resultado['vars']['id_telegram']);
        $this->assertSame('message', $resultado['vars']['tipo_de_update']);
        $this->assertArrayNotHasKey('texto_para_verificar', $resultado['vars']);
        $this->assertArrayNotHasKey('reply_to_message', $resultado['vars']);
        $this->assertArrayNotHasKey('user', $resultado['vars']);
        $this->assertSame(0, $this->contarUsuarios());
    }

    public function testMensagemSemChatEhIgnoradaAntesDosGatilhos(): void
    {
        $resultado = $this->incluirRoteador([
            'update_id' => 4,
            'message' => [
                'message_id' => 4,
                'from' => ['id' => 222],
                // ATENÇÃO: o texto NÃO pode conter "dealni" (dispararia chatgpt.php).
                'text' => 'tudo certo',
            ],
        ]);

        $this->assertNull($resultado['retorno']);
        // Sem chat no update: chatRecebido vazio e chatType null.
        $this->assertSame([], $resultado['vars']['chatRecebido']);
        $this->assertNull($resultado['vars']['chatType']);
        $this->assertArrayNotHasKey('texto_para_verificar', $resultado['vars']);
        $this->assertArrayNotHasKey('user', $resultado['vars']);
        $this->assertSame(0, $this->contarUsuarios());
    }

    // ---------------------------------------------------------------------
    // Update que não é 'message': `return;` antes dos gatilhos de grupo
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function updatesQueNaoSaoMensagemProvider(): array
    {
        return [
            // ATENÇÃO: em todos, o texto NÃO pode conter "dealni" e o chat não
            // pode ser 'private' — cairia num require de comandos/*.php.
            'mensagem editada em grupo' => [
                [
                    'update_id' => 5,
                    'edited_message' => [
                        'message_id' => 5,
                        'from' => ['id' => 111, 'is_bot' => false],
                        'chat' => ['id' => -50, 'type' => 'group'],
                        'text' => 'corrigindo o que escrevi',
                    ],
                ],
                'edited_message',
            ],
            'callback_query em grupo' => [
                [
                    'update_id' => 6,
                    'callback_query' => [
                        'id' => '99',
                        'from' => ['id' => 111, 'is_bot' => false],
                        'data' => 'acao_qualquer',
                        'message' => [
                            'message_id' => 6,
                            'chat' => ['id' => -50, 'type' => 'group'],
                        ],
                    ],
                ],
                Telegram::CALLBACK_QUERY,
            ],
            'post de canal' => [
                [
                    'update_id' => 7,
                    'channel_post' => [
                        'message_id' => 7,
                        'chat' => ['id' => -60, 'type' => 'channel'],
                        'text' => 'aviso do canal',
                    ],
                ],
                Telegram::CHANNEL_POST,
            ],
        ];
    }

    /**
     * Fora de chat privado, qualquer update que não seja 'message' encerra o
     * roteamento ANTES dos gatilhos (reply ao bot, menção pelo nome e sorteio
     * dos grupos). Nenhum comando é incluído e o controle volta ao laço de
     * bot.php — o resto do lote ainda precisa ser tratado.
     *
     * @param array<string, mixed> $update
     */
    #[DataProvider('updatesQueNaoSaoMensagemProvider')]
    public function testUpdateQueNaoEhMensagemEncerraORoteamentoSemComando(
        array $update,
        string $tipoEsperado
    ): void {
        $resultado = $this->incluirRoteador($update);

        // `return;` no ramo do tipo de update: o require devolve null.
        $this->assertNull($resultado['retorno']);
        $this->assertSame($tipoEsperado, $resultado['vars']['tipo_de_update']);
        $this->assertNotSame('private', $resultado['vars']['chatType']);
        // O ramo do reply nunca chegou a ser avaliado.
        $this->assertArrayNotHasKey('reply_to_message', $resultado['vars']);
        $this->assertArrayNotHasKey('rota', $resultado['vars']);
        $this->assertSame(0, $this->contarUsuarios());
    }

    // ---------------------------------------------------------------------
    // Reply em grupo: só resposta a mensagem do PRÓPRIO bot é atendida
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function repliesQueNaoSaoDoBotProvider(): array
    {
        return [
            'resposta a outro membro' => [
                ['message_id' => 8, 'from' => ['id' => 999, 'username' => 'Fulano']],
            ],
            'resposta a outro bot' => [
                ['message_id' => 8, 'from' => ['id' => 999, 'username' => 'OutroBot', 'is_bot' => true]],
            ],
            'remetente sem username' => [
                ['message_id' => 8, 'from' => ['id' => 999]],
            ],
            'reply sem remetente' => [
                ['message_id' => 8],
            ],
        ];
    }

    /**
     * Responder em grupo a alguém que não é o bot NÃO pode disparar comando
     * nenhum: é conversa entre os membros do grupo e cada disparo indevido é
     * uma chamada paga à OpenAI.
     *
     * @param array<array-key, mixed> $reply
     */
    #[DataProvider('repliesQueNaoSaoDoBotProvider')]
    public function testRespostaQueNaoEhAoBotNaoDisparaComandoEmGrupo(array $reply): void
    {
        $resultado = $this->incluirRoteador([
            'update_id' => 8,
            'message' => [
                'message_id' => 80,
                'from' => ['id' => 111, 'is_bot' => false],
                'chat' => ['id' => -50, 'type' => 'group'],
                // ATENÇÃO: sem "dealni" no texto — a guarda do reply precisa
                // ser a única razão do encerramento.
                'text' => 'concordo com você',
                'reply_to_message' => $reply,
            ],
        ]);

        $this->assertNull($resultado['retorno']);
        $this->assertSame($reply, $resultado['vars']['reply_to_message']);
        // Encerrou no ramo do reply: nem a menção pelo nome nem o sorteio de
        // grupo chegaram a ser avaliados.
        $this->assertArrayNotHasKey('texto_para_verificar', $resultado['vars']);
        $this->assertArrayNotHasKey('rota', $resultado['vars']);
        $this->assertSame(0, $this->contarUsuarios());
    }

    // ---------------------------------------------------------------------
    // Grupo sem gatilho: sorteio de 1 em 10
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function tiposDeGrupoProvider(): array
    {
        return [
            'group' => ['group'],
            'supergroup' => ['supergroup'],
        ];
    }

    /**
     * Conversa em grupo que não menciona o bot nem responde a ele só vira
     * comando no sorteio de 1 em 10; nas outras 9 vezes o roteador devolve o
     * controle sem incluir nada (nenhuma chamada paga à OpenAI).
     */
    #[DataProvider('tiposDeGrupoProvider')]
    public function testGrupoSemGatilhoNaoDisparaComandoQuandoOSorteioNaoDaUm(string $tipoDeChat): void
    {
        // Semente cujos primeiros sorteios de rand(1, 10) não dão 1 — provado
        // abaixo ANTES de o roteador ser incluído.
        $semente = 7;
        $this->assertSorteioNuncaCaiNoComando($semente);

        $resultado = $this->incluirRoteadorComSorteio([
            'update_id' => 9,
            'message' => [
                'message_id' => 90,
                'from' => ['id' => 111, 'is_bot' => false],
                'chat' => ['id' => -50, 'type' => $tipoDeChat],
                // ATENÇÃO: sem "dealni" no texto e sem reply/foto.
                'text' => 'bom dia, pessoal',
            ],
        ], $semente);

        // `return;` no fim do ramo de grupo, com o sorteio perdido.
        $this->assertNull($resultado['retorno']);
        $this->assertSame($tipoDeChat, $resultado['vars']['chatType']);
        $this->assertSame('bom dia, pessoal', $resultado['vars']['texto_para_verificar']);
        // Nenhuma rota resolvida: o comando não chegou a ser escolhido.
        $this->assertArrayNotHasKey('rota', $resultado['vars']);
        $this->assertSame(0, $this->contarUsuarios());
    }

    /**
     * Chat em que o bot perdeu o direito de escrever fica lembrado no Redis:
     * o roteador encerra antes de qualquer gatilho, sem chamar a Bot API.
     */
    public function testChatSemPermissaoDeEscritaEncerraAntesDosGatilhos(): void
    {
        $semente = 7;
        $this->assertSorteioNuncaCaiNoComando($semente);
        $redis = new \Tests\Support\RedisFalsoComLua();
        $redis->semear(chaveRecusaDeEscrita(-50), '1');

        $resultado = $this->incluirRoteadorComSorteio([
            'update_id' => 9,
            'message' => [
                'message_id' => 90,
                'from' => ['id' => 111, 'is_bot' => false],
                'chat' => ['id' => -50, 'type' => 'supergroup'],
                'text' => 'bom dia, pessoal',
            ],
        ], $semente, $redis);

        $this->assertNull($resultado['retorno']);
        $this->assertSame('supergroup', $resultado['vars']['chatType']);
        $this->assertArrayNotHasKey('texto_para_verificar', $resultado['vars']);
        $this->assertArrayNotHasKey('reply_to_message', $resultado['vars']);
        $this->assertSame(['EXISTS telegram:sem_escrita:-50'], $redis->operacoes());
    }

    public function testChanceDeRespostaEspontaneaEmGrupoContinuaSendoUmEmDez(): void
    {
        // A garantia acima só é segura enquanto o sorteio for `rand(1, 10) === 1`:
        // se a faixa mudar (por exemplo para rand(1, 2)), a semente fixa poderia
        // passar a cair no require de comandos/*.php.
        $fonte = file_get_contents(__DIR__ . '/../../roteador.php');
        $this->assertIsString($fonte, 'roteador.php precisa ser legível.');

        // Contagem por tokens: a menção a rand() no comentário do topo não vale.
        $this->assertSame(1, $this->chamadasDeRandNoRoteador(), 'O roteador só pode sortear uma vez.');
        $this->assertStringContainsString('if (rand(1, 10) === 1) {', $fonte);
    }

    // ---------------------------------------------------------------------
    // Mídia em grupo: atravessa o portão de tipo do update
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function midiasEmGrupoProvider(): array
    {
        return [
            'foto' => [['photo' => [['file_id' => 'AgAC', 'file_unique_id' => 'u1']]], Telegram::PHOTO],
            'voz' => [['voice' => ['file_id' => 'AwAC', 'file_unique_id' => 'u2', 'duration' => 3]], Telegram::VOICE],
            'audio' => [['audio' => ['file_id' => 'CQAC', 'file_unique_id' => 'u3', 'duration' => 9]], Telegram::AUDIO],
        ];
    }

    /**
     * O portão de tipo do roteador era `$tipo != 'message'`, e
     * Telegram::getUpdateType() só devolve 'message' quando há `message.text`:
     * mensagem com foto é 'photo', com voz é 'voice', com áudio é 'audio'. Em
     * grupo o bot então só reagia a TEXTO — responder a ele com foto, voz ou
     * áudio, ou mandar foto com "dealni" na legenda, não disparava nada, e as
     * chamadas a roteamento_rotaPorMidia() dos ramos de reply e do sorteio eram
     * ramos mortos. Hoje esses três tipos passam.
     *
     * O reply é a QUEM NÃO É o bot de propósito: agora que a mídia atravessa o
     * portão, quem encerra é a guarda do reply, e o teste consegue observar isso
     * sem efeito nenhum. Com um reply ao próprio bot o roteador incluiria
     * comandos/ver_imagem.php — chamada real, proibida neste arquivo. A rota da
     * mídia em si é verificada em RoteadorRotasTest, sobre as funções puras.
     *
     * @param array<string, mixed> $midia
     */
    #[DataProvider('midiasEmGrupoProvider')]
    public function testMidiaEmGrupoAtravessaOPortaoDeTipoEChegaAoRamoDoReply(
        array $midia,
        string $tipoEsperado
    ): void {
        $mensagem = [
            'message_id' => 100,
            'from' => ['id' => 111, 'is_bot' => false],
            'chat' => ['id' => -50, 'type' => 'group'],
            'reply_to_message' => ['message_id' => 99, 'from' => ['username' => 'Fulano']],
            // ATENÇÃO: sem "dealni" na legenda — se a guarda do reply falhasse,
            // a menção pelo nome cairia num require de comandos/*.php.
            'caption' => 'o que é isso?',
        ];

        $resultado = $this->incluirRoteador([
            'update_id' => 10,
            'message' => array_merge($mensagem, $midia),
        ]);

        $this->assertNull($resultado['retorno']);
        $this->assertSame($tipoEsperado, $resultado['vars']['tipo_de_update']);
        // A mídia foi detectada corretamente: sobe a flag DO TIPO em jogo e
        // as outras duas continuam falsas. O `||` que estava aqui aceitava um
        // roteador que marcasse as três de uma vez.
        $flagEsperada = match ($tipoEsperado) {
            Telegram::PHOTO => 'temFoto',
            Telegram::VOICE => 'temVoz',
            Telegram::AUDIO => 'temAudio',
            default => self::fail("Tipo de mídia não previsto no teste: {$tipoEsperado}"),
        };
        foreach (['temFoto', 'temVoz', 'temAudio'] as $flag) {
            $this->assertSame($flag === $flagEsperada, $resultado['vars'][$flag], "flag {$flag}");
        }
        // ...e o roteamento seguiu até a guarda do reply, que antes era
        // inalcançável para mídia.
        $this->assertSame(
            ['message_id' => 99, 'from' => ['username' => 'Fulano']],
            $resultado['vars']['reply_to_message']
        );
        // Encerrou ali: sem rota, sem comando, sem usuário registrado.
        $this->assertArrayNotHasKey('rota', $resultado['vars']);
        $this->assertSame(0, $this->contarUsuarios());
    }

    /**
     * Contrapartida do teste acima: os tipos que os comandos NÃO sabem atender
     * continuam parando no portão. Vídeo, documento e sticker cairiam no
     * chatgpt com texto vazio — chamada paga disparada por um sticker.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function midiasQueNaoSaoAtendidasProvider(): array
    {
        return [
            'video' => [['video' => ['file_id' => 'BAAC', 'file_unique_id' => 'u4', 'duration' => 5,
                'width' => 10, 'height' => 10]]],
            'documento' => [['document' => ['file_id' => 'BQAC', 'file_unique_id' => 'u5']]],
            'sticker' => [['sticker' => ['file_id' => 'CAAC', 'file_unique_id' => 'u6',
                'type' => 'regular', 'width' => 512, 'height' => 512, 'is_animated' => false,
                'is_video' => false]]],
        ];
    }

    /**
     * @param array<string, mixed> $midia
     */
    #[DataProvider('midiasQueNaoSaoAtendidasProvider')]
    public function testMidiaSemComandoParaAtenderContinuaParandoNoPortao(array $midia): void
    {
        $mensagem = [
            'message_id' => 101,
            'from' => ['id' => 111, 'is_bot' => false],
            'chat' => ['id' => -50, 'type' => 'group'],
            'reply_to_message' => ['message_id' => 99, 'from' => ['username' => 'Fulano']],
        ];

        $resultado = $this->incluirRoteador([
            'update_id' => 11,
            'message' => array_merge($mensagem, $midia),
        ]);

        $this->assertNull($resultado['retorno']);
        $this->assertNotSame('message', $resultado['vars']['tipo_de_update']);
        // Parou ANTES do reply: o portão de tipo é o que encerra.
        $this->assertArrayNotHasKey('reply_to_message', $resultado['vars']);
        $this->assertArrayNotHasKey('rota', $resultado['vars']);
    }
}
