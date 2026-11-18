<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Support\ContextoDeExecucao;
use App\Support\LoggerFactory;
use App\Support\ProcessadorDeRedacao;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppLogReader;

/**
 * O contrato de observabilidade da frota, exercitado pelo CAMINHO REAL de log.
 *
 * Nada aqui inspeciona o formatador em isolamento de propósito. O contrato fala
 * do que chega ao ARQUIVO, e é entre o call site e o arquivo que moram as
 * frestas que interessam: um handler sem formatador, um processor que alguém
 * removeu, um canal criado por um caminho diferente. Testar a peça isolada
 * continuaria verde com o arquivo errado.
 *
 * Tudo escreve com `forcar: true` em vez de `define('LOGS_ATIVO', true)`:
 * `define()` não se desfaz, a ordem da suíte é sorteada, e o parâmetro já é o
 * caminho que a produção usa para o evento que precisa aparecer mesmo com o log
 * desligado.
 */
final class ContratoDeObservabilidadeTest extends TestCase
{
    /**
     * A lista da seção 2 do contrato, escrita à mão AQUI de propósito.
     *
     * Ler `ProcessadorDeRedacao::CHAVES_PROIBIDAS` faria o teste encolher junto
     * com a lista: apagar uma chave do código apagaria o caso que a cobre, e o
     * portão continuaria verde. Copiada, ela é a cópia independente que o
     * contrato exige.
     *
     * @var array<string, string>
     */
    private const SEGREDOS = [
        'password' => 'teste',
        'senha' => 'minha-senha-secreta',
        'token' => 'tok_vazamento_1',
        'authorization' => 'Bearer vazamento_2',
        'cookie' => 'sessionid=vazamento_3',
        'session' => 'sess_vazamento_4',
        'secret' => 'shhh_vazamento_5',
        'key' => 'k_vazamento_6',
        'apiKey' => 'ak_vazamento_7',
        'jwt' => 'eyJvazamento8',
        'X-Auth-Token' => 'xat_vazamento_9',
        'credential' => 'cred_vazamento_10',
        'cpf' => '12345678909',
        'cnpj' => '11222333000181',
        'card' => '4111111111111111',
        'cvv' => '737-vazamento-11',
        'pix' => 'chave-pix-vazamento',
    ];

    protected function setUp(): void
    {
        LoggerFactory::reset();
        ContextoDeExecucao::reset();
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        ContextoDeExecucao::reset();
    }

    /** Seção 1: os campos obrigatórios, com os nomes do contrato. */
    public function testEventoTemOsCamposObrigatoriosDoContrato(): void
    {
        $antes = AppLogReader::tamanhoAtual();
        LoggerFactory::channel('contrato_formato', true)->warning('Mensagem curta');
        $evento = $this->ultimoEvento(AppLogReader::conteudoDesde($antes));

        // ISO 8601 em UTC COM milissegundos e sufixo Z: sem milissegundos duas
        // linhas do mesmo segundo perdem a ordem, e sem UTC a virada do horário
        // de verão vira aritmética no meio do incidente.
        $this->assertMatchesRegularExpression(
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/',
            is_string($evento['ts'] ?? null) ? $evento['ts'] : ''
        );
        $this->assertSame('warn', $evento['level'] ?? null);
        $this->assertSame('Mensagem curta', $evento['msg'] ?? null);
        $this->assertSame('dealnibot', $evento['service'] ?? null);
        $this->assertSame('test', $evento['env'] ?? null);
        $this->assertIsString($evento['version'] ?? null);
        $this->assertNotSame('', $evento['version']);

        // O canal do Monolog continua no evento: é por ele que todo runbook
        // deste bot filtra, e o contrato permite campo extra em snake_case.
        $this->assertSame('contrato_formato', $evento['channel'] ?? null);

        // Os nomes do Monolog não podem sobreviver ao lado dos do contrato: dois
        // campos com o mesmo sentido é a origem de todo filtro que mede metade.
        foreach (['datetime', 'level_name', 'message'] as $herdado) {
            $this->assertArrayNotHasKey($herdado, $evento);
        }
    }

    /** Os oito níveis do PSR-3 nos cinco degraus do contrato. */
    public function testNiveisDoPsr3CaemNosCincoDegrausDoContrato(): void
    {
        $antes = AppLogReader::tamanhoAtual();

        $logger = LoggerFactory::channel('contrato_niveis', true);
        $logger->debug('d');
        $logger->notice('n');
        $logger->warning('w');
        $logger->error('e');
        $logger->critical('c');
        $logger->alert('a');
        $logger->emergency('x');

        $niveis = array_map(
            static fn (array $linha): mixed => $linha['level'] ?? null,
            $this->linhasDoCanal($antes, 'contrato_niveis')
        );

        $this->assertSame(['debug', 'info', 'warn', 'error', 'fatal', 'fatal', 'fatal'], $niveis);
    }

    public function testLoggerAceitaNivelGenericoDoPsr3ERejeitaNivelDesconhecido(): void
    {
        $antes = AppLogReader::tamanhoAtual();
        $logger = LoggerFactory::channel('contrato_nivel_generico', true);

        $logger->log(\Psr\Log\LogLevel::INFO, 'nivel generico');

        $linhas = $this->linhasDoCanal($antes, 'contrato_nivel_generico');
        $this->assertCount(1, $linhas);
        $this->assertSame('info', $linhas[0]['level'] ?? null);
        $this->assertSame('nivel generico', $linhas[0]['msg'] ?? null);

        $this->expectException(\Psr\Log\InvalidArgumentException::class);
        $logger->log('nivel-inexistente', 'nao deve ser gravado');
    }

    public function testContextoJsonSerializableQueFalhaNaoDerrubaOLog(): void
    {
        $valor = new class () implements \JsonSerializable {
            public string $interno = 'este estado nao pode acompanhar o evento';

            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('falha deliberada de serializacao');
            }
        };

        $antes = AppLogReader::tamanhoAtual();
        LoggerFactory::channel('contrato_contexto_arbitrario', true)->info(
            'contexto leniente',
            ['valor' => $valor]
        );

        $evento = $this->ultimoEvento(AppLogReader::conteudoDesde($antes));
        $contexto = $evento['context'] ?? null;
        $this->assertIsArray($contexto);

        // O processor de redação roda ANTES do formatador: um jsonSerialize()
        // que lança vira só o nome da classe, e é esse marcador que chega ao
        // arquivo. O que importa no contrato é que a linha continue sendo
        // gravada e que nenhum estado interno do objeto acompanhe o evento.
        $this->assertSame('contexto leniente', $evento['msg'] ?? null);
        $this->assertSame($valor::class, $contexto['valor'] ?? null);
        $this->assertStringNotContainsString(
            'este estado nao pode acompanhar o evento',
            json_encode($evento, JSON_THROW_ON_ERROR),
        );
    }

    /** Um objeto por linha — nunca um array por arquivo, nunca pretty-print. */
    public function testCadaEventoOcupaExatamenteUmaLinha(): void
    {
        $antes = AppLogReader::tamanhoAtual();
        $logger = LoggerFactory::channel('contrato_formato', true);
        $logger->info('primeiro');
        $logger->info('segundo', ['texto' => "com\nquebra"]);

        $acrescentado = AppLogReader::conteudoDesde($antes);
        $this->assertSame(2, substr_count($acrescentado, "\n"));
        $this->assertCount(2, AppLogReader::linhasJson($acrescentado));
        $this->assertStringStartsWith('{', $acrescentado);
    }

    /** Seção 3: o id do ciclo entra em TODO evento, sem ninguém passá-lo. */
    public function testTodoEventoDoCicloCarregaOMesmoRequestId(): void
    {
        $antes = AppLogReader::tamanhoAtual();

        escreverLog('primeiro evento do ciclo', 'contrato_ciclo', true);
        escreverLog('segundo evento do ciclo', 'contrato_ciclo', true);
        LoggerFactory::channel('roteador_erros', true)->info('evento de outro canal');

        $linhas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($antes));
        $ids = [];
        foreach ($linhas as $linha) {
            $id = $linha['request_id'] ?? null;
            $this->assertIsString($id, 'evento sem request_id');
            $ids[$id] = true;
        }
        $ids = array_keys($ids);

        // Um id por CICLO, não por evento nem por canal: correlacionar é
        // justamente conseguir puxar tudo que aconteceu na mesma requisição.
        $this->assertCount(1, $ids);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{8,64}\z/', $ids[0]);
        $this->assertSame(ContextoDeExecucao::requestId(), $ids[0]);
    }

    /**
     * A REGRA EXECUTÁVEL da seção 2, com as DUAS superfícies que este bot
     * produz — e a segunda é o ganho real da variante canônica.
     *
     * 1. as CHAVES de `context`/`extra`, recursivamente;
     * 2. **o TEXTO da mensagem.** Este repositório loga string já montada:
     *    `escreverLog()` recebe a frase pronta e `registrarFalhaDoRoteador()`
     *    interpola classe, mensagem, arquivo e linha na própria `msg`. A
     *    redação anterior só olhava `context`, o que a fazia passar verde sobre
     *    o caminho real de log deste bot sem proteger uma linha sequer.
     *
     * A asserção é sobre o TEXTO CRU acrescentado, não sobre o array já
     * decodificado: o valor voltaria por qualquer caminho novo — um campo
     * herdado, uma exceção normalizada, uma interpolação na mensagem — e só o
     * texto cru pega os três.
     */
    public function testNenhumValorDeChaveProibidaSaiEmClaroNoAppLog(): void
    {
        $antes = AppLogReader::tamanhoAtual();

        $contexto = self::SEGREDOS;
        $contexto['aninhado'] = ['headers' => self::SEGREDOS, 'query' => self::SEGREDOS];
        $contexto['err'] = ['type' => 'RuntimeException', 'context' => self::SEGREDOS];

        LoggerFactory::channel('geral', true)->error('falha com contexto sensível', $contexto);

        // O idioma do escreverLog(): tudo interpolado numa string só.
        $pares = [];
        foreach (self::SEGREDOS as $chave => $valor) {
            $pares[] = "{$chave}={$valor}";
        }
        escreverLog('Falha do roteador | ' . implode(' | ', $pares), 'roteador_erros', true);
        escreverLog('Falha OpenAI | resposta=' . json_encode(self::SEGREDOS), 'erros_openai', true);

        $saida = AppLogReader::conteudoDesde($antes);
        $this->assertNotSame('', $saida);

        foreach (self::SEGREDOS as $chave => $valor) {
            $this->assertStringNotContainsString(
                $valor,
                $saida,
                "o valor da chave `{$chave}` saiu em claro no app.log"
            );
            // A chave sobrevive de propósito: saber QUE havia um token ali é
            // diagnóstico; saber QUAL era é vazamento.
            $this->assertStringContainsString($chave, $saida);
        }

        $this->assertStringContainsString(ProcessadorDeRedacao::SUBSTITUTO, $saida);
    }

    /**
     * A redação do TEXTO, isolada e nomeada: é ela que a implementação anterior
     * deste repositório não fazia.
     */
    public function testORedatorAlcancaOTextoDaMensagemENaoSoOContexto(): void
    {
        $antes = AppLogReader::tamanhoAtual();

        escreverLog(
            'Falha da Bot API | chat_id=42 | authorization=Bearer segredo-na-mensagem',
            'contrato_texto',
            true
        );

        $evento = $this->ultimoEvento(AppLogReader::conteudoDesde($antes));
        $msg = $evento['msg'] ?? null;
        $this->assertIsString($msg);
        $this->assertStringNotContainsString('segredo-na-mensagem', $msg);
        $this->assertStringContainsString('authorization=' . ProcessadorDeRedacao::SUBSTITUTO, $msg);
        // O que não é segredo continua legível: uma redação que apagasse a
        // linha inteira tiraria do log a evidência que o plantão usa.
        $this->assertStringContainsString('chat_id=42', $msg);
    }

    /**
     * Uma lista de NOMES de campo não é redigida: o índice é numérico, e o que
     * o contrato protege é o valor de um campo nomeado. Sem esta garantia, o
     * log da Bot API — que registra os nomes dos campos enviados, justamente
     * para não registrar os valores — viraria uma fileira de `[REDACTED]`.
     */
    public function testListaDeNomesDeCampoNaoEhRedigida(): void
    {
        $antes = AppLogReader::tamanhoAtual();
        LoggerFactory::channel('contrato_redacao', true)->error('Falha da Bot API', [
            'campos' => ['chat_id', 'text', 'reply_parameters'],
        ]);

        $registrado = $this->contextoDoEvento($this->ultimoEvento(AppLogReader::conteudoDesde($antes)));
        $this->assertSame(['chat_id', 'text', 'reply_parameters'], $registrado['campos'] ?? null);
    }

    /**
     * @param array<string, mixed> $evento
     * @return array<string, mixed>
     */
    private function contextoDoEvento(array $evento): array
    {
        $contexto = $evento['context'] ?? null;
        $this->assertIsArray($contexto, 'o evento precisa ter carregado o contexto.');

        $registrado = [];
        foreach ($contexto as $chave => $valor) {
            $this->assertIsString($chave);
            $registrado[$chave] = $valor;
        }

        return $registrado;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linhasDoCanal(int $antes, string $canal): array
    {
        return array_values(array_filter(
            AppLogReader::linhasJson(AppLogReader::conteudoDesde($antes)),
            static fn (array $linha): bool => ($linha['channel'] ?? null) === $canal
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function ultimoEvento(string $trecho): array
    {
        $linhas = AppLogReader::linhasJson($trecho);
        $this->assertNotSame([], $linhas, 'nenhum evento foi acrescentado ao app.log.');

        return $linhas[count($linhas) - 1];
    }
}
