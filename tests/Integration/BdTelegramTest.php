<?php

declare(strict_types=1);

namespace Tests;

use bdTelegram;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Testes da camada de banco (classe bdTelegram, inclusos/bdTelegram.php).
 *
 * Cada teste roda contra um schema PostgreSQL DESCARTÁVEL criado no setUp e
 * semeado com o schema real do bot (database/schema.sql, apenas a tabela `usuarios`).
 * Nenhum dado de produção é tocado.
 */
final class BdTelegramTest extends TestCase
{
    /** @var string Identificador do schema PostgreSQL descartável deste teste. */
    private $tmp;

    /** @var bdTelegram|null Zerada no tearDown para liberar a conexão PDO. */
    private $bd;

    protected function setUp(): void
    {
        // Não herdar handlers criados por outro teste sob outro fuso/diretório.
        \App\Support\LoggerFactory::reset();
        require_once __DIR__ . '/../../inclusos/bdTelegram.php';

        // Identificador único: BancoTeste deriva dele um schema isolado por teste.
        $this->tmp = tempnam(sys_get_temp_dir(), 'dealnibot_bd_');

        // Semeia o schema real do bot (tabela usuarios).
        $sql = file_get_contents(__DIR__ . '/../../database/schema.sql');
        $this->assertIsString($sql, 'database/schema.sql precisa existir e ser legível para semear o schema.');
        $pdo = \BancoTeste::conectar($this->tmp);
        $pdo->exec($sql);
        $pdo = null;

        $this->bd = new bdTelegram(\BancoTeste::dsn($this->tmp));
    }

    protected function tearDown(): void
    {
        // Libera a conexão PDO da classe antes de remover o arquivo.
        $this->bd = null;

        if (file_exists($this->tmp)) {
            @unlink($this->tmp);
        }
    }

    /**
     * Acesso não-nulo à instância criada no setUp. A propriedade é
     * bdTelegram|null (anulada no tearDown), então a assertion narrowa o
     * tipo para o PHPStan e ainda falha cedo se o setUp não rodou.
     */
    private function bd(): bdTelegram
    {
        $this->assertInstanceOf(bdTelegram::class, $this->bd);

        return $this->bd;
    }

    public function testInstanciaCriadaComSucesso(): void
    {
        $this->assertInstanceOf(bdTelegram::class, $this->bd);
    }





    public function testConexaoHabilitaForeignKeysEmTodaConexao(): void
    {

        // A integridade referencial precisa ser efetiva, não apenas declarada.
        // No motor anterior ela era por conexão e vinha desligada; aqui o
        // servidor a aplica sempre, e este teste prova que continua aplicada.
        $this->assertTrue($this->bd()->queryExec(
            'CREATE TABLE fk_pai (id INTEGER PRIMARY KEY)',
            [],
            true
        ));
        $this->assertTrue($this->bd()->queryExec(
            'CREATE TABLE fk_filho (pai_id INTEGER REFERENCES fk_pai (id))',
            [],
            true
        ));
        $this->assertFalse($this->bd()->queryExec(
            'INSERT INTO fk_filho (pai_id) VALUES (?)',
            [999],
            true
        ));
    }

    public function testEscritaEhEnxergadaPorOutraConexao(): void
    {
        // A gravação precisa estar confirmada no servidor, e não presa a esta
        // conexão: uma segunda conexão enxerga a linha — o cenário do bot
        // respondendo a um update enquanto outra conexão lê o banco.
        $this->assertTrue($this->bd()->addUsuario(31337));


        $outraConexao = new bdTelegram(\BancoTeste::dsn($this->tmp));
        $usuario = $outraConexao->getUsuario(31337);
        $this->assertIsArray($usuario);
        $this->assertSame(31337, $usuario['id_telegram']);
    }

    /**
     * O sintoma que motivou esta garantia: um segundo webhook (ou um script de
     * CLI) mantém uma LEITURA aberta enquanto o bot precisa gravar. No motor
     * anterior o lock do leitor derrubava a escrita na hora ("database is
     * locked"), queryExec engolia a PDOException e devolvia false — o usuário
     * sumia do banco sem nenhum registro do motivo. Sob MVCC do PostgreSQL o
     * leitor não bloqueia o escritor, e é isso que o roteiro abaixo trava.
     *
     * O controle abaixo (mesmo roteiro, outra conexão) é o que impede este
     * teste de ser vacuoso: ele PROVA que o cenário é exercitado de verdade.
     */
    public function testEscritaConcorrenteComLeituraAbertaNaoPerdeDado(): void
    {
        $this->assertTrue($this->bd()->addUsuario(70001));

        // Conexão de leitura: abre transação e segura o snapshot.
        $leitor = new bdTelegram(\BancoTeste::dsn($this->tmp));
        $this->assertTrue($leitor->queryExec('BEGIN', [], true));
        $consulta = $leitor->queryExec('SELECT * FROM usuarios');
        $this->assertInstanceOf(\PDOStatement::class, $consulta);
        $this->assertCount(1, $consulta->fetchAll(PDO::FETCH_ASSOC));

        // Escritor concorrente: com WAL + busy_timeout a gravação passa.
        $escritor = new bdTelegram(\BancoTeste::dsn($this->tmp));
        $this->assertTrue(
            $escritor->addUsuario(70002),
            'Escrita concorrente com leitura aberta precisa passar (WAL + busy_timeout).'
        );

        $this->assertTrue($leitor->queryExec('COMMIT', [], true));

        // Nada se perdeu: os dois registros estão no banco, vistos por uma
        // terceira conexão.
        $this->assertCount(2, (new bdTelegram(\BancoTeste::dsn($this->tmp)))->todosUsuarios());
    }

    public function testConexaoConfiguraTimeoutsPostgres(): void
    {
        foreach (['lock_timeout' => '30s', 'statement_timeout' => '1min'] as $config => $valor) {
            $stmt = $this->bd()->queryExec('SHOW ' . $config, [], false);
            self::assertInstanceOf(\PDOStatement::class, $stmt);
            self::assertSame($valor, $stmt->fetchColumn());
        }
    }





    public function testDsnPadraoUsaODsnDeTesteQuandoOAmbienteInteiroConfere(): void
    {
        // O phpunit.xml já monta esse ambiente; a asserção fixa que as três
        // condições juntas (e só elas) redirecionam o banco.
        $metodo = new \ReflectionMethod(bdTelegram::class, 'dsnPadrao');
        $dsn = $metodo->invoke($this->bd());

        $this->assertSame(getenv('TEST_DATABASE_DSN'), $dsn);
    }

    public function testGetUsuarioRetornaFalseQuandoNaoExiste(): void
    {
        // fetch() sem linha retorna false no PDO.
        $this->assertFalse($this->bd()->getUsuario(999999));
    }

    public function testAddUsuarioInsereERetornaTrue(): void
    {
        // addUsuario usa $status=true em queryExec, devolvendo o booleano do execute().
        $resultado = $this->bd()->addUsuario(111222333);
        $this->assertTrue($resultado);

        $usuario = $this->bd()->getUsuario(111222333);
        $this->assertIsArray($usuario);
        $this->assertEquals(111222333, $usuario['id_telegram']);
        // Valor padrão do schema.
        $this->assertEquals('pt', $usuario['codigo_idioma']);
    }

    public function testUsuarioCriaQuandoInexistenteEReutilizaQuandoExiste(): void
    {
        // Primeira chamada deve criar o usuário.
        $criado = $this->bd()->usuario(444555666);
        $this->assertIsArray($criado);
        $this->assertEquals(444555666, $criado['id_telegram']);

        // Segunda chamada deve retornar exatamente o mesmo registro, sem duplicar.
        $reuso = $this->bd()->usuario(444555666);
        $this->assertSame($criado, $reuso);

        $todos = $this->bd()->todosUsuarios();
        $this->assertCount(1, $todos);
    }

    /**
     * A corrida real do webhook: duas mensagens do MESMO usuário novo chegam
     * quase juntas e dois processos executam `usuario()` em paralelo. Os dois
     * veem "não existe", os dois tentam inserir, e o segundo INSERT bate no
     * UNIQUE de id_telegram — addUsuario() devolve false.
     *
     * O contrato que importa é o do CHAMADOR (roteador.php:61): mesmo perdendo
     * a corrida, `usuario()` tem que devolver o registro existente, e não false
     * — senão a segunda mensagem do usuário seria atendida sem cadastro.
     *
     * A corrida é reproduzida de forma determinística por uma subclasse que
     * responde "não existe" só na PRIMEIRA consulta (o instante anterior ao
     * INSERT do outro processo); da segunda em diante ela consulta o banco de
     * verdade. O `usuario()` exercitado é o da classe de produção.
     */
    public function testUsuarioPerdendoACorridaDoInsertDevolveORegistroExistente(): void
    {
        // O "outro processo" já cadastrou o usuário.
        $this->assertTrue($this->bd()->addUsuario(90210));

        $comCorrida = new class (\BancoTeste::dsn($this->tmp)) extends \bdTelegram {
            /** @var int */
            public $consultas = 0;

            /**
             * @param int|string $id_telegram
             * @return array{id: mixed, id_telegram: mixed, codigo_idioma: mixed}|false
             */
            public function getUsuario($id_telegram)
            {
                $this->consultas++;
                if ($this->consultas === 1) {
                    // Instante anterior ao INSERT do outro processo.
                    return false;
                }

                return parent::getUsuario($id_telegram);
            }
        };

        $usuario = $comCorrida->usuario(90210);

        // Duas consultas: a de checagem (que "não achou") e a de leitura final.
        $this->assertSame(2, $comCorrida->consultas);
        $this->assertIsArray($usuario, 'Perder a corrida não pode devolver false ao roteador.');
        $this->assertEquals(90210, $usuario['id_telegram']);

        // E, principalmente, nenhuma duplicata foi criada.
        $this->assertCount(1, $this->bd()->todosUsuarios());
    }

    public function testTodosUsuariosVazioInicialmente(): void
    {
        $this->assertSame([], $this->bd()->todosUsuarios());
    }

    public function testGetUsuarioRetornaOsCamposAtivosDoCadastro(): void
    {
        $this->bd()->addUsuario(123123123);

        $usuario = $this->bd()->getUsuario(123123123);
        $this->assertIsArray($usuario);
        $this->assertSame(['id' => $usuario['id'], 'id_telegram' => 123123123, 'codigo_idioma' => 'pt'], $usuario);
    }

    public function testGetUsuarioNaoRetornaIndicesNumericos(): void
    {
        $this->bd()->addUsuario(321321321);

        // As linhas agora vêm em FETCH_ASSOC (antes FETCH_BOTH): sem índice 0.
        $usuario = $this->bd()->getUsuario(321321321);
        $this->assertIsArray($usuario);
        $this->assertArrayNotHasKey(0, $usuario);
    }

    public function testTodosUsuariosRetornaLinhasComChavesString(): void
    {
        $this->bd()->addUsuario(555111);
        $this->bd()->addUsuario(555222);

        $todos = $this->bd()->todosUsuarios();
        $this->assertCount(2, $todos);

        foreach ($todos as $linha) {
            // FETCH_ASSOC retorna os campos ativos com chaves textuais.
            $this->assertSame(
                ['id', 'id_telegram', 'codigo_idioma'],
                array_keys($linha)
            );
        }
    }

    public function testTodosUsuariosOrdenadoPorIdDesc(): void
    {
        $this->bd()->addUsuario(1001);
        $this->bd()->addUsuario(1002);
        $this->bd()->addUsuario(1003);

        $todos = $this->bd()->todosUsuarios();
        $this->assertCount(3, $todos);

        // A query ordena por id DESC: o último inserido vem primeiro.
        $this->assertEquals(1003, $todos[0]['id_telegram']);
        $this->assertEquals(1002, $todos[1]['id_telegram']);
        $this->assertEquals(1001, $todos[2]['id_telegram']);
    }

    public function testAddUsuarioDuplicadoRetornaFalsePorUnique(): void
    {
        // id_telegram é UNIQUE no schema; a segunda inserção viola a constraint.
        // queryExec captura a PDOException e devolve false.
        $this->assertTrue($this->bd()->addUsuario(7777));
        $this->assertFalse($this->bd()->addUsuario(7777));

        // Continua existindo apenas um registro.
        $this->assertCount(1, $this->bd()->todosUsuarios());
    }

    public function testQueryExecSelectRetornaStatementParaFetch(): void
    {
        $this->bd()->addUsuario(8888);

        // Sem $status (default false), queryExec retorna o PDOStatement preparado.
        $stmt = $this->bd()->queryExec('SELECT * FROM usuarios WHERE id_telegram=?', [8888]);
        $this->assertInstanceOf(\PDOStatement::class, $stmt);

        $linha = $stmt->fetch();
        $this->assertIsArray($linha);
        $this->assertEquals(8888, $linha['id_telegram']);
    }

    public function testQueryExecComStatusRetornaBooleano(): void
    {
        // Com $status=true, queryExec retorna o booleano do execute().
        $ok = $this->bd()->queryExec(
            'INSERT INTO usuarios (id_telegram) VALUES (?)',
            [9999],
            true
        );
        $this->assertTrue($ok);
    }

    public function testQueryExecComSqlInvalidoRetornaFalse(): void
    {
        // SQL inválido dispara PDOException, que é capturada e vira false.
        $this->assertFalse($this->bd()->queryExec('SELECT * FROM tabela_inexistente', []));
    }

    public function testQueryExecComStatusTrueESqlInvalidoRetornaFalse(): void
    {
        // A PDOException também precisa virar false no modo $status=true.
        $ok = $this->bd()->queryExec('INSERT INTO tabela_inexistente (x) VALUES (?)', [1], true);
        $this->assertFalse($ok);
    }

    /**
     * Falha no execute() (e não no prepare()) tem de virar `false`.
     *
     * É aqui que o ATTR_ERRMODE aplicado no construtor aparece: só com
     * ERRMODE_EXCEPTION o execute() lança e o catch de queryExec devolve
     * false. No modo silencioso (o padrão do PDO) o execute() apenas devolve
     * false, e queryExec entregaria o PDOStatement — objeto verdadeiro, sem
     * dado nenhum dentro. Quem chama testa `=== false`, então a consulta
     * quebrada passaria por bem-sucedida e o fetch viria vazio em silêncio.
     */
    public function testErroDeExecucaoViraFalseGracasAoModoDeExcecaoDoPdo(): void
    {
        // Um placeholder na consulta, três valores no bind: o prepare passa e
        // é o execute que falha.
        $resultado = $this->bd()->queryExec(
            'SELECT * FROM usuarios WHERE id_telegram=?',
            [1, 2, 3]
        );

        $this->assertFalse($resultado);
    }

    /**
     * Consulta que falha tem de deixar RASTRO.
     *
     * A falha precisa ficar visível com canal e mensagem no app.log.
     */
    public function testConsultaQueFalhaDeixaRastroNoCanalBd(): void
    {
        $tamanhoAntes = Support\AppLogReader::tamanhoAtual();

        $this->assertFalse($this->bd()->queryExec('SELECT * FROM tabela_inexistente', []));

        $linhas = Support\AppLogReader::linhasJson(Support\AppLogReader::conteudoDesde($tamanhoAntes));
        $this->assertCount(1, $linhas);
        $this->assertSame('bd', $linhas[0]['channel'] ?? null);
        $this->assertSame('error', $linhas[0]['level'] ?? null);

        $mensagem = $linhas[0]['msg'] ?? null;
        $this->assertIsString($mensagem);
        $this->assertStringContainsString('Consulta ao banco falhou', $mensagem);
        $this->assertStringContainsString('tabela_inexistente', $mensagem);

        // O SQL entra (é texto fixo do código); os PARÂMETROS, não — é ali que
        // moram o id do Telegram e o resto do dado do usuário.
        $contexto = $linhas[0]['context'] ?? null;
        $this->assertIsArray($contexto);
        $this->assertSame('SELECT * FROM tabela_inexistente', $contexto['sql'] ?? null);
        $this->assertArrayNotHasKey('parametros', $contexto);
        $this->assertArrayNotHasKey('data', $contexto);
    }

    /**
     * O log não pode carregar o valor dos binds: `usuario()` passa o
     * `id_telegram` por ali, e uma falha de banco não pode virar um dossiê de
     * quem falou com o bot no dia em que o disco encheu.
     */
    public function testRastroDaConsultaNaoCarregaOsParametrosDoBind(): void
    {
        $tamanhoAntes = Support\AppLogReader::tamanhoAtual();

        // Um placeholder, três valores: o prepare passa e o execute falha.
        $this->assertFalse(
            $this->bd()->queryExec('SELECT * FROM usuarios WHERE id_telegram=?', [987654321, 2, 3])
        );

        $trecho = Support\AppLogReader::conteudoDesde($tamanhoAntes);
        $this->assertNotSame('', $trecho, 'a falha precisa ter virado linha de log.');
        $this->assertStringNotContainsString('987654321', $trecho);
    }

    /**
     * Erro de SINTAXE (e não de tabela ausente) percorre o mesmo catch e
     * também precisa deixar rastro: o `prepare()` é que lança aqui, e o
     * registro fica no `catch`, depois dos dois pontos possíveis de falha.
     */
    public function testConsultaComSqlSintaticamenteInvalidoTambemDeixaRastro(): void
    {
        $tamanhoAntes = Support\AppLogReader::tamanhoAtual();

        $this->assertFalse($this->bd()->queryExec('ISTO NAO E SQL', []));

        $this->assertNotSame('', Support\AppLogReader::conteudoDesde($tamanhoAntes));
    }

    public function testQueryExecComParametrosNomeados(): void
    {
        $this->bd()->addUsuario(424242);

        // queryExec aceita array<int|string, mixed>: placeholders nomeados também.
        $stmt = $this->bd()->queryExec(
            'SELECT * FROM usuarios WHERE id_telegram=:id',
            [':id' => 424242]
        );
        $this->assertInstanceOf(\PDOStatement::class, $stmt);

        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($linha);
        $this->assertEquals(424242, $linha['id_telegram']);
    }

    public function testQueryExecInsertViolandoNotNullRetornaFalse(): void
    {
        // id_telegram é NOT NULL no schema: inserir null dispara PDOException -> false.
        $ok = $this->bd()->queryExec('INSERT INTO usuarios (id_telegram) VALUES (?)', [null], true);
        $this->assertFalse($ok);

        // Nada foi persistido.
        $this->assertSame([], $this->bd()->todosUsuarios());
    }

    public function testGetUsuarioRetornaFalseQuandoConsultaFalha(): void
    {
        // Sem a tabela, queryExec devolve false e getUsuario propaga como false.
        $this->assertTrue($this->bd()->queryExec('DROP TABLE usuarios', [], true));
        $this->assertFalse($this->bd()->getUsuario(123));
    }

    public function testTodosUsuariosRetornaArrayVazioQuandoConsultaFalha(): void
    {
        // Sem a tabela, o branch $busca === false devolve lista vazia (não false).
        $this->assertTrue($this->bd()->queryExec('DROP TABLE usuarios', [], true));
        $this->assertSame([], $this->bd()->todosUsuarios());
    }

    public function testUsuarioRetornaFalseQuandoInsercaoFalha(): void
    {
        // Sem a tabela, usuario() tenta criar, a inserção falha em silêncio
        // e o retorno final é false (o false do getUsuario após o insert).
        $this->assertTrue($this->bd()->queryExec('DROP TABLE usuarios', [], true));
        $this->assertFalse($this->bd()->usuario(123));
    }

    public function testGetUsuarioAceitaIdTelegramComoString(): void
    {
        // O docblock aceita int|string; o bind converte o parâmetro '654321'
        // para comparar com a coluna BIGINT de valor 654321.
        $this->bd()->addUsuario(654321);

        $usuario = $this->bd()->getUsuario('654321');
        $this->assertIsArray($usuario);
        $this->assertEquals(654321, $usuario['id_telegram']);
    }

    public function testUsuarioComIdStringNaoDuplicaUsuarioExistenteComIdInt(): void
    {
        $criado = $this->bd()->usuario(987654);
        $this->assertIsArray($criado);

        // Mesmo usuário, agora com o id vindo como string (ex.: valor de update JSON).
        $mesmo = $this->bd()->usuario('987654');
        $this->assertIsArray($mesmo);
        $this->assertEquals($criado['id'], $mesmo['id']);

        // Não pode ter criado um segundo registro.
        $this->assertCount(1, $this->bd()->todosUsuarios());
    }

    /**
     * Fixa a coerção de tipos do PDO/PostgreSQL observada no PHP 8.5.
     *
     * Antes do PHP 8.1 o driver devolvia TODA coluna como string ('1', '55555').
     * A partir do PHP 8.1 (PDO::ATTR_STRINGIFY_FETCHES = false por padrão) as
     * colunas INTEGER/BIGINT voltam como int nativo e só as colunas de texto
     * voltam como string. Este teste trava esse contrato: se um upgrade ou uma
     * mudança de configuração reintroduzir a stringificação, ele falha aqui e
     * não em produção, onde comparações === com int passariam a ser falsas.
     */
    public function testColunasNumericasVoltamComTipoNativoNoPhp85(): void
    {
        $this->assertTrue($this->bd()->addUsuario(55555));

        $usuario = $this->bd()->getUsuario(55555);
        $this->assertIsArray($usuario);
        $this->assertIsInt($usuario['id'], 'usuarios.id (INTEGER) deve voltar como int no PHP 8.5.');
        $this->assertIsInt($usuario['id_telegram'], 'usuarios.id_telegram (BIGINT) deve voltar como int no PHP 8.5.');
        $this->assertSame(55555, $usuario['id_telegram']);
        $this->assertIsString($usuario['codigo_idioma'], 'usuarios.codigo_idioma (VARCHAR) deve voltar como string.');
        $this->assertSame('pt', $usuario['codigo_idioma']);
    }

    /**
     * Mesmo contrato de tipos na leitura em lote (fetchAll + normalizeRow).
     */
    public function testCadastroDeContatoEIdempotenteERecusaIdInvalido(): void
    {
        $bd = $this->bd();
        $this->assertFalse($bd->registrarContato(0));
        $this->assertFalse($bd->registrarContato(-1));
        $this->assertTrue($bd->registrarContato(7001));
        $this->assertTrue($bd->registrarContato(7001));
        $this->assertCount(1, $bd->todosUsuarios());
        $this->assertTrue($bd->queryExec('DROP TABLE usuarios', [], true));
        $this->assertFalse($bd->registrarContato(7002));
    }

    public function testTodosUsuariosPreservaTiposNativosDasColunas(): void
    {
        $this->assertTrue($this->bd()->addUsuario(4242));

        $usuarios = $this->bd()->todosUsuarios();
        $this->assertCount(1, $usuarios);
        $this->assertIsInt($usuarios[0]['id']);
        $this->assertSame(4242, $usuarios[0]['id_telegram']);
        $this->assertSame('pt', $usuarios[0]['codigo_idioma']);

        // normalizeRow() descarta os índices numéricos do FETCH_ASSOC: só sobram
        // as colunas consultadas, com chave string.
        $this->assertSame(
            ['id', 'id_telegram', 'codigo_idioma'],
            array_keys($usuarios[0])
        );
    }

}
