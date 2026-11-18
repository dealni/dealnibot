<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedisException;
use RuntimeException;

/**
 * Testes do SCRIPT LUA da cota diária usada pelos comandos de IA
 * (consumir_limite_diario(), em inclusos/limites_requisicoes.php, chamada por
 * comandos/chatgpt.php em chat privado).
 *
 * Os demais testes da cota usam um dublê que IMITA o que o script deveria
 * fazer — com ele, trocar o corpo do Lua por `return 0`, remover o EXPIRE ou
 * mudar a condição `atual == 1` mantinha a suíte verde. Aqui o dublê
 * (Support\RedisFalsoComLua) EXECUTA o Lua recebido, então cada asserção
 * abaixo depende da lógica que está dentro do script.
 *
 * Nada é conectado: o dublê é um Redis em memória e nenhum comando sai do
 * processo.
 */
final class LimiteDiarioLuaTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../inclusos/limites_requisicoes.php';
    }

    private function chaveDeHoje(int $id): string
    {
        return "limite_diario:{$id}:" . limite_diario_dia_atual();
    }

    public function testAdmissaoCompartilhaRajadaEntreImagemEAudioSemCobrarCotaPaga(): void
    {
        $redis = new Support\RedisFalsoComLua();
        $sujeito = 'dealnibot:midia:v1:sujeito:-7';
        $global = 'dealnibot:midia:v1:global';
        foreach ([1, 2, 1, 2, 1] as $unidades) {
            $this->assertSame('permitida', admitir_midia($redis, -7, $unidades));
        }
        $this->assertSame('temporario', admitir_midia($redis, -7, 2));
        $this->assertSame('5', $redis->valorDe($sujeito));
        $this->assertSame('5', $redis->valorDe($global));
        $this->assertNull($redis->valorDe($this->chaveDeHoje(-7)));
        $this->assertSame(60, $redis->expiracaoDe($sujeito));
        $this->assertSame(60, $redis->expiracaoDe($global));
        $this->assertCount(2, array_filter(
            $redis->operacoes(),
            static fn (string $op): bool => str_starts_with($op, 'EXPIRE ')
        ));

        $redis->vencerChave($sujeito);
        $this->assertSame('permitida', admitir_midia($redis, -7, 2));
        $this->assertSame('1', $redis->valorDe($sujeito));
        $this->assertSame('6', $redis->valorDe($global));
    }

    public function testAdmissaoGlobalLimitaGruposDistintosSemDebitarUmaRecusa(): void
    {
        $redis = new Support\RedisFalsoComLua();
        for ($grupo = -1; $grupo >= -4; $grupo--) {
            for ($tentativa = 0; $tentativa < 5; $tentativa++) {
                $this->assertSame('permitida', admitir_midia($redis, $grupo, 1));
            }
        }
        $this->assertSame('temporario', admitir_midia($redis, -5, 1));
        $this->assertNull($redis->valorDe('dealnibot:midia:v1:sujeito:-5'));
        $this->assertSame('20', $redis->valorDe('dealnibot:midia:v1:global'));
        $redis->vencerChave('dealnibot:midia:v1:global');
        $this->assertSame('permitida', admitir_midia($redis, -5, 1));
    }

    public function testAdmissaoConsideraDuasUnidadesDoAudioEMantemReservaFinal(): void
    {
        $redis = new Support\RedisFalsoComLua();
        $redis->semear($this->chaveDeHoje(-7), '99');
        $this->assertSame('cota', admitir_midia($redis, -7, 2));
        $this->assertNull($redis->valorDe('dealnibot:midia:v1:global'));
        $this->assertSame('permitida', admitir_midia($redis, -7, 1));
        // Outro update consome a última unidade durante o download admitido.
        $this->assertTrue(consumir_limite_diario($redis, -7)['permitida']);
        $this->assertFalse(consumir_limite_diario($redis, -7)['permitida']);
        $this->assertSame('cota', admitir_midia($redis, -7, 1));
        $this->assertSame('100', $redis->valorDe($this->chaveDeHoje(-7)));
        $this->assertSame('1', $redis->valorDe('dealnibot:midia:v1:global'));
    }

    public function testAdmissaoRecusaEstadoInvalidoOuRedisIndisponivel(): void
    {
        foreach ([$this->chaveDeHoje(-7), 'dealnibot:midia:v1:sujeito:-7', 'dealnibot:midia:v1:global'] as $chave) {
            foreach (['invalido', '-1'] as $valor) {
                $redis = new Support\RedisFalsoComLua();
                $redis->semear($chave, $valor);
                $this->assertNotSame('permitida', admitir_midia($redis, -7, 1));
                $this->assertSame([], array_values(array_filter(
                    $redis->operacoes(),
                    static fn (string $op): bool => str_starts_with($op, 'INCRBY ')
                )));
            }
        }
        $redis = new Support\RedisFalsoComLua();
        $redis->ficarIndisponivel();
        $this->assertSame('cota', admitir_midia($redis, -7, 1));
        $this->assertSame('cota', admitir_midia($redis, -7, 0));
        $this->assertSame('cota', admitir_midia($redis, -7, 3));
    }

    // ---------------------------------------------------------------------
    // Comportamento do script Lua
    // ---------------------------------------------------------------------

    public function testScriptContaCadaConsumoEDevolveAContagemAtual(): void
    {
        $redis = new Support\RedisFalsoComLua();
        $chave = $this->chaveDeHoje(7);

        // A contagem devolvida vem do `return` do próprio script Lua.
        $this->assertSame(1, consumir_limite_diario($redis, 7, 3)['contagem_atual']);
        $this->assertSame(2, consumir_limite_diario($redis, 7, 3)['contagem_atual']);
        $this->assertSame(3, consumir_limite_diario($redis, 7, 3)['contagem_atual']);

        // E o contador realmente ficou registrado no Redis.
        $this->assertSame('3', $redis->valorDe($chave));
    }

    public function testScriptAplicaTtlDe24hApenasNaCriacaoDaChave(): void
    {
        $redis = new Support\RedisFalsoComLua();
        $chave = $this->chaveDeHoje(8);

        consumir_limite_diario($redis, 8, 100);
        consumir_limite_diario($redis, 8, 100);
        consumir_limite_diario($redis, 8, 100);

        // Ordem exata das operações executadas DENTRO do Lua: o EXPIRE só
        // acontece no incremento que cria a chave (retorno 1).
        $this->assertSame(
            [
                'GET ' . $chave,
                'INCRBY ' . $chave . ' 1',
                'EXPIRE ' . $chave . ' 86400',
                'GET ' . $chave,
                'INCRBY ' . $chave . ' 1',
                'GET ' . $chave,
                'INCRBY ' . $chave . ' 1',
            ],
            $redis->operacoes()
        );
        $this->assertSame(86400, $redis->expiracaoDe($chave));
    }

    public function testScriptReaplicaTtlQuandoAChaveVenceEEhRecriada(): void
    {
        $redis = new Support\RedisFalsoComLua();
        $chave = $this->chaveDeHoje(9);

        consumir_limite_diario($redis, 9, 100);
        consumir_limite_diario($redis, 9, 100);

        // O TTL venceu no meio do dia e o Redis apagou a chave.
        $redis->vencerChave($chave);

        $reinicio = consumir_limite_diario($redis, 9, 100);

        // O incremento recria a chave devolvendo 1, então o TTL é reaplicado:
        // nenhuma chave de contagem fica sem expiração.
        $this->assertSame(1, $reinicio['contagem_atual']);
        $this->assertSame(86400, $redis->expiracaoDe($chave));
        $this->assertCount(
            2,
            array_filter(
                $redis->operacoes(),
                static fn (string $operacao): bool => str_starts_with($operacao, 'EXPIRE ')
            )
        );
    }

    public function testCotaDoChatPrivadoLibera100RequisicoesEBloqueiaA101(): void
    {
        // Mesmo limite que comandos/chatgpt.php aplica em chat privado.
        $redis = new Support\RedisFalsoComLua();
        $chave = $this->chaveDeHoje(10);

        for ($requisicao = 1; $requisicao <= 100; $requisicao++) {
            $info = consumir_limite_diario($redis, 10, 100);
            $this->assertTrue($info['permitida'], "A requisição {$requisicao} não deveria ser bloqueada.");
            $this->assertSame($requisicao, $info['contagem_atual']);
        }

        $bloqueio = consumir_limite_diario($redis, 10, 100);

        $this->assertFalse($bloqueio['permitida']);
        $this->assertTrue($bloqueio['atingiu_limite']);
        $this->assertSame(100, $bloqueio['contagem_atual']);
        $this->assertSame(100, $bloqueio['limite_maximo']);
        // O consumo recusado não altera o contador; só os 100 aceitos incrementam.
        $operacoes = $redis->operacoes();
        $this->assertCount(202, $operacoes);
        $this->assertCount(
            100,
            array_filter($operacoes, static fn (string $op): bool => $op === 'INCRBY ' . $chave . ' 1')
        );
        $this->assertCount(
            1,
            array_filter($operacoes, static fn (string $op): bool => $op === 'EXPIRE ' . $chave . ' 86400')
        );
    }

    public function testConsumosConcorrentesNaoLiberamMaisQueACota(): void
    {
        // Cinco processos do bot atendendo o MESMO usuário ao mesmo tempo
        // (webhook concorrente): como a decisão inteira acontece dentro de um
        // único script — o Redis executa Lua de forma atômica —, não existe
        // janela entre "ler a contagem" e "gravar a contagem" para dois
        // processos lerem o mesmo valor e passarem juntos.
        $redis = new Support\RedisFalsoComLua();
        $cota = 3;

        $permitidas = 0;
        $contagens = [];
        for ($processo = 1; $processo <= 5; $processo++) {
            $info = consumir_limite_diario($redis, 17, $cota);
            $contagens[] = $info['contagem_atual'];
            if ($info['permitida']) {
                $permitidas++;
            }
        }

        // Exatamente $cota liberações — nem uma a mais, mesmo com todos
        // chegando "juntos". Recusas devolvem a contagem vigente sem alterá-la.
        $this->assertSame($cota, $permitidas);
        $this->assertSame([1, 2, 3, 3, 3], $contagens);
        $this->assertSame('3', $redis->valorDe($this->chaveDeHoje(17)));
        // E o TTL foi aplicado uma única vez, na criação: nenhuma das
        // chamadas concorrentes pode deixar a chave órfã (sem expiração), o
        // que baniria o usuário até alguém apagar a chave à mão.
        $this->assertSame(86400, $redis->expiracaoDe($this->chaveDeHoje(17)));
        $this->assertCount(
            1,
            array_filter(
                $redis->operacoes(),
                static fn (string $operacao): bool => str_starts_with($operacao, 'EXPIRE ')
            )
        );
    }

    public function testCotasDeUsuariosDiferentesSaoIndependentes(): void
    {
        $redis = new Support\RedisFalsoComLua();

        consumir_limite_diario($redis, 11, 100);
        consumir_limite_diario($redis, 11, 100);
        $outro = consumir_limite_diario($redis, 12, 100);

        $this->assertSame(1, $outro['contagem_atual']);
        $this->assertSame('2', $redis->valorDe($this->chaveDeHoje(11)));
        $this->assertSame('1', $redis->valorDe($this->chaveDeHoje(12)));
    }

    public function testContadorDeOutroDiaNaoEhReaproveitado(): void
    {
        $redis = new Support\RedisFalsoComLua();
        $redis->semear('limite_diario:13:19990101', '999');

        $info = consumir_limite_diario($redis, 13, 100);

        $this->assertSame(1, $info['contagem_atual']);
        $this->assertSame('999', $redis->valorDe('limite_diario:13:19990101'));
    }

    public function testCotaZeroBloqueiaJaAPrimeiraRequisicao(): void
    {
        // Borda inferior: com cota 0 nem a primeira chamada passa e a recusa
        // não cria contador sem utilidade.
        $redis = new Support\RedisFalsoComLua();

        $info = consumir_limite_diario($redis, 18, 0);

        $this->assertFalse($info['permitida']);
        $this->assertTrue($info['atingiu_limite']);
        $this->assertSame(0, $info['contagem_atual']);
        $this->assertNull($redis->valorDe($this->chaveDeHoje(18)));
    }

    /**
     * @return array<string, array{int, int, bool}>
     */
    public static function fronteiraDaCotaProvider(): array
    {
        return [
            'penúltima consumida (N-1)' => [5, 4, true],
            'última consumida (N)' => [5, 5, true],
            'a seguinte (N+1)' => [5, 6, false],
        ];
    }

    /**
     * Fronteira exata `contagem <= limite`, um consumo por vez até a posição
     * pedida — com o Lua real fazendo a contagem.
     */
    #[DataProvider('fronteiraDaCotaProvider')]
    public function testFronteiraExataDaCotaDiaria(int $cota, int $consumos, bool $permitida): void
    {
        $redis = new Support\RedisFalsoComLua();

        $info = ['permitida' => null, 'contagem_atual' => null];
        for ($i = 0; $i < $consumos; $i++) {
            $info = consumir_limite_diario($redis, 19, $cota);
        }

        $this->assertSame($permitida, $info['permitida']);
        $this->assertSame(min($cota, $consumos), $info['contagem_atual']);
    }

    // ---------------------------------------------------------------------
    // Janela diária e fuso horário
    // ---------------------------------------------------------------------

    /**
     * Regressão: a janela da cota era `date('Ymd')`, avaliada no fuso AMBIENTE
     * do processo. Nenhum ponto do bot define o fuso antes do portão — bot.php,
     * bot_web.php e o roteador não chamam date_default_timezone_set(), e os
     * comandos de IA só o fazem DEPOIS de consumir a cota. Com o php.ini padrão
     * (UTC), a "cota diária" era o dia UTC, zerando às 21h de São Paulo; e no
     * polling a primeira mensagem do processo caía num balde e as seguintes em
     * outro, porque aí o fuso já tinha sido trocado pelo comando anterior.
     *
     * Hoje limite_diario_dia_atual() calcula a data em America/Sao_Paulo, então
     * o fuso do processo não muda mais o balde: a cota estourada continua
     * estourada depois de trocar o fuso.
     *
     * Os dois fusos usados são escolhidos em tempo de execução, e não fixos: um
     * par fixo divergiria de São Paulo só em parte do dia, e a asserção passaria
     * a depender da hora real. Ver fusosDivergentesDeSaoPaulo().
     */
    public function testJanelaDiariaIgnoraOFusoDoProcessoEUsaODeSaoPaulo(): void
    {
        $fusoOriginal = date_default_timezone_get();
        [$primeiroFuso, $segundoFuso] = self::fusosDivergentesDeSaoPaulo();

        try {
            date_default_timezone_set($primeiroFuso);
            $redis = new Support\RedisFalsoComLua();
            $chaveSaoPaulo = 'limite_diario:20:' . limite_diario_dia_atual();

            // O fuso do processo está num dia diferente do de São Paulo: um
            // limite_diario_dia_atual() que voltasse a usar date('Ymd')
            // reprovaria aqui — e reprovaria a qualquer hora, porque o fuso foi
            // escolhido justamente por divergir agora.
            $this->assertNotSame(date('Ymd'), limite_diario_dia_atual());

            for ($i = 0; $i < 3; $i++) {
                consumir_limite_diario($redis, 20, 3);
            }
            $estourou = consumir_limite_diario($redis, 20, 3);
            $this->assertFalse($estourou['permitida'], 'A cota precisa estourar no primeiro fuso.');

            date_default_timezone_set($segundoFuso);

            // O fuso do processo mudou de verdade; a janela da cota, não.
            $this->assertSame($segundoFuso, date_default_timezone_get());
            $this->assertNotSame(date('Ymd'), limite_diario_dia_atual());
            $this->assertSame($chaveSaoPaulo, 'limite_diario:20:' . limite_diario_dia_atual());

            // Mesmo usuário, mesmo instante, outro fuso: a cota SEGUE estourada.
            $depois = consumir_limite_diario($redis, 20, 3);
            $this->assertFalse($depois['permitida'], 'Trocar o fuso não pode devolver cota.');
            $this->assertSame(3, $depois['contagem_atual']);
            $this->assertSame('3', $redis->valorDe($chaveSaoPaulo));
        } finally {
            date_default_timezone_set($fusoOriginal);
        }
    }

    /** Fusos extremos, dos mais adiantados aos mais atrasados, sem DST. */
    private const FUSOS_CANDIDATOS = [
        'Pacific/Kiritimati', // UTC+14
        'Etc/GMT-13',         // UTC+13
        'Etc/GMT-12',         // UTC+12
        'Etc/GMT+10',         // UTC-10
        'Pacific/Midway',     // UTC-11
        'Etc/GMT+12',         // UTC-12
    ];

    /**
     * Dois fusos DISTINTOS cuja data local difere da de São Paulo NESTE instante.
     *
     * Um par fixo não serve: cada fuso compartilha a data com São Paulo em parte
     * do dia. Chamar isso de "sempre diferente" foi o que fez este teste passar
     * só entre 00h e 08h de São Paulo e reprovar no resto do dia.
     *
     * Quem diverge depende da hora: perto da meia-noite de São Paulo, só os
     * fusos ATRASADOS estão em outro dia; no fim do dia, só os ADIANTADOS. Por
     * isso a lista cobre as duas pontas — na hora mais apertada (~06h-09h de
     * São Paulo) ainda restam três candidatos divergentes.
     *
     * @return array{string, string}
     */
    private static function fusosDivergentesDeSaoPaulo(): array
    {
        $agora = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $emSaoPaulo = $agora->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Ymd');

        $divergentes = [];
        foreach (self::FUSOS_CANDIDATOS as $fuso) {
            if ($agora->setTimezone(new \DateTimeZone($fuso))->format('Ymd') !== $emSaoPaulo) {
                $divergentes[] = $fuso;
            }
        }

        if (count($divergentes) < 2) {
            throw new RuntimeException(sprintf(
                'Esperados >= 2 fusos com data diferente de America/Sao_Paulo (%s); achados: %s.',
                $emSaoPaulo,
                $divergentes === [] ? 'nenhum' : implode(', ', $divergentes)
            ));
        }

        return [$divergentes[0], $divergentes[1]];
    }

    public function testAJanelaDaCotaNaoDependeDaOrdemDoFusoNoComando(): void
    {
        // O comando continua definindo o fuso DEPOIS do portão de cota — o que
        // deixou de importar, porque a janela é calculada em São Paulo dentro de
        // limite_diario_dia_atual(). Este teste trava as duas pontas: a ordem
        // que existe hoje e a independência que a torna inofensiva.
        $fonte = Support\FonteDoComando::ler(__DIR__ . '/../../comandos/chatgpt.php');

        $posicaoCota = strpos($fonte, '$info_limite = consumir_limite_diario(');
        $posicaoFuso = strpos($fonte, "date_default_timezone_set('America/Sao_Paulo')");
        $this->assertIsInt($posicaoCota, 'O comando precisa manter o portão de cota.');
        $this->assertIsInt($posicaoFuso, 'O comando precisa manter a definição de fuso.');
        $this->assertGreaterThan($posicaoCota, $posicaoFuso);

        // A independência: nenhuma das funções de cota pode voltar a usar o
        // relógio ambiente para montar a chave.
        $limites = file_get_contents(__DIR__ . '/../../inclusos/limites_requisicoes.php');
        $this->assertIsString($limites);
        $this->assertSame(
            0,
            substr_count($limites, '$hoje = date('),
            'a janela da cota voltou a depender do fuso ambiente do processo'
        );
        $this->assertStringContainsString("new DateTimeZone('America/Sao_Paulo')", $limites);

        // E nenhum outro ponto do caminho de boot define o fuso antes disso.
        foreach (['bot.php', 'bot_web.php', 'roteador.php'] as $arquivo) {
            $conteudo = file_get_contents(__DIR__ . '/../../' . $arquivo);
            $this->assertIsString($conteudo, "{$arquivo} precisa ser legível.");
            $this->assertStringNotContainsString('date_default_timezone_set', $conteudo);
        }
    }

    // ---------------------------------------------------------------------
    // Redis indisponível
    // ---------------------------------------------------------------------

    public function testRedisIndisponivelFalhaFechadoENaoLiberaChamadaPaga(): void
    {
        // phpredis sem exceções devolve false: sem contagem confiável, a cota
        // bloqueia (o comando não chega a chamar a OpenAI).
        $redis = new Support\RedisFalsoComLua();
        $redis->ficarIndisponivel();

        $info = consumir_limite_diario($redis, 14, 100);

        $this->assertFalse($info['permitida']);
        $this->assertTrue($info['atingiu_limite']);
        $this->assertSame(101, $info['contagem_atual']);
        $this->assertNull($redis->valorDe($this->chaveDeHoje(14)));
    }

    public function testRedisIndisponivelComExcecaoInterrompeAntesDaChamadaPaga(): void
    {
        // Modo padrão do phpredis: a exceção sobe e o comando morre antes de
        // montar qualquer requisição para a OpenAI.
        $redis = new Support\RedisFalsoComLua();
        $redis->ficarIndisponivelComExcecao();

        $this->expectException(RedisException::class);

        consumir_limite_diario($redis, 15, 100);
    }

    // ---------------------------------------------------------------------
    // Garantias do dublê (sem elas, os testes acima não provariam nada)
    // ---------------------------------------------------------------------

    public function testDubleExecutaOScriptRecebidoEUmaMutacaoNoRetornoMudaOResultado(): void
    {
        $redis = new Support\RedisFalsoComLua();
        consumir_limite_diario($redis, 16, 100);

        $script = $redis->ultimoScript();
        $this->assertIsString($script, 'A cota precisa ser decidida por um script Lua.');

        // O script real devolve a contagem do INCR...
        $real = new Support\RedisFalsoComLua();
        $this->assertSame(1, $real->eval($script, ['chave', 86400, 1, 100], 1));
        $this->assertSame(2, $real->eval($script, ['chave', 86400, 1, 100], 1));

        // ...enquanto a mutação clássica (retorno fixo) devolve outra coisa,
        // ainda que continue contando. É por isso que os testes de contagem
        // acima quebram se o corpo do Lua mudar.
        $mutado = str_replace('return novo', 'return 0', $script);
        $this->assertNotSame($script, $mutado, 'O script precisa devolver a contagem apurada.');

        $comMutacao = new Support\RedisFalsoComLua();
        $this->assertSame(0, $comMutacao->eval($mutado, ['chave', 86400, 1, 100], 1));
        $this->assertSame('1', $comMutacao->valorDe('chave'));
    }

    public function testConsumoPonderadoEhReservadoInteiroOuNaoEhConsumido(): void
    {
        $redis = new Support\RedisFalsoComLua();

        $this->assertSame(98, consumir_limite_diario($redis, 21, 100, 98)['contagem_atual']);
        $audio = consumir_limite_diario($redis, 21, 100, 2);
        $this->assertTrue($audio['permitida']);
        $this->assertSame(100, $audio['contagem_atual']);

        $recusado = consumir_limite_diario($redis, 21, 100, 2);
        $this->assertFalse($recusado['permitida']);
        $this->assertSame(100, $recusado['contagem_atual']);
        $this->assertSame('100', $redis->valorDe($this->chaveDeHoje(21)));
    }

    public function testDubleRecusaConstrucaoLuaQueNaoSabeExecutar(): void
    {
        $redis = new Support\RedisFalsoComLua();

        $this->expectException(RuntimeException::class);

        $redis->eval("for i = 1, 10 do redis.call('INCR', KEYS[1]) end return 1", ['chave'], 1);
    }

    public function testDubleRecusaComandoRedisDesconhecido(): void
    {
        $redis = new Support\RedisFalsoComLua();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Comando Redis não suportado pelo dublê: LPUSH.');

        $redis->eval("return redis.call('LPUSH', KEYS[1], 'x')", ['chave'], 1);
    }

    public function testDubleRespeitaAsRegrasDeVerdadeEComparacaoDoLua(): void
    {
        $redis = new Support\RedisFalsoComLua();

        // No Lua, 0 é verdadeiro e "1" (string) não é igual a 1 (número).
        $this->assertSame(1, $redis->eval('if 0 then return 1 end return 2', [], 0));
        $this->assertSame(2, $redis->eval("if ARGV[1] == 1 then return 1 end return 2", ['1'], 0));
        $this->assertSame(1, $redis->eval('if tonumber(ARGV[1]) == 1 then return 1 end return 2', ['1'], 0));
        $this->assertSame('a1', $redis->eval("return 'a' .. 1", [], 0));
    }
}
