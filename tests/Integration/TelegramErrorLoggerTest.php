<?php

declare(strict_types=1);

namespace Tests;

use App\Support\LoggerFactory;
use ArrayObject;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use TelegramErrorLogger;
use Tests\Support\AppLogReader;

require_once __DIR__ . '/../../inclusos/TelegramErrorLogger.php';

/**
 * Testes do TelegramErrorLogger, acionado a cada resposta da Bot API quando o
 * Telegram é construído com log_errors = true.
 *
 * O contrato é "inofensivo por padrão": sem LOGS_ATIVO definida, com resposta
 * que não seja array ou com ok que não seja false ESTRITO, nada é escrito. Só
 * erro de verdade vira linha JSON no app.log.
 *
 * Cada teste roda em processo separado porque LOGS_ATIVO é uma constante e não
 * pode ser redefinida. O diretório de log é DEALNI_LOGS_DIR (temp da suíte).
 */
final class TelegramErrorLoggerTest extends TestCase
{
    private int $tamanhoAntes;

    protected function setUp(): void
    {
        $this->tamanhoAntes = AppLogReader::tamanhoAtual();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFpmComFlagFalsePreservaFalhasELimitaMensagemSemForcarRuido(): void
    {
        define('LOGS_ATIVO', false);
        LoggerFactory::reset(); // Sem forCli: mesmos destinos do FPM.
        TelegramErrorLogger::log(['ok' => true, 'result' => ['text' => 'controle privado']]);
        TelegramErrorLogger::log(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified'], ['metodo' => 'editMessageText']);
        TelegramErrorLogger::log(['ok' => false, 'error_code' => 403, 'description' => 'bot blocked']);
        $this->assertLogInalterado();
        $descricao = "Falha legítima\n\x1b" . str_repeat('á', 10000);
        TelegramErrorLogger::log(['ok' => false, 'error_code' => 500, 'description' => $descricao], ['metodo' => 'sendMessage', 'chat_id' => 123, 'text' => 'privado']);
        TelegramErrorLogger::log(['ok' => false, 'error_code' => 7, 'description' => 'cURL error: Failed to connect']);
        $linhas = AppLogReader::linhasJson($this->conteudoAdicionado());
        $this->assertCount(2, $linhas);
        $this->assertSame(['error', 'error'], array_column($linhas, 'level'));
        $this->assertIsString($linhas[0]['msg']);
        $this->assertLessThanOrEqual(520, mb_strlen($linhas[0]['msg']));
        $this->assertStringStartsWith('Telegram API Error: Falha legítima', $linhas[0]['msg']);
        $this->assertStringNotContainsString("\n", $linhas[0]['msg']);
        $this->assertStringNotContainsString("\x1b", $linhas[0]['msg']);
        $this->assertStringNotContainsString('privado', $this->conteudoAdicionado());
        $this->assertIsArray($linhas[0]['context']);
        $contexto = $linhas[0]['context']['context'];
        $this->assertIsArray($contexto);
        $this->assertSame('sendMessage', $contexto['metodo']);
        $this->assertSame(123, $contexto['chat_id']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFalhaRealGravaMesmoQuandoLogsEstaoDesativados(): void
    {
        define('LOGS_ATIVO', false);
        TelegramErrorLogger::log(['ok' => false, 'description' => 'falha']);
        $this->assertSame('error', $this->unicaEntradaJson()['level']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFalhaRealGravaMesmoSemConstanteDefinida(): void
    {
        // Sem LOGS_ATIVO definida (o bootstrap não a define, e um processo de
        // produção sem dados_bot.php carregado também não), o log é ignorado.
        $this->assertFalse(defined('LOGS_ATIVO'));
        TelegramErrorLogger::log(['ok' => false, 'description' => 'falha']);
        $this->assertSame('error', $this->unicaEntradaJson()['level']);
    }

    /**
     * Desativar o arquivo não impede o evento de alcançar os destinos
     * configurados, inclusive stderr nos processos CLI.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLogsDesativadosMantemEventoDisponivelParaStderr(): void
    {
        define('LOGS_ATIVO', false);
        LoggerFactory::reset();
        $canal = LoggerFactory::channel('telegram', true);
        $this->assertInstanceOf(Logger::class, $canal);
        $espia = new TestHandler();
        $canal->pushHandler($espia);

        TelegramErrorLogger::log(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request']);

        $this->assertTrue($espia->hasErrorThatContains('Bad Request'));
        $this->assertSame('error', $this->unicaEntradaJson()['level']);

        // Controle: o espião está mesmo pendurado no canal que o logger usaria.
        // Sem ele, o assertSame acima passaria também se o espião nunca
        // estivesse ligado a canal nenhum.
        $canal->error('controle do espiao');
        $this->assertTrue($espia->hasErrorThatContains('controle do espiao'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNaoGravaRespostaBemSucedida(): void
    {
        define('LOGS_ATIVO', true);
        TelegramErrorLogger::log(['ok' => true, 'result' => []]);
        $this->assertLogInalterado();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNaoGravaQuandoRespostaNaoEArray(): void
    {
        define('LOGS_ATIVO', true);
        // A resposta vem de json_decode (mixed): sem array não há erro a logar.
        TelegramErrorLogger::log('nao sou array');
        TelegramErrorLogger::log(null);
        TelegramErrorLogger::log(false);
        TelegramErrorLogger::log(42);
        TelegramErrorLogger::log(1.5);
        // ArrayObject responde a isset() e a [] como um array responderia, mas
        // json_decode() nunca devolve um: a guarda é is_array() de propósito, e
        // um objeto com ArrayAccess vindo de outra camada não pode entrar no
        // lugar da resposta da Bot API.
        TelegramErrorLogger::log(new ArrayObject(['ok' => false, 'description' => 'Bad Request']));
        $this->assertLogInalterado();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNaoGravaQuandoOkAusenteOuNaoEstritamenteFalse(): void
    {
        define('LOGS_ATIVO', true);
        TelegramErrorLogger::log(['result' => []]);   // sem a chave 'ok'
        TelegramErrorLogger::log(['ok' => 0]);        // falsy, mas não é false estrito
        TelegramErrorLogger::log(['ok' => '']);       // falsy, mas não é false estrito
        TelegramErrorLogger::log(['ok' => 'false']);  // string não é erro da API
        TelegramErrorLogger::log(['ok' => null]);     // isset() é false para null
        $this->assertLogInalterado();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGravaErroComCodigoDescricaoEContexto(): void
    {
        define('LOGS_ATIVO', true);
        TelegramErrorLogger::log(
            ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request'],
            ['chat_id' => 123, 'metodo' => 'sendMessage']
        );

        $entrada = $this->unicaEntradaJson();
        $this->assertSame('Telegram API Error: Bad Request', $entrada['msg']);
        $this->assertSame('telegram', $entrada['channel']);
        $this->assertSame('error', $entrada['level']);
        $this->assertSame('400', $entrada['context']['error_code']);
        $interno = $this->contextoAninhado($entrada['context']);
        $this->assertSame(123, $interno['chat_id']);
        $this->assertSame('sendMessage', $interno['metodo']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUsaValoresPadraoQuandoApiOmiteDetalhes(): void
    {
        define('LOGS_ATIVO', true);
        TelegramErrorLogger::log(['ok' => false]);

        $entrada = $this->unicaEntradaJson();
        $this->assertSame('Telegram API Error: Unknown error', $entrada['msg']);
        $this->assertSame('0', $entrada['context']['error_code']);
        $this->assertSame([], $entrada['context']['context']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDetalhesNaoEscalaresCaemNosPadroes(): void
    {
        define('LOGS_ATIVO', true);
        TelegramErrorLogger::log([
            'ok' => false,
            'description' => ['lista'],
            'error_code' => ['lista'],
        ]);
        $entrada = $this->unicaEntradaJson();
        $this->assertSame('Telegram API Error: Unknown error', $entrada['msg']);
        $this->assertSame('0', $entrada['context']['error_code']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConverteDetalhesEscalaresNaoStringParaString(): void
    {
        define('LOGS_ATIVO', true);
        TelegramErrorLogger::log(['ok' => false, 'error_code' => '429', 'description' => true]);
        // (string) true vira "1" e o código escapa a conversão como string.
        $entrada = $this->unicaEntradaJson();
        $this->assertSame('Telegram API Error: 1', $entrada['msg']);
        $this->assertSame('429', $entrada['context']['error_code']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testContextoComUtf8InvalidoAindaEGravado(): void
    {
        // O contexto carrega texto digitado pelo usuário; o Normalizer do
        // Monolog substitui bytes inválidos em vez de derrubar o log.
        define('LOGS_ATIVO', true);
        TelegramErrorLogger::log(
            ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request'],
            ['texto' => "ola\xB1\x31"]
        );

        $entrada = $this->unicaEntradaJson();
        $this->assertSame('Telegram API Error: Bad Request', $entrada['msg']);
        $interno = $this->contextoAninhado($entrada['context']);
        $this->assertArrayNotHasKey('texto', $interno);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testContextoCiclicoNaoImpedeORegistroDoErro(): void
    {
        // O Normalizer do Monolog corta o ciclo; o erro da API tem de ser
        // gravado mesmo com um contexto que não serializa por completo.
        define('LOGS_ATIVO', true);
        $ciclico = ['metodo' => 'sendMessage'];
        $ciclico['proprio'] = &$ciclico;

        TelegramErrorLogger::log(
            ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request'],
            ['payload' => $ciclico]
        );

        $entrada = $this->unicaEntradaJson();
        $this->assertSame('Telegram API Error: Bad Request', $entrada['msg']);
        $interno = $this->contextoAninhado($entrada['context']);
        $this->assertArrayNotHasKey('payload', $interno);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testValorNaoSerializavelNoContextoViraRepresentacaoSemPerderOLog(): void
    {
        // Recurso aberto (handle de arquivo) não tem representação JSON nativa.
        // O Monolog normaliza para string; o erro da API permanece no log.
        define('LOGS_ATIVO', true);
        $recurso = fopen('php://memory', 'rb');
        $this->assertNotFalse($recurso);

        TelegramErrorLogger::log(
            ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request'],
            ['arquivo' => $recurso]
        );
        fclose($recurso);

        $entrada = $this->unicaEntradaJson();
        $this->assertSame('Telegram API Error: Bad Request', $entrada['msg']);
        $interno = $this->contextoAninhado($entrada['context']);
        $this->assertArrayNotHasKey('arquivo', $interno);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAcumulaEntradasEmChamadasSucessivas(): void
    {
        define('LOGS_ATIVO', true);
        TelegramErrorLogger::log(['ok' => false, 'error_code' => 400, 'description' => 'Primeiro erro']);
        TelegramErrorLogger::log(['ok' => false, 'error_code' => 429, 'description' => 'Segundo erro']);

        $linhas = AppLogReader::linhasJson($this->conteudoAdicionado());
        $this->assertCount(2, $linhas);
        $this->assertSame('Telegram API Error: Primeiro erro', $linhas[0]['msg']);
        $this->assertSame('Telegram API Error: Segundo erro', $linhas[1]['msg']);
        $contexto0 = $linhas[0]['context'] ?? null;
        $contexto1 = $linhas[1]['context'] ?? null;
        $this->assertIsArray($contexto0);
        $this->assertIsArray($contexto1);
        $this->assertSame('400', $contexto0['error_code']);
        $this->assertSame('429', $contexto1['error_code']);
    }

    /**
     * O bloco de carga sob demanda do autoload do Composer.
     *
     * Telegram.php dá `require_once` em TelegramErrorLogger.php sozinho, e é
     * carregado por entrypoints que podem nunca ter passado pelo autoload
     * (bot_web.php dos bots legados, script solto de manutenção). Nesse
     * processo `App\Support\LoggerFactory` não existe, e sem carregar o
     * `vendor/autoload.php` na mão o erro da Bot API sumiria — sem exceção,
     * sem aviso, sem linha no log.
     *
     * O cenário NÃO é reproduzível dentro da suíte: o bootstrap registra o
     * autoload, e `class_exists()` devolve true para classe já declarada mesmo
     * sem autoloader nenhum. Por isso ele é montado num subprocesso limpo, que
     * imprime a sentinela ANTES/DEPOIS — a prova de que o processo começou sem
     * a classe e terminou com ela.
     */
    public function testSemOAutoloadDoComposerOLoggerOCarregaSobDemandaEGravaAMesmaLinha(): void
    {
        $diretorio = sys_get_temp_dir() . '/dealnibot-sem-autoload-' . uniqid();
        $classe = var_export(LoggerFactory::class, true);
        $script = '<?php' . "\n"
            . 'putenv(' . var_export('DEALNI_LOGS_DIR=' . $diretorio, true) . ');' . "\n"
            . 'define(\'LOGS_ATIVO\', true);' . "\n"
            . 'echo class_exists(' . $classe . ') ? "ANTES:sim\n" : "ANTES:nao\n";' . "\n"
            . 'require ' . var_export(dirname(__DIR__, 2) . '/inclusos/TelegramErrorLogger.php', true) . ';' . "\n"
            . 'TelegramErrorLogger::log(' . "\n"
            . '    [\'ok\' => false, \'error_code\' => 429, \'description\' => \'Too Many Requests\'],' . "\n"
            . '    [\'metodo\' => \'sendMessage\']' . "\n"
            . ');' . "\n"
            . 'echo class_exists(' . $classe . ') ? "DEPOIS:sim\n" : "DEPOIS:nao\n";' . "\n";

        try {
            $saida = $this->rodarEmSubprocesso($script);

            // O processo realmente começou sem o autoload registrado, e a
            // classe passou a existir só depois da chamada ao logger.
            $this->assertStringContainsString('ANTES:nao', $saida);
            $this->assertStringContainsString('DEPOIS:sim', $saida);

            $entrada = $this->unicaEntradaJsonDe($diretorio);
            $this->assertSame('Telegram API Error: Too Many Requests', $entrada['msg']);
            $this->assertSame('telegram', $entrada['channel']);
            $this->assertSame('error', $entrada['level']);
            $this->assertSame('429', $entrada['context']['error_code']);
        } finally {
            $this->limparDiretorio($diretorio);
        }
    }

    /**
     * Executa um script PHP em subprocesso e devolve a saída combinada.
     *
     * O subprocesso herda o isolamento de rede da suíte: ele só escreve em
     * disco, num diretório temporário do próprio teste.
     */
    private function rodarEmSubprocesso(string $script): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'dealnibot_sem_autoload_');
        $this->assertIsString($caminho, 'tempnam precisa criar o script do subprocesso.');

        try {
            $this->assertNotFalse(file_put_contents($caminho, $script));

            $linhas = [];
            $codigo = -1;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($caminho) . ' 2>&1', $linhas, $codigo);
            $saida = implode("\n", $linhas);
            $this->assertSame(0, $codigo, "O subprocesso precisa terminar sem erro. Saída:\n" . $saida);

            return $saida;
        } finally {
            @unlink($caminho);
        }
    }

    /**
     * Única linha JSON gravada no app.log de um diretório de logs qualquer.
     *
     * O arquivo é localizado por glob, e não por date(): o nome carrega a data
     * do rotativo, e um teste que a calcule por conta própria quebra sozinho na
     * virada do dia.
     *
     * @return array{msg: mixed, channel: mixed, level: mixed, context: array<string, mixed>}
     */
    private function unicaEntradaJsonDe(string $diretorio): array
    {
        $arquivos = glob($diretorio . '/app-*.log');
        $this->assertIsArray($arquivos);
        $this->assertCount(1, $arquivos, 'O logger precisa ter criado exatamente um app.log em ' . $diretorio . '.');

        $conteudo = file_get_contents($arquivos[0]);
        $this->assertIsString($conteudo);
        $linhas = AppLogReader::linhasJson($conteudo);
        $this->assertCount(1, $linhas);
        $entrada = $linhas[0];

        return [
            'msg' => $entrada['msg'] ?? null,
            'channel' => $entrada['channel'] ?? null,
            'level' => $entrada['level'] ?? null,
            'context' => $this->comoMapa($entrada['context'] ?? null, 'context'),
        ];
    }

    private function limparDiretorio(string $diretorio): void
    {
        $restos = glob($diretorio . '/*');
        foreach ($restos === false ? [] : $restos as $resto) {
            @unlink($resto);
        }
        @rmdir($diretorio);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEdicaoERecusaNaoOcultamTransporteOuServidor(): void
    {
        define('LOGS_ATIVO', true);
        $esperados = [];
        foreach ([
            [400, 'editMessageReplyMarkup', 'Bad Request: message is not modified', 0, 'debug'],
            [500, 'editMessageText', 'Bad Request: message is not modified', 0, 'error'],
            [400, 'sendMessage', 'Bad Request: message is not modified', 0, 'error'],
            [400, '', 'Bad Request: message is not modified', 0, 'error'],
            [400, 'editMessageText', 'Bad Request: message is not modified', 28, 'error'],
            ['400', 'editMessageText', 'Bad Request: message is not modified', 0, 'error'],
            [403, 'sendMessage', 'Forbidden: bot was blocked by the user', 28, 'error'],
        ] as [$codigo, $metodo, $descricao, $curl, $nivel]) {
            TelegramErrorLogger::log(['ok' => false, 'error_code' => $codigo, 'description' => $descricao,
                'curl_error_code' => $curl], ['metodo' => $metodo]);
            $esperados[] = $nivel;
        }
        $linhas = AppLogReader::linhasJson($this->conteudoAdicionado());
        $this->assertSame($esperados, array_column($linhas, 'level'));
    }

    private function assertLogInalterado(): void
    {
        $this->assertSame('', $this->conteudoAdicionado());
    }

    private function conteudoAdicionado(): string
    {
        return AppLogReader::conteudoDesde($this->tamanhoAntes);
    }

    /**
     * @return array{msg: mixed, channel: mixed, level: mixed, context: array<string, mixed>}
     */
    private function unicaEntradaJson(): array
    {
        $linhas = AppLogReader::linhasJson($this->conteudoAdicionado());
        $this->assertCount(1, $linhas);
        $entrada = $linhas[0];

        return [
            'msg' => $entrada['msg'] ?? null,
            'channel' => $entrada['channel'] ?? null,
            'level' => $entrada['level'] ?? null,
            'context' => $this->comoMapa($entrada['context'] ?? null, 'context'),
        ];
    }

    /**
     * @param array<string, mixed> $contexto
     * @return array<string, mixed>
     */
    private function contextoAninhado(array $contexto): array
    {
        return $this->comoMapa($contexto['context'] ?? null, 'context.context');
    }

    /**
     * Campo do log lido como mapa de chaves TEXTUAIS.
     *
     * A checagem das chaves não é cerimônia de tipagem: o log é JSON de
     * objeto, e chave numérica aqui significa que o campo virou LISTA — o
     * consumidor do log (quem lê `context.error_code` depois de um incidente)
     * passaria a não achar nada, em silêncio.
     *
     * @return array<string, mixed>
     */
    private function comoMapa(mixed $valor, string $campo): array
    {
        $this->assertIsArray($valor, "O campo {$campo} do log precisa ser um objeto JSON.");

        $mapa = [];
        foreach ($valor as $chave => $item) {
            $this->assertIsString($chave, "O campo {$campo} do log precisa ter chaves textuais.");
            $mapa[$chave] = $item;
        }

        return $mapa;
    }
}
