<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * Testes de verificar_limite_diario() e incrementar_contador_requisicoes()
 * (inclusos/limites_requisicoes.php).
 *
 * As funções recebem a instância do Redis por PARÂMETRO, então usamos um
 * fake de Redis em memória (mock tipado como Redis) que implementa apenas os
 * métodos usados por elas: exists/set/expire/get/incr. O estado fica em
 * propriedades do teste ($store, $expiracoes, $chamadasSet, $chamadasExpire,
 * $sequencia), disponíveis para inspeção e pré-população. Nenhum Redis real
 * é tocado.
 *
 * consumir_limite_diario() — o portão que os comandos de IA usam de verdade —
 * fica FORA daqui de propósito: a decisão dela está dentro de um script Lua, e
 * um fake que imitasse o que o Lua deveria fazer continuaria verde mesmo com o
 * script quebrado. Ela é testada em LimiteDiarioLuaTest, cujo dublê EXECUTA o
 * Lua recebido.
 *
 * Contrato atômico travado por estes testes: a chave diária só é criada por
 * incr() (que já a inicia em 1) e o TTL é aplicado exatamente quando incr()
 * devolve 1 — nunca existe chave de contagem sem expiração.
 */
final class LimitesRequisicoesTest extends TestCase
{
    /** @var array<string, mixed> Valores por chave do Redis fake. */
    private $store = [];

    /** @var array<string, int> Expiração (em segundos) definida por chave. */
    private $expiracoes = [];

    /** @var int Contagem de chamadas a set(). */
    private $chamadasSet = 0;

    /** @var int Contagem de chamadas a expire(). */
    private $chamadasExpire = 0;

    /** @var array<int, string> Sequência de operações de escrita/leitura no Redis fake. */
    private $sequencia = [];

    /** @var bool Quando true, incr() do fake devolve false (falha do Redis). */
    private $falhaIncr = false;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../inclusos/limites_requisicoes.php';
    }

    /** @param mixed $value */
    private function redisKey($value): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \InvalidArgumentException('Chave Redis inválida no teste.');
        }

        return (string) $value;
    }

    /**
     * Cria um fake de Redis em memória com os métodos usados pelas funções.
     * O dublê é tipado como Redis (o que as funções de produção esperam) e o
     * estado fica nas propriedades do teste, que os testes podem inspecionar
     * e pré-popular.
     *
     * É um STUB, não um mock: aqui ninguém verifica chamada com expects() — a
     * verificação é feita sobre $this->sequencia e $this->store. O PHPUnit 11+
     * emite notice para mock sem expectativa justamente para separar os dois
     * casos, e createStub() é a construção certa para este.
     */
    private function fakeRedis(): Redis
    {
        $redis = $this->createStub(Redis::class);

        $redis->method('exists')->willReturnCallback(function ($chave) {
            $this->sequencia[] = 'exists:' . $this->redisKey($chave);

            return array_key_exists($this->redisKey($chave), $this->store) ? 1 : 0;
        });

        $redis->method('set')->willReturnCallback(function ($chave, $valor) {
            $this->store[$this->redisKey($chave)] = $valor;
            $this->chamadasSet++;
            $this->sequencia[] = 'set:' . $this->redisKey($chave);

            return true;
        });

        $redis->method('expire')->willReturnCallback(function ($chave, $segundos) {
            if (!is_int($segundos)) {
                throw new \InvalidArgumentException('Expiração Redis inválida no teste.');
            }
            $this->expiracoes[$this->redisKey($chave)] = $segundos;
            $this->chamadasExpire++;
            $this->sequencia[] = 'expire:' . $this->redisKey($chave);

            return true;
        });

        $redis->method('get')->willReturnCallback(function ($chave) {
            $chave = $this->redisKey($chave);
            $this->sequencia[] = 'get:' . $chave;

            return array_key_exists($chave, $this->store) ? $this->store[$chave] : false;
        });

        $redis->method('incr')->willReturnCallback(function ($chave) {
            $chave = $this->redisKey($chave);
            if ($this->falhaIncr) {
                $this->sequencia[] = 'incr-falha:' . $chave;

                return false;
            }
            $bruto = $this->store[$chave] ?? 0;
            // O store é mixed; só soma sobre valor numérico (o Redis real
            // guarda strings numéricas), o resto reinicia do zero.
            $atual = is_numeric($bruto) ? (int) $bruto : 0;
            $atual++;
            $this->store[$chave] = $atual;
            $this->sequencia[] = 'incr:' . $chave;

            return $atual;
        });

        return $redis;
    }

    public function testVerificarLimiteNaoCriaChaveInexistenteETrataComoZero(): void
    {
        $redis = $this->fakeRedis();
        $hoje = limite_diario_dia_atual();
        $chave = "limite_diario:123:{$hoje}";

        $resultado = verificar_limite_diario($redis, 123);

        // A verificação é somente leitura: a chave NÃO é criada aqui (quem a
        // cria, já com TTL, é o incr() de incrementar_contador_requisicoes).
        // O padrão antigo exists() → set(0) → expire() podia recriar a chave
        // sem TTL sob concorrência, vazando-a para sempre no Redis.
        $this->assertArrayNotHasKey($chave, $this->store);
        $this->assertArrayNotHasKey($chave, $this->expiracoes);
        $this->assertSame(0, $this->chamadasSet);
        $this->assertSame(0, $this->chamadasExpire);

        // Estado retornado: chave ausente equivale a contagem 0.
        $this->assertFalse($resultado['atingiu_limite']);
        $this->assertSame(0, $resultado['contagem_atual']);
        $this->assertSame(100, $resultado['limite_maximo']);
    }

    public function testVerificarLimiteNaoReinicializaChaveExistente(): void
    {
        $redis = $this->fakeRedis();
        $hoje = limite_diario_dia_atual();
        $chave = "limite_diario:55:{$hoje}";
        $this->store[$chave] = 42; // já existe

        $resultado = verificar_limite_diario($redis, 55);

        // A verificação nunca escreve: nenhum set/expire, com ou sem chave.
        $this->assertSame(0, $this->chamadasSet);
        $this->assertSame(0, $this->chamadasExpire);
        $this->assertSame(42, $resultado['contagem_atual']);
        $this->assertFalse($resultado['atingiu_limite']);
        // A verificação é somente leitura: o valor armazenado não muda.
        $this->assertSame(42, $this->store[$chave]);
    }

    public function testVerificarLimiteConverteStringNumericaDoRedisParaInt(): void
    {
        // O Redis real devolve strings; a função precisa castar para int.
        $redis = $this->fakeRedis();
        $hoje = limite_diario_dia_atual();
        $this->store["limite_diario:11:{$hoje}"] = '42';

        $resultado = verificar_limite_diario($redis, 11);

        $this->assertSame(42, $resultado['contagem_atual']);
        $this->assertFalse($resultado['atingiu_limite']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function providerValoresNaoNumericos(): array
    {
        return [
            'string lixo' => ['lixo'],
            'prefixo numérico malformado' => ['99requisições'],
            'string vazia' => [''],
            'false' => [false],
            'null' => [null],
        ];
    }

    /**
     * Valor não numérico no Redis (lixo, vazio, false) vira contagem 0,
     * conforme a branch is_numeric() de verificar_limite_diario.
     *
     * @param mixed $valorBruto
     */
    #[DataProvider('providerValoresNaoNumericos')]
    public function testVerificarLimiteTrataValorNaoNumericoComoZero($valorBruto): void
    {
        $redis = $this->fakeRedis();
        $hoje = limite_diario_dia_atual();
        $this->store["limite_diario:12:{$hoje}"] = $valorBruto;

        $resultado = verificar_limite_diario($redis, 12, 100);

        $this->assertSame(0, $resultado['contagem_atual']);
        $this->assertFalse($resultado['atingiu_limite']);
    }

    /** @return array<string, array{string, int, bool}> */
    public static function contagensTextuais(): array
    {
        return [
            'abaixo da cota' => ['99', 99, false],
            'na fronteira' => ['100', 100, true],
            'acima da cota' => ['101', 101, true],
        ];
    }

    #[DataProvider('contagensTextuais')]
    public function testStrictTypesPreservaContagemTextualDoRedis(string $bruto, int $esperado, bool $atingiu): void
    {
        $redis = $this->fakeRedis();
        $this->store['limite_diario:12:' . limite_diario_dia_atual()] = $bruto;

        $this->assertSame([
            'atingiu_limite' => $atingiu,
            'contagem_atual' => $esperado,
            'limite_maximo' => 100,
        ], verificar_limite_diario($redis, 12));
    }

    public function testVerificarLimiteZeroAtingeImediatamente(): void
    {
        // Com limite 0, até uma chave recém-criada (contagem 0) já atingiu.
        $redis = $this->fakeRedis();

        $resultado = verificar_limite_diario($redis, 13, 0);

        $this->assertTrue($resultado['atingiu_limite']);
        $this->assertSame(0, $resultado['contagem_atual']);
        $this->assertSame(0, $resultado['limite_maximo']);
    }

    /**
     * Fronteira da decisão `contagem >= limite`, um caso por lado e um em cima
     * dela — com um limite customizado para o último, que é como os comandos
     * chamariam a função com outra cota.
     *
     * @return array<string, array{int, int, bool}>
     */
    public static function providerFronteiraDoLimite(): array
    {
        return [
            'uma requisição antes do limite' => [99, 100, false],
            'exatamente no limite' => [100, 100, true],
            'acima do limite' => [150, 100, true],
            'no limite customizado' => [5, 5, true],
        ];
    }

    #[DataProvider('providerFronteiraDoLimite')]
    public function testVerificarLimiteDecidePelaFronteiraDaContagem(
        int $contagem,
        int $limite,
        bool $atingiu
    ): void {
        $redis = $this->fakeRedis();
        $hoje = limite_diario_dia_atual();
        $this->store["limite_diario:7:{$hoje}"] = $contagem;

        $resultado = verificar_limite_diario($redis, 7, $limite);

        $this->assertSame($atingiu, $resultado['atingiu_limite']);
        $this->assertSame($contagem, $resultado['contagem_atual']);
        $this->assertSame($limite, $resultado['limite_maximo']);
    }

    public function testContadoresDeUsuariosDiferentesSaoIndependentes(): void
    {
        $redis = $this->fakeRedis();
        $hoje = limite_diario_dia_atual();
        $this->store["limite_diario:300:{$hoje}"] = 100; // usuário 300 estourou

        $bloqueado = verificar_limite_diario($redis, 300, 100);
        $liberado = verificar_limite_diario($redis, 301, 100);

        $this->assertTrue($bloqueado['atingiu_limite']);
        $this->assertSame(100, $bloqueado['contagem_atual']);
        $this->assertFalse($liberado['atingiu_limite']);
        $this->assertSame(0, $liberado['contagem_atual']);
        // O contador do usuário 300 não foi tocado pela consulta do 301.
        $this->assertSame(100, $this->store["limite_diario:300:{$hoje}"]);
    }

    public function testChaveDeOutroDiaNaoInterfereNaJanelaDeHoje(): void
    {
        // Simula a "expiração" da janela: o contador de um dia anterior
        // continua no Redis, mas a chave de hoje (data no nome) começa em 0.
        $redis = $this->fakeRedis();
        $hoje = limite_diario_dia_atual();
        $chaveAntiga = 'limite_diario:77:19990101';
        $chaveHoje = "limite_diario:77:{$hoje}";
        $this->store[$chaveAntiga] = 999;
        $antesDaVerificacao = $this->store;

        $resultado = verificar_limite_diario($redis, 77, 100);

        $this->assertFalse($resultado['atingiu_limite']);
        $this->assertSame(0, $resultado['contagem_atual']);
        // A verificação é só leitura: nada no Redis muda — nem a chave de hoje
        // aparece, nem a antiga é tocada.
        $this->assertSame($antesDaVerificacao, $this->store);

        // O incremento também opera só na janela de hoje: a chave nova entra
        // com 1 e a antiga continua exatamente como estava.
        $this->assertSame(1, incrementar_contador_requisicoes($redis, 77));
        $this->assertSame(
            [$chaveAntiga => 999, $chaveHoje => 1],
            $this->store
        );
    }

    public function testIncrementarContadorCriaChaveNovaComTtlViaIncrPrimeiro(): void
    {
        $redis = $this->fakeRedis();
        $hoje = limite_diario_dia_atual();
        $chave = "limite_diario:200:{$hoje}";

        $novo = incrementar_contador_requisicoes($redis, 200);

        // Padrão atômico: incr() primeiro (cria a chave já com 1) e, como o
        // retorno foi 1 (chave nova), aplica o TTL de 24h em seguida. Nunca
        // usa set() e a chave jamais fica sem expiração.
        $this->assertSame(1, $novo);
        $this->assertSame(1, $this->store[$chave]);
        $this->assertSame(86400, $this->expiracoes[$chave]);
        $this->assertSame(0, $this->chamadasSet);
        $this->assertSame(1, $this->chamadasExpire);
        // Ordem exata das operações: incr e só então expire (nada de
        // exists/set/get antes, que abririam a janela de corrida).
        $this->assertSame(['incr:' . $chave, 'expire:' . $chave], $this->sequencia);
    }

    public function testIncrementarContadorSomaSobreValorExistente(): void
    {
        $redis = $this->fakeRedis();
        $hoje = limite_diario_dia_atual();
        $chave = "limite_diario:201:{$hoje}";
        $this->store[$chave] = 10;

        $novo = incrementar_contador_requisicoes($redis, 201);

        $this->assertSame(11, $novo);
        $this->assertSame(11, $this->store[$chave]);
        // Chave existente (incr devolve > 1): não é reinicializada nem ganha
        // nova expiração — o TTL original aplicado na criação é preservado.
        $this->assertSame(0, $this->chamadasSet);
        $this->assertSame(0, $this->chamadasExpire);
        $this->assertSame(['incr:' . $chave], $this->sequencia);
    }

    public function testIncrementarReaplicaTtlQuandoChaveExpirouEIncrRecria(): void
    {
        $redis = $this->fakeRedis();
        $hoje = limite_diario_dia_atual();
        $chave = "limite_diario:204:{$hoje}";

        // Primeira criação com TTL.
        $this->assertSame(1, incrementar_contador_requisicoes($redis, 204));
        $this->assertSame(1, $this->chamadasExpire);

        // Simula o TTL vencendo no meio do dia (o Redis removeu a chave).
        unset($this->store[$chave], $this->expiracoes[$chave]);

        // A recriação via incr devolve 1 de novo, então o TTL é reaplicado:
        // nem a chave recriada fica sem expiração.
        $this->assertSame(1, incrementar_contador_requisicoes($redis, 204));
        $this->assertSame(86400, $this->expiracoes[$chave]);
        $this->assertSame(2, $this->chamadasExpire);
        $this->assertSame(0, $this->chamadasSet);
    }

    public function testIncrementarDevolveZeroSemExpireQuandoIncrFalha(): void
    {
        // phpredis devolve false quando o incr falha: a função devolve 0
        // (nenhuma contagem confiável) e NÃO aplica expiração.
        $redis = $this->fakeRedis();
        $hoje = limite_diario_dia_atual();
        $chave = "limite_diario:205:{$hoje}";
        $this->falhaIncr = true;

        $this->assertSame(0, incrementar_contador_requisicoes($redis, 205));
        $this->assertSame(0, $this->chamadasExpire);
        $this->assertSame(0, $this->chamadasSet);
        $this->assertSame(['incr-falha:' . $chave], $this->sequencia);
    }

    public function testIncrementarContadorSomaSobreStringNumerica(): void
    {
        // O Redis real armazena strings; incr precisa continuar a contagem.
        $redis = $this->fakeRedis();
        $hoje = limite_diario_dia_atual();
        $chave = "limite_diario:202:{$hoje}";
        $this->store[$chave] = '7';

        $this->assertSame(8, incrementar_contador_requisicoes($redis, 202));
    }

    public function testIncrementosSequenciaisRetornamContagensCrescentes(): void
    {
        $redis = $this->fakeRedis();

        $this->assertSame(1, incrementar_contador_requisicoes($redis, 203));
        $this->assertSame(2, incrementar_contador_requisicoes($redis, 203));
        $this->assertSame(3, incrementar_contador_requisicoes($redis, 203));

        // Só o primeiro incremento (retorno 1, chave nova) define expiração;
        // set() nunca é chamado no padrão atômico.
        $this->assertSame(0, $this->chamadasSet);
        $this->assertSame(1, $this->chamadasExpire);
    }

    public function testFluxoCompletoVerificaIncrementaEAtingeLimite(): void
    {
        $redis = $this->fakeRedis();

        // Limite baixo para validar o cruzamento da fronteira.
        $limite = 3;
        $id = 42;

        for ($i = 0; $i < $limite; $i++) {
            $estado = verificar_limite_diario($redis, $id, $limite);
            $this->assertFalse($estado['atingiu_limite'], "Iteração {$i} não deveria ter atingido o limite");
            $this->assertSame($i, $estado['contagem_atual'], "Iteração {$i} deveria reportar contagem {$i}");
            $this->assertSame($i + 1, incrementar_contador_requisicoes($redis, $id));
        }

        // Após $limite incrementos, a verificação deve indicar limite atingido.
        $estadoFinal = verificar_limite_diario($redis, $id, $limite);
        $this->assertTrue($estadoFinal['atingiu_limite']);
        $this->assertSame($limite, $estadoFinal['contagem_atual']);
    }

    public function testOPortaoDeProducaoEhSomenteOConsumoAtomico(): void
    {
        // As duas funções exercitadas acima estão 100% cobertas, mas hoje são
        // CÓDIGO MORTO: nenhum arquivo de produção as chama — o portão real é
        // consumir_limite_diario(), atômico via Lua. Esta asserção impede que a
        // dupla verificar+incrementar (com a janela de corrida entre ler e
        // gravar) volte a ser usada por um comando sem que se perceba.
        $raiz = dirname(__DIR__, 2);
        $arquivos = ['bot.php', 'bot_web.php', 'roteador.php'];
        foreach (['imagem', 'audio', 'chatgpt'] as $rota) {
            $arquivos[] = \roteamento_arquivoDaRota($rota);
        }

        $chamamOConsumo = 0;
        foreach ($arquivos as $relativo) {
            // Comando + os funcoes_*.php irmãos: o portão de cota dos três
            // comandos de IA mora no irmão desde que o corpo saiu do script.
            $fonte = str_starts_with($relativo, 'comandos/')
                ? Support\FonteDoComando::ler($raiz . '/' . $relativo)
                : (string) file_get_contents($raiz . '/' . $relativo);
            $this->assertNotSame('', $fonte, "{$relativo} precisa ser legível.");

            $this->assertStringNotContainsString(
                'verificar_limite_diario(',
                $fonte,
                "{$relativo} voltou a usar a verificação não atômica da cota."
            );
            $this->assertStringNotContainsString(
                'incrementar_contador_requisicoes(',
                $fonte,
                "{$relativo} voltou a usar o incremento separado da verificação."
            );
            if (strpos($fonte, 'consumir_limite_diario(') !== false) {
                $chamamOConsumo++;
            }
        }

        // Os três comandos de IA (texto, áudio e imagem) e mais ninguém.
        $this->assertSame(3, $chamamOConsumo);
    }
}
