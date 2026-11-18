<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Trava o contrato que o roteador.php tem com o LOOP de bot.php.
 *
 * bot.php busca os lotes por executarLongPollingTelegram()
 * (inclusos/funcoes_polling.php), que chama o callback uma vez por update e só
 * envia o offset de confirmação DEPOIS que o lote inteiro terminou. O roteador
 * é incluído dentro desse callback. Se ele encerrar o processo, o lote fica sem
 * confirmação: as mensagens já atendidas voltam a ser entregues e respondidas
 * de novo (chamada paga à OpenAI cobrada duas vezes) e o laço de polling morre
 * até o bot.sh reiniciá-lo. A regra `return`, nunca `exit` vale ainda mais do
 * que quando o lote era confirmado antecipadamente.
 *
 * Cada teste roda num SUBPROCESSO (padrão de BdTelegramConexaoTest): se o
 * roteador voltar a chamar exit, o subprocesso morre sem imprimir a sentinela
 * e o teste falha aqui — em vez de matar o runner do PHPUnit no meio da suíte.
 * Nenhuma rede é usada: Telegram recebe os dados por setData(), o bdTelegram
 * aponta para um schema PostgreSQL descartável e o Redis nunca chama connect().
 *
 * Os updates usados de propósito NÃO disparam comando algum (nada de chat
 * privado, foto, reply ao bot ou texto com "dealni"): incluir comandos/*.php
 * faria chamadas reais à OpenAI e ao Telegram.
 */
final class InfraRoteadorPollingTest extends TestCase
{
    /** Impressa pelo subprocesso ao fim de cada volta do laço. */
    private const SENTINELA = 'VOLTA_CONCLUIDA';

    /** @var string Identificador do schema PostgreSQL descartável deste teste. */
    private string $tmp = '';

    /** @var array<int, string> Arquivos de fixture criados pelo teste. */
    private array $fixtures = [];

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dealnibot_polling_');
        $this->assertIsString($tmp, 'tempnam precisa criar o identificador do schema de teste.');
        $this->tmp = $tmp;

        $sql = file_get_contents(__DIR__ . '/../../database/schema.sql');
        $this->assertIsString($sql, 'database/schema.sql precisa existir e ser legível.');
        $pdo = \BancoTeste::conectar($this->tmp);
        $pdo->exec($sql);
        $pdo = null;
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && file_exists($this->tmp)) {
            @unlink($this->tmp);
        }
        foreach ($this->fixtures as $arquivo) {
            if (file_exists($arquivo)) {
                @unlink($arquivo);
            }
        }
        $this->fixtures = [];
    }

    // ---------------------------------------------------------------------
    // Comportamento
    // ---------------------------------------------------------------------

    public function testUpdateQueNaoEhMensagemDevolveOControleAoChamador(): void
    {
        // Ramo `if ($tipo_de_update != 'message')`: um update editado em grupo
        // não é atendido por nenhum comando, mas também não pode derrubar o
        // processo — o próximo update do lote ainda precisa ser tratado.
        $resultado = $this->rodarLaco([$this->updateEditadoEmGrupo(1)]);

        $this->assertSame(0, $resultado['codigo'], 'Saída do subprocesso: ' . $resultado['saida']);
        $this->assertSame([self::SENTINELA . ':1'], $this->voltas($resultado['saida']));
    }

    public function testLoteInteiroEhTratadoEmVezDeSoOPrimeiroUpdate(): void
    {
        // O cenário real do bug: três updates confirmados no mesmo getUpdates().
        // Com `exit` no roteador, apenas o primeiro era tratado e os outros
        // dois sumiam (já confirmados no Telegram, nunca reentregues).
        $resultado = $this->rodarLaco([
            $this->updateEditadoEmGrupo(1),
            $this->updateEditadoEmGrupo(2),
            $this->updateDeCanalSemGatilho(3),
        ]);

        $this->assertSame(0, $resultado['codigo'], 'Saída do subprocesso: ' . $resultado['saida']);
        $this->assertSame(
            [self::SENTINELA . ':1', self::SENTINELA . ':2', self::SENTINELA . ':3'],
            $this->voltas($resultado['saida'])
        );
    }

    public function testChatPrivadoEhIgnoradoAntesDeQualquerComando(): void
    {
        $resultado = $this->rodarLaco([$this->updatePrivado(1)]);

        $this->assertSame(0, $resultado['codigo'], 'Saída do subprocesso: ' . $resultado['saida']);
        $this->assertSame(
            [self::SENTINELA . ':1'],
            $this->voltas($resultado['saida']),
            'Ignorar um chat privado precisa devolver o controle ao laço de polling.'
        );
        $this->assertStringNotContainsString('Cannot redeclare', $resultado['saida']);
    }

    public function testBotPhpContinuaIncluindoORoteadorDentroDoCallbackDoPolling(): void
    {
        // A garantia acima só vale porque bot.php inclui o roteador DENTRO do
        // callback chamado por update; se o include sair de lá, o contrato
        // testado aqui deixa de descrever a produção.
        $fonte = file_get_contents(__DIR__ . '/../../bot.php');
        $this->assertIsString($fonte, 'bot.php precisa ser legível.');

        $posicaoLaco = strpos($fonte, 'executarLongPollingTelegram(');
        $posicaoInclude = strpos($fonte, "require __DIR__ . '/roteador.php';");
        $this->assertIsInt($posicaoLaco, 'bot.php precisa consumir os lotes por executarLongPollingTelegram().');
        $this->assertIsInt($posicaoInclude, 'bot.php precisa incluir o roteador a cada update.');
        $this->assertGreaterThan($posicaoLaco, $posicaoInclude, 'O roteador precisa ser incluído dentro do callback.');

        // A confirmação antecipada do lote não pode voltar: `getUpdates()` sem
        // argumentos dispara uma segunda chamada com offset ANTES de o lote ser
        // processado (Telegram::getUpdates, $update = true por padrão).
        $this->assertStringNotContainsString('$telegram->getUpdates();', $fonte);

        // E a conexão de banco continua sendo por update, dentro de try/catch:
        // o construtor canônico lança em falha de conexão.
        $this->assertStringContainsString('$bd = new bdTelegram();', $fonte);
        $this->assertStringContainsString('} catch (Throwable $e) {', $fonte);
        $this->assertStringContainsString('ecoarEEncerrar($e->getMessage());', $fonte);
    }

    // ---------------------------------------------------------------------
    // Segundo update do lote que cai no mesmo comando
    // ---------------------------------------------------------------------

    /**
     * O roteador inclui o comando com `require` (não `require_once`) e bot.php
     * inclui o roteador DENTRO do laço do lote. Enquanto os comandos declaravam
     * funções no topo, o SEGUNDO update do mesmo lote que caísse em um comando
     * matava o processo com "Cannot redeclare" — e o resto do lote, já
     * confirmado pelo getUpdates, era perdido: o mesmo prejuízo que o `exit`
     * causava, por outro mecanismo.
     *
     * Este teste prova que o MECANISMO existe, com arquivos de fixture que têm
     * a forma antiga (declaram função no topo). Ele é a metade de baixo da
     * garantia: a de cima é
     * testNenhumComandoDeclaraFuncaoEntaoORequireRepetidoEhSeguro(), que
     * confere por tokenização que nenhum comando real tem mais essa forma.
     * Juntos, os dois travam a correção — as declarações vivem em
     * comandos/funcoes_*.php, carregados com require_once.
     *
     * `require_once` no roteador NÃO seria a correção: silenciaria o bot a
     * partir da segunda mensagem, porque o topo do comando não voltaria a rodar.
     */
    public function testSegundoUpdateDoLoteQueCaiNoMesmoComandoMataOProcesso(): void
    {
        $comando = $this->fixtureDeComando('fixture_comando_um');

        $resultado = $this->rodarLacoComRequire([$comando, $comando]);

        // A primeira volta terminou; a segunda matou o processo.
        $this->assertSame([self::SENTINELA . ':1'], $this->voltas($resultado['saida']));
        $this->assertNotSame(0, $resultado['codigo'], 'O segundo require precisa derrubar o processo.');
        $this->assertStringContainsString('Cannot redeclare', $resultado['saida']);
    }

    /**
     * Variante do mesmo defeito entre comandos DIFERENTES: cortarMensagem(),
     * escapeMarkdown() e lerTextoDoRedis() eram declaradas nos três comandos de
     * IA com o mesmo nome, então um lote com uma mensagem de texto e um áudio
     * derrubava o processo na segunda volta mesmo caindo em comandos distintos.
     * Hoje as três moram uma única vez em comandos/funcoes_ia_comuns.php; o
     * teste segue provando o mecanismo com fixtures da forma antiga.
     */
    public function testUpdatesDeComandosDiferentesTambemColidemPelosHelpersDuplicados(): void
    {
        $primeiro = $this->fixtureDeComando('fixture_comando_a');
        $segundo = $this->fixtureDeComando('fixture_comando_b');

        $resultado = $this->rodarLacoComRequire([$primeiro, $segundo]);

        $this->assertSame([self::SENTINELA . ':1'], $this->voltas($resultado['saida']));
        $this->assertNotSame(0, $resultado['codigo']);
        $this->assertStringContainsString('Cannot redeclare', $resultado['saida']);
    }

    /**
     * A metade de cima da garantia: nenhum comando pode voltar a declarar
     * função no próprio arquivo. É o que torna seguro o `require` puro que o
     * roteador faz uma vez por update — e, por tabela, o que impede a volta do
     * "Cannot redeclare function" provado com fixtures nos dois testes acima.
     *
     * As funções vivem em comandos/funcoes_<comando>.php (mais o
     * comandos/funcoes_ia_comuns.php dos três comandos de IA), carregados com
     * require_once: o segundo include é no-op e o topo do comando, que é o que
     * precisa rodar a cada update, continua rodando.
     */
    public function testNenhumComandoDeclaraFuncaoEntaoORequireRepetidoEhSeguro(): void
    {
        $raiz = dirname(__DIR__, 2);
        $rotas = ['imagem', 'audio', 'chatgpt'];

        foreach ($rotas as $rota) {
            $relativo = \roteamento_arquivoDaRota($rota);
            $arquivo = $raiz . '/' . $relativo;

            $this->assertSame(
                [],
                $this->funcoesDeclaradas($arquivo),
                "{$relativo} voltou a declarar função no próprio arquivo: o segundo update "
                    . 'do lote roteado para cá mata o processo com "Cannot redeclare function" '
                    . 'e o resto do lote, já confirmado pelo getUpdates, é perdido.'
            );

            // E as funções que ele usa continuam vindo por require_once.
            $fonte = file_get_contents($arquivo);
            $this->assertIsString($fonte, "{$relativo} precisa ser legível.");
            $this->assertMatchesRegularExpression(
                "/require_once __DIR__ \. '\/funcoes_[a-z_]+\.php';/",
                $fonte,
                "{$relativo} precisa carregar suas funções com require_once."
            );
        }

        // Os três comandos de IA compartilham cortarMensagem(), escapeMarkdown()
        // e lerTextoDoRedis(): uma declaração só, no arquivo comum.
        $comuns = $this->funcoesDeclaradas($raiz . '/comandos/funcoes_ia_comuns.php');
        $this->assertContains('cortarMensagem', $comuns);
        $this->assertContains('escapeMarkdown', $comuns);
        $this->assertContains('lerTextoDoRedis', $comuns);

        // E o roteador inclui o comando com `require` puro, uma vez por update.
        $fonte = file_get_contents($raiz . '/roteador.php');
        $this->assertIsString($fonte, 'roteador.php precisa ser legível.');
        $requires = 0;
        $requiresOnce = 0;
        foreach (token_get_all($fonte) as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_REQUIRE) {
                $requires++;
            }
            if ($token[0] === T_REQUIRE_ONCE) {
                $requiresOnce++;
            }
        }
        $this->assertSame(3, $requires, 'Um require por ramo que dispara comando em grupos.');
        $this->assertSame(3, $requiresOnce, 'A configuração, os auxiliares de roteamento e a recusa de escrita usam require_once.');
        $this->assertStringContainsString(
            "require __DIR__ . '/' . roteamento_arquivoDaRota(\$rota);",
            $fonte
        );
    }

    /**
     * Nomes das funções declaradas no topo de um script, lidos por tokenização
     * (nenhuma linha do arquivo é executada).
     *
     * @return array<int, string>
     */
    private function funcoesDeclaradas(string $caminho): array
    {
        $codigo = file_get_contents($caminho);
        $this->assertIsString($codigo, "{$caminho} precisa ser legível.");

        $tokens = token_get_all($codigo);
        $total = count($tokens);
        $nomes = [];

        for ($indice = 0; $indice < $total; $indice++) {
            $token = $tokens[$indice];
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            for ($seguinte = $indice + 1; $seguinte < $total; $seguinte++) {
                $candidato = $tokens[$seguinte];
                if (is_array($candidato)
                    && in_array($candidato[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
                ) {
                    continue;
                }
                if (is_array($candidato) && $candidato[0] === T_STRING) {
                    $nomes[] = $candidato[1];
                }
                break; // closure ou `&`: não é declaração nomeada simples
            }
        }

        return $nomes;
    }

    /**
     * Cria um arquivo com a MESMA forma dos comandos: declara helpers no topo
     * (com os nomes reais duplicados entre os comandos de IA) e só depois roda
     * o fluxo. Nada de rede, banco ou Telegram.
     */
    private function fixtureDeComando(string $prefixo): string
    {
        $caminho = tempnam(sys_get_temp_dir(), $prefixo);
        if ($caminho === false) {
            self::fail('Não foi possível criar a fixture do comando.');
        }
        $this->fixtures[] = $caminho;

        $conteudo = '<?php' . "\n"
            . 'function cortarMensagem($mensagem, $limite = 4096) { return $mensagem; }' . "\n"
            . 'function lerTextoDoRedis($redis, $chave) { return ""; }' . "\n"
            . '$efeito = cortarMensagem("fluxo do comando");' . "\n";
        $this->assertNotFalse(file_put_contents($caminho, $conteudo));

        return $caminho;
    }

    /**
     * Reproduz o laço de bot.php incluindo com `require` puro (como o roteador
     * faz) o arquivo indicado para cada update do lote.
     *
     * @param array<int, string> $comandosPorUpdate
     * @return array{codigo: int, saida: string}
     */
    private function rodarLacoComRequire(array $comandosPorUpdate): array
    {
        $script = '<?php' . "\n"
            . '$comandos = ' . var_export($comandosPorUpdate, true) . ';' . "\n"
            . 'foreach ($comandos as $i => $comando) {' . "\n"
            . '    require $comando;' . "\n"
            . '    echo ' . var_export(self::SENTINELA . ':', true) . ' . ($i + 1) . PHP_EOL;' . "\n"
            . '}' . "\n";

        return $this->rodarScript($script);
    }

    /**
     * Prova executável da correção, com os arquivos REAIS de produção: carregar
     * os quatro funcoes_*.php duas vezes cada — o que o roteador faz quando o
     * lote traz dois updates de IA — não redeclara nada e o processo termina
     * inteiro. Sem o require_once (ou com as declarações de volta no comando),
     * a segunda volta morreria com "Cannot redeclare function", como os dois
     * testes de fixture acima demonstram.
     *
     * Incluir estes arquivos é seguro justamente porque eles SÓ declaram
     * funções — nenhum efeito no include, ao contrário dos comandos.
     */
    public function testCarregarOsArquivosDeFuncoesDuasVezesNaoDerrubaOProcesso(): void
    {
        $comandos = dirname(__DIR__, 2) . '/comandos';
        $arquivos = [
            $comandos . '/funcoes_ia_comuns.php',
            $comandos . '/funcoes_chatgpt.php',
            $comandos . '/funcoes_ver_audio.php',
            $comandos . '/funcoes_ver_imagem.php',
        ];
        foreach ($arquivos as $arquivo) {
            $this->assertFileExists($arquivo);
        }

        $script = '<?php' . "\n"
            . '$arquivos = ' . var_export($arquivos, true) . ';' . "\n"
            . 'foreach ([1, 2] as $volta) {' . "\n"
            . '    foreach ($arquivos as $arquivo) {' . "\n"
            . '        require_once $arquivo;' . "\n"
            . '    }' . "\n"
            . '    echo ' . var_export(self::SENTINELA . ':', true) . ' . $volta . PHP_EOL;' . "\n"
            . '}' . "\n";

        $resultado = $this->rodarScript($script);

        $this->assertSame(0, $resultado['codigo'], $resultado['saida']);
        $this->assertStringNotContainsString('Cannot redeclare', $resultado['saida']);
        $this->assertSame(
            [self::SENTINELA . ':1', self::SENTINELA . ':2'],
            $this->voltas($resultado['saida']),
            'As duas voltas do lote precisam terminar.'
        );
    }

    // ---------------------------------------------------------------------
    // Updates que não disparam comando algum
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function updateEditadoEmGrupo(int $updateId): array
    {
        return [
            'update_id' => $updateId,
            'edited_message' => [
                'message_id' => $updateId,
                'from' => ['id' => 111, 'is_bot' => false],
                'chat' => ['id' => -50, 'type' => 'group'],
                'text' => 'corrigindo o que escrevi',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function updateDeCanalSemGatilho(int $updateId): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => 222, 'is_bot' => false],
                'chat' => ['id' => -60, 'type' => 'channel'],
                // ATENÇÃO: o texto NÃO pode conter "dealni" (dispararia chatgpt.php).
                'text' => 'aviso do canal',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePrivado(int $updateId): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => 333, 'is_bot' => false],
                'chat' => ['id' => 333, 'type' => 'private'],
                'text' => '/start',
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Subprocesso
    // ---------------------------------------------------------------------

    /**
     * Reproduz o laço de bot.php em um subprocesso, incluindo o roteador REAL
     * uma vez por update e imprimindo a sentinela ao fim de cada volta.
     *
     * @param array<int, array<string, mixed>> $updates
     * @return array{codigo: int, saida: string}
     */
    private function rodarLaco(array $updates): array
    {
        $raiz = dirname(__DIR__, 2);

        $script = '<?php' . "\n"
            . 'require ' . var_export($raiz . '/inclusos/Telegram.php', true) . ';' . "\n"
            . 'require ' . var_export($raiz . '/inclusos/bdTelegram.php', true) . ';' . "\n"
            . '$updates = ' . var_export($updates, true) . ';' . "\n"
            . '$telegram = new Telegram(' . var_export('123456:FAKE_TOKEN', true) . ', false);' . "\n"
            . '$bd = new bdTelegram(' . var_export(\BancoTeste::dsn($this->tmp), true) . ');' . "\n"
            . '$redis = new Redis();' . "\n"
            . 'foreach ($updates as $i => $update) {' . "\n"
            . '    $telegram->setData($update);' . "\n"
            . '    require ' . var_export($raiz . '/roteador.php', true) . ';' . "\n"
            . '    echo ' . var_export(self::SENTINELA . ':', true) . ' . ($i + 1) . PHP_EOL;' . "\n"
            . '}' . "\n";

        return $this->rodarScript($script);
    }

    /**
     * Executa um script PHP em subprocesso e devolve o código de saída e a
     * saída combinada (stdout + stderr).
     *
     * @return array{codigo: int, saida: string}
     */
    private function rodarScript(string $script): array
    {
        $caminhoScript = tempnam(sys_get_temp_dir(), 'phpunit_polling_');
        if ($caminhoScript === false) {
            self::fail('Não foi possível criar o script temporário do subprocesso.');
        }

        try {
            $this->assertNotFalse(file_put_contents($caminhoScript, $script));

            $binario = escapeshellarg(PHP_BINARY);
            $comando = $binario . ' ' . escapeshellarg($caminhoScript) . ' 2>&1';
            $linhas = [];
            $codigo = -1;
            exec($comando, $linhas, $codigo);
        } finally {
            @unlink($caminhoScript);
        }

        return ['codigo' => $codigo, 'saida' => implode("\n", $linhas)];
    }

    /**
     * Sentinelas impressas pelo subprocesso, na ordem.
     *
     * @return array<int, string>
     */
    private function voltas(string $saida): array
    {
        $linhas = array_filter(
            array_map('trim', explode("\n", $saida)),
            static fn (string $linha): bool => str_starts_with($linha, self::SENTINELA)
        );

        return array_values($linhas);
    }
}
