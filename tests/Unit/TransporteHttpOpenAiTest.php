<?php

declare(strict_types=1);

namespace Tests;

use App\Support\LoggerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppLogReader;

require_once __DIR__ . '/../../comandos/funcoes_http_openai.php';
require_once __DIR__ . '/../../comandos/funcoes_chatgpt.php';
require_once __DIR__ . '/../../comandos/funcoes_ver_audio.php';
require_once __DIR__ . '/../../comandos/funcoes_ver_imagem.php';

/**
 * Transporte compartilhado das três modalidades de IA.
 *
 * A falha registra uma linha JSON no canal `erros_openai` do app.log. A suíte
 * aponta DEALNI_LOGS_DIR para um diretório temporário (tests/bootstrap.php),
 * então o log de produção nunca é tocado: as asserções leem apenas o TRECHO
 * acrescentado pela chamada sob teste.
 */
final class TransporteHttpOpenAiTest extends TestCase
{
    private int $tamanhoLogAntes = 0;

    public static function setUpBeforeClass(): void
    {
        // escreverLog() respeita LOGS_ATIVO; em produção dados_bot.php a define
        // como true, e sem ela o registro da falha não aconteceria.
        if (!defined('LOGS_ATIVO')) {
            define('LOGS_ATIVO', true);
        }
    }

    protected function setUp(): void
    {
        LoggerFactory::reset();
        $this->tamanhoLogAntes = AppLogReader::tamanhoAtual();
        putenv('DEALNI_ALERTA_CHAT_ID');
        @unlink(LoggerFactory::diretorioLogs() . '/aviso-openrouter.falhou');
        @unlink(LoggerFactory::diretorioLogs() . '/aviso-openrouter.falhou.lock');
    }

    protected function tearDown(): void
    {
        putenv('DEALNI_ALERTA_CHAT_ID');
        @unlink(LoggerFactory::diretorioLogs() . '/aviso-openrouter.falhou');
        @unlink(LoggerFactory::diretorioLogs() . '/aviso-openrouter.falhou.lock');
    }

    /** @return array<string, array{int, string}> */
    public static function niveisPorStatusProvider(): array
    {
        return [
            '401 chave recusada' => [401, 'error'],
            '402 sem crédito' => [402, 'error'],
            '403 permissão ou moderação' => [403, 'warning'],
            '429 limite' => [429, 'warning'],
            '500 provedor' => [500, 'warning'],
            '503 provedor' => [503, 'warning'],
            '599 teto dos 5xx' => [599, 'warning'],
            '0 transporte' => [0, 'error'],
            '200 erro no corpo' => [200, 'error'],
            '400 pedido' => [400, 'warning'],
            '404 modelo' => [404, 'warning'],
            '499 abaixo dos 5xx' => [499, 'warning'],
            '600 fora da faixa' => [600, 'error'],
        ];
    }

    #[DataProvider('niveisPorStatusProvider')]
    public function testNivelDoLogSegueOStatusDoProvedor(int $http, string $nivel): void
    {
        $this->assertSame($nivel, nivelDaFalhaHttpOpenAi($http));
    }

    public function testContaRecusadaViraErroEInstabilidadeViraAviso(): void
    {
        registrarFalhaHttpOpenAi('chat.completions.texto', 401, 0, 'resposta HTTP fora da faixa 2xx');
        registrarFalhaHttpOpenAi('chat.completions.texto', 503, 0, 'resposta HTTP fora da faixa 2xx');
        registrarFalhaHttpOpenAi('chat.completions.texto', 0, 7, 'Failed to connect');

        $entradas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($this->tamanhoLogAntes));
        $this->assertSame(['error', 'warn', 'error'], array_column($entradas, 'level'));
        $this->assertSame(['erros_openai', 'erros_openai', 'erros_openai'], array_column($entradas, 'channel'));
        // Sem DEALNI_ALERTA_CHAT_ID, o 401 não deixa marca de aviso.
        $this->assertFileDoesNotExist(LoggerFactory::diretorioLogs() . '/aviso-openrouter.falhou');
    }

    public function testProibidoPelaModeracaoViraAvisoSemChamarODono(): void
    {
        // 403 do OpenRouter é também o conteúdo de quem escreveu barrado pela
        // moderação: com o aviso ligado, fica só o `warn`, sem marca de aviso.
        putenv('DEALNI_ALERTA_CHAT_ID=42');
        registrarFalhaHttpOpenAi('chat.completions.texto', 403, 0, 'resposta HTTP fora da faixa 2xx');

        $entradas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($this->tamanhoLogAntes));
        $this->assertSame(['warn'], array_column($entradas, 'level'));
        $this->assertFileDoesNotExist(LoggerFactory::diretorioLogs() . '/aviso-openrouter.falhou');
    }

    public function testBloqueioPersistenteEmHttp200AcionaAvisoSemConfundirModeracao(): void
    {
        putenv('DEALNI_ALERTA_CHAT_ID=42');
        registrarErroDaApiOpenAi('chat.completions.texto', 'content policy violation');
        $marca = LoggerFactory::diretorioLogs() . '/aviso-openrouter.falhou';
        $this->assertFileDoesNotExist($marca);

        // O transporte real está isolado pela sandbox: marca zero comprova a
        // tentativa fracassada e permite novo aviso, sem enviar para o Telegram.
        registrarErroDaApiOpenAi('chat.completions.texto', 'Policy Violation: this user has been blocked for a previous policy violation.');
        $this->assertSame('0', file_get_contents($marca));
        $entradas = AppLogReader::conteudoDesde($this->tamanhoLogAntes);
        $this->assertStringContainsString('"account_blocked":false', $entradas);
        $this->assertStringContainsString('"account_blocked":true', $entradas);
        $this->assertStringContainsString('bloqueio persistente', montarMensagemDeFalhaDoProvedor('bot', 'host', 'chat', 200));
    }

    public function testErroNaPrimeiraChoiceNaoAceitaConteudoComoSucesso(): void
    {
        $corpo = json_encode(['choices' => [['error' => ['message' => 'provider unavailable'], 'message' => ['content' => 'Não entregar']]]], JSON_THROW_ON_ERROR);
        $resultado = interpretarRespostaChatgpt($corpo);
        $this->assertSame('erro_api', $resultado['tipo']);
        $this->assertStringNotContainsString('Não entregar', $resultado['texto']);
        $this->assertCount(1, AppLogReader::linhasJson(AppLogReader::conteudoDesde($this->tamanhoLogAntes)));
    }

    public function testAvisosConcorrentesNaoDuplicamEnvioAntesDeGravarAJanela(): void
    {
        putenv('DEALNI_ALERTA_CHAT_ID=42');
        $enviar = function (string $texto): bool {
            $this->assertNotSame('', $texto);
            $this->assertFalse(avisarDonoFalhaDeContaOpenAi('chat.completions.imagem', 200, static function (string $duplicado): bool {
                self::fail('O envio concorrente não deve ocorrer: ' . $duplicado);
            }, 1_000_000));
            return true;
        };
        $this->assertTrue(avisarDonoFalhaDeContaOpenAi('chat.completions.texto', 200, $enviar, 1_000_000));
        $this->assertSame('1000000', file_get_contents(LoggerFactory::diretorioLogs() . '/aviso-openrouter.falhou'));
    }

    public function testFalhaDeContaAvisaODonoUmaVezPorHora(): void
    {
        putenv('DEALNI_ALERTA_CHAT_ID=42');
        /** @var \ArrayObject<int, string> $mensagens */
        $mensagens = new \ArrayObject();
        $enviar = static function (string $texto) use ($mensagens): bool {
            $mensagens[] = $texto;

            return true;
        };

        $this->assertTrue(avisarDonoFalhaDeContaOpenAi('chat.completions.texto', 401, $enviar, 1_000_000));
        $this->assertFalse(avisarDonoFalhaDeContaOpenAi('chat.completions.imagem', 402, $enviar, 1_000_000 + 3599));
        $this->assertCount(1, $mensagens, 'A mesma conta recusada não volta ao chat dentro da hora.');
        foreach (['[FALHA] OpenRouter', 'Chamada: chat.completions.texto', 'HTTP: 401 (chave inválida ou revogada)'] as $trecho) {
            $this->assertStringContainsString($trecho, $mensagens->getArrayCopy()[0]);
        }
        $this->assertStringNotContainsString('42', $mensagens->getArrayCopy()[0]);

        $this->assertTrue(avisarDonoFalhaDeContaOpenAi('audio.transcriptions', 402, $enviar, 1_000_000 + 3600));
        $this->assertStringContainsString('HTTP: 402 (conta sem crédito)', $mensagens->getArrayCopy()[1]);

        // Transporte padrão sob a rede desligada da suíte: não entrega, e a
        // marca `0` deixa a próxima falha tentar de novo.
        $this->assertFalse(avisarDonoFalhaDeContaOpenAi('chat.completions.texto', 401, null, 1_000_000 + 7200));
        $this->assertSame('0', file_get_contents(LoggerFactory::diretorioLogs() . '/aviso-openrouter.falhou'));

        putenv('DEALNI_ALERTA_CHAT_ID= ');
        $this->assertFalse(avisarDonoFalhaDeContaOpenAi('chat.completions.texto', 402, $enviar, 2_000_000));
        $this->assertCount(2, $mensagens, 'Sem chat configurado o aviso fica desligado.');
    }

    public function testFalhaDoCurlRetornaErroEstruturadoSemExporOCorpo(): void
    {
        // Porta discard: sob a sandbox de rede a conexão falha de imediato, e o
        // caminho exercitado é o de transporte real (errno != 0), não o de URL
        // malformada.
        $resultado = executarRequisicaoOpenAi(
            'http://127.0.0.1:9/v1/chat/completions',
            '{"segredo":"nao-deve-aparecer-no-log"}',
            ['Authorization: Bearer sk-segredo'],
            "chat\ncontexto",
            1
        );

        $this->assertSame(['corpo' => false, 'http' => 0], $resultado);

        $entradas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($this->tamanhoLogAntes));
        $this->assertCount(1, $entradas);
        $entrada = $entradas[0];
        $this->assertSame('erros_openai', $entrada['channel']);
        $mensagem = $entrada['msg'];
        $this->assertIsString($mensagem);

        // O contexto é sanitizado (a quebra de linha vira '_') para que uma
        // única falha não possa forjar várias linhas no log — regra que
        // continua valendo, mesmo com o app.log em JSON.
        $this->assertStringContainsString('OpenAI chat_contexto falhou', $mensagem);
        $this->assertStringNotContainsString("chat\ncontexto", $mensagem);

        // Nem a chave da API nem o corpo enviado podem chegar ao disco.
        $trecho = AppLogReader::conteudoDesde($this->tamanhoLogAntes);
        $this->assertStringNotContainsString('sk-segredo', $trecho);
        $this->assertStringNotContainsString('nao-deve-aparecer-no-log', $trecho);
    }

    /**
     * Resposta que CHEGOU mas está fora da faixa 2xx: o corpo é devolvido a
     * quem chamou (o leitor da resposta é que decide o que fazer com ele) e a
     * falha fica registrada assim mesmo — um 429 da OpenAI que passasse
     * silenciosamente viraria "o bot parou de responder" sem nenhuma pista.
     *
     * O transporte é exercitado com o cURL REAL sobre um `file://` local: não
     * há rede envolvida (a suíte roda sob sandbox), o errno é 0 e o status HTTP
     * é 0 — exatamente o formato "respondeu, mas não é 2xx". A explicação
     * completa da técnica do `file://` como servidor falso está em
     * tests/Unit/TelegramRespostaApiTest.php, sobre
     * testChatIdEntraNaQueryStringSemAlterarOCaminhoDaUrl.
     */
    public function testRespostaForaDaFaixa2xxDevolveOCorpoERegistraAFalha(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'dealnibot_openai_corpo_');
        $this->assertIsString($arquivo);
        file_put_contents($arquivo, '{"choices":[{"message":{"content":"oi"}}]}');

        try {
            $resultado = executarRequisicaoOpenAi(
                'file://' . $arquivo,
                '{"model":"openai/gpt-6-luna"}',
                ['Authorization: Bearer sk-segredo'],
                'chat.completions.texto',
                1
            );

            $this->assertSame('{"choices":[{"message":{"content":"oi"}}]}', $resultado['corpo']);
            $this->assertSame(0, $resultado['http']);

            $entradas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($this->tamanhoLogAntes));
            $this->assertCount(1, $entradas);
            $mensagem = $entradas[0]['msg'];
            $this->assertIsString($mensagem);
            $this->assertStringContainsString('OpenAI chat.completions.texto falhou', $mensagem);
            $this->assertStringContainsString('resposta HTTP fora da faixa 2xx', $mensagem);
            $this->assertStringNotContainsString('sk-segredo', $mensagem);
        } finally {
            @unlink($arquivo);
        }
    }

    /**
     * Os fluxos normalmente injetam um transporte nos testes. Este contrato
     * exercita deliberadamente o caminho de produção dos três wrappers de
     * chat: sem dublê, todos precisam delegar ao transporte compartilhado e
     * falhar fechado quando a rede está bloqueada pela sandbox da suíte.
     */
    /**
     * @return array<string, array{callable(string): array{tipo: string, texto: string}, string, string}>
     */
    public static function leitoresDeErroDaApiProvider(): array
    {
        return [
            'texto' => [
                interpretarRespostaChatgpt(...),
                'chat.completions',
                'Erro ao gerar resposta. Tente novamente! ❌',
            ],
            'audio' => [
                interpretarRespostaAudio(...),
                'chat.completions.audio',
                'Erro ao gerar resposta. Tente novamente! ❌',
            ],
            'imagem' => [
                interpretarRespostaImagem(...),
                'chat.completions.imagem',
                'Erro ao analisar a imagem. Tente novamente! ❌',
            ],
        ];
    }

    /** @param callable(string): array{tipo: string, texto: string} $leitor */
    #[DataProvider('leitoresDeErroDaApiProvider')]
    public function testErroDaApiViraAvisoGenericoESoCategoriaFicaNoLog(
        callable $leitor,
        string $contextoEsperado,
        string $avisoEsperado
    ): void {
        $mensagemDoProvedor = 'You exceeded your current quota, please check your plan and billing details '
            . 'for org-Minha-Empresa-Ltda token=segredo prompt=conteudo_privado';
        $corpo = json_encode(['error' => ['message' => $mensagemDoProvedor, 'type' => 'insufficient_quota']]);
        $this->assertIsString($corpo);

        $resultado = $leitor($corpo);

        // 1. O que o usuário vê: o aviso fixo do comando, e nada do provedor.
        $this->assertSame('erro_api', $resultado['tipo']);
        $this->assertSame($avisoEsperado, $resultado['texto']);
        $this->assertStringNotContainsString('quota', $resultado['texto']);
        $this->assertStringNotContainsString('billing', $resultado['texto']);
        $this->assertStringNotContainsString('org-Minha-Empresa-Ltda', $resultado['texto']);

        // 2. O operador recebe somente categoria fixa e contexto técnico.
        $entradas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($this->tamanhoLogAntes));
        $this->assertCount(1, $entradas);
        $this->assertSame('erros_openai', $entradas[0]['channel']);
        $mensagem = $entradas[0]['msg'];
        $this->assertIsString($mensagem);
        $this->assertStringContainsString($contextoEsperado, $mensagem);
        $this->assertStringNotContainsString('org-Minha-Empresa-Ltda', $mensagem);
        $this->assertStringContainsString('provider_error', $mensagem);
        $this->assertStringNotContainsString('segredo', $mensagem);
        $this->assertStringNotContainsString('conteudo_privado', $mensagem);
    }

    /**
     * A transcrição também não persiste a mensagem arbitrária do provedor.
     */
    public function testErroDaTranscricaoViraAvisoGenericoESemChaveNoLog(): void
    {
        $corpo = json_encode(['error' => ['message' => 'Incorrect API key provided: sk-proj-abc***xyz']]);
        $this->assertIsString($corpo);

        $resultado = interpretarTranscricaoWhisper($corpo, 401);

        $this->assertSame(
            ['tipo' => 'falha', 'texto' => 'Erro ao transcrever o áudio. Tente novamente! ❌'],
            $resultado
        );

        $entradas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($this->tamanhoLogAntes));
        $this->assertCount(1, $entradas);
        $this->assertSame('erros_openai', $entradas[0]['channel']);
        $mensagem = $entradas[0]['msg'];
        $this->assertIsString($mensagem);
        $this->assertStringContainsString('audio.transcriptions', $mensagem);
        $this->assertStringNotContainsString('Incorrect API key provided', $mensagem);
        $this->assertStringNotContainsString('sk-proj-abc', $mensagem);
        $this->assertStringContainsString('provider_error', $mensagem);
        $this->assertStringNotContainsString('segredo', $mensagem);
        $this->assertStringNotContainsString('conteudo_privado', $mensagem);
    }

    public function testWrappersDeChatUsamTransporteRealEFalhamFechadoSemRede(): void
    {
        $url = 'http://127.0.0.1:9/v1/chat/completions';
        $corpo = '{"model":"openai/gpt-6-luna","messages":[]}';

        $this->assertFalse(chamarChatCompletionsOpenAi($url, 'sk-teste', $corpo));
        $this->assertFalse(chamarChatCompletionsAudio($url, 'sk-teste', $corpo));
        $this->assertFalse(chamarChatCompletionsImagem($url, 'sk-teste', $corpo));
    }

    /**
     * A transcrição tem contrato diferente (multipart e par corpo/status).
     * Confirma o caminho sem callback usando um arquivo real e garante que
     * erro de conexão nunca seja confundido com resposta vazia de sucesso.
     */
    public function testWrapperDeTranscricaoUsaTransporteRealEFalhaFechadoSemRede(): void
    {
        $audio = tempnam(sys_get_temp_dir(), 'dealnibot_whisper_');
        $this->assertIsString($audio);
        file_put_contents($audio, 'audio de teste');

        try {
            $resultado = chamarTranscricaoWhisper(
                'http://127.0.0.1:9/v1/audio/transcriptions',
                'sk-teste',
                $audio
            );

            $this->assertSame(['corpo' => false, 'http' => 0], $resultado);
        } finally {
            @unlink($audio);
        }
    }
}
