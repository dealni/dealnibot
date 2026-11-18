<?php

declare(strict_types=1);

namespace Tests;

use bdTelegram;
use PHPUnit\Framework\TestCase;
use Redis;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';
require_once __DIR__ . '/../../inclusos/bdTelegram.php';

/**
 * Testes de FLUXO dos comandos de IA na etapa "parsing do update -> portão de
 * cota (chamada)". Amarram três subsistemas reais que hoje só são testados
 * isoladamente:
 *   - o parsing do update pela classe Telegram (UserID/ChatID/Chat, via setData
 *     em memória, sem rede);
 *   - o rate limiting de inclusos/limites_requisicoes.php pelo portão que a
 *     produção usa de verdade: consumir_limite_diario(), com o script Lua
 *     EXECUTADO pelo dublê Support\RedisFalsoComLua;
 *   - o registro do usuário em bdTelegram sobre schema PostgreSQL DESCARTÁVEL.
 * O Redis é um fake em memória (mock tipado como Redis) — nenhuma conexão real.
 *
 * NÃO inclui comandos/*.php nem roteador.php: o topo desses scripts faz
 * sendMessage/cURL reais e termina em exit (mataria o runner). A lógica de
 * decisão que ainda vive no topo dos comandos é ESPELHADA aqui e TRAVADA na
 * fonte por assertStringContainsString — se o comando divergir, o teste quebra.
 *
 * O que estes testes caracterizam (comportamento ATUAL):
 *   - a cota diária é indexada pelo id derivado do update;
 *   - em chat privado o usuário recebe exatamente 100 requisições e a 101ª é
 *     bloqueada, com a mensagem exata que o comando envia;
 *   - o id usado a jusante é o do USUÁRIO em privado e o do CHAT em
 *     grupo/supergrupo (contexto compartilhado por grupo);
 *   - o registro do usuário é idempotente ao longo do fluxo.
 */
final class FluxoIaRateLimitTest extends TestCase
{
    /** @var string Identificador do schema PostgreSQL descartável deste teste. */
    private $tmp = '';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../inclusos/limites_requisicoes.php';

        // Identificador único: BancoTeste deriva dele um schema isolado por
        // teste, semeado com o schema real.
        $tmp = tempnam(sys_get_temp_dir(), 'dealnibot_fluxo_ia_');
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
    }

    // ---------------------------------------------------------------------
    // Utilitários
    // ---------------------------------------------------------------------

    /**
     * Instância de Telegram com token fake alimentada por um update em memória
     * (setData não faz rede).
     *
     * @param array<string, mixed> $update
     */
    private function comUpdate(array $update): Telegram
    {
        $telegram = new Telegram('123456:FAKE_TOKEN', false);
        $telegram->setData($update);

        return $telegram;
    }

    /** UserID()/ChatID() são ?int; aqui o update sempre traz o id, então narrowa para int. */
    private function inteiroNaoNulo(?int $valor): int
    {
        // assertIsInt (@phpstan-assert int) narrowa $valor de ?int para int.
        $this->assertIsInt($valor, 'Esperado inteiro não-nulo neste update.');

        return $valor;
    }

    /**
     * Id usado a jusante pelo comando de IA. Espelha comandos/chatgpt.php
     * (L53 UserID, L58-59 chatType, L93-98 reescrita para o chat em grupo);
     * a fidelidade é travada pelo assert de fonte em
     * testIdAJusanteEDoUsuarioEmPrivadoEDoChatEmGrupo().
     */
    private function idAJusante(Telegram $telegram): int
    {
        $id = $telegram->UserID();
        $chat = $telegram->Chat();
        $tipo = is_array($chat) && isset($chat['type']) && is_string($chat['type']) ? $chat['type'] : null;

        if ($tipo === 'group' || $tipo === 'supergroup') {
            $chatId = $telegram->ChatID();
            if ($chatId !== null) {
                $id = $chatId;
            }
        }

        return is_int($id) ? $id : 0;
    }

    /** Fonte de comandos/chatgpt.php sem executar nenhuma linha do script. */
    private function fonteChatgpt(): string
    {
        // ler() já lança quando não consegue ler, e devolve string: o
        // assertIsString que existia aqui virou tautologia.
        return Support\FonteDoComando::ler(__DIR__ . '/../../comandos/chatgpt.php');
    }

    /**
     * Dublê de Redis que EXECUTA o script Lua da cota, em vez de imitar o que
     * ele deveria fazer: sem isso, um `return 0` no lugar do corpo do script
     * deixaria este teste verde.
     */
    private function fakeRedis(): Support\RedisFalsoComLua
    {
        return new Support\RedisFalsoComLua();
    }

    /**
     * Update de mensagem de texto em chat privado.
     *
     * @return array<string, mixed>
     */
    private function updatePrivado(int $idUsuario): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'from' => ['id' => $idUsuario, 'is_bot' => false, 'first_name' => 'Fulano'],
                'chat' => ['id' => $idUsuario, 'type' => 'private'],
                'text' => 'Olá!',
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Testes de fluxo
    // ---------------------------------------------------------------------

    public function testPortaoDeCotaIndexaPeloIdDerivadoDoUpdate(): void
    {
        $telegram = $this->comUpdate($this->updatePrivado(777));
        $id = $this->inteiroNaoNulo($telegram->UserID());
        $this->assertSame(777, $id);

        // chatType lido como o comando faz: $telegram->Chat() ?? [].
        $chat = $telegram->Chat();
        $tipo = is_array($chat) ? ($chat['type'] ?? null) : null;
        $this->assertSame('private', $tipo);

        // O portão de PRODUÇÃO é o consumo atômico (o par verificar/incrementar
        // não é chamado por comando nenhum, ver
        // LimitesRequisicoesTest::testOPortaoDeProducaoEhSomenteOConsumoAtomico).
        $redis = $this->fakeRedis();
        $info = consumir_limite_diario($redis, $id, 100);

        // Só a chave daquele id foi tocada, com reserva atômica e TTL da criação.
        $hoje = limite_diario_dia_atual();
        $this->assertSame(
            [
                "GET limite_diario:777:{$hoje}",
                "INCRBY limite_diario:777:{$hoje} 1",
                "EXPIRE limite_diario:777:{$hoje} 86400",
            ],
            $redis->operacoes()
        );
        $this->assertTrue($info['permitida']);
        $this->assertFalse($info['atingiu_limite']);
        $this->assertSame(1, $info['contagem_atual']);
        $this->assertSame(100, $info['limite_maximo']);
    }

    public function testMensagemDeBloqueioAnunciaACotaQueFoiAplicada(): void
    {
        // Que a cota de 100 libera 100 e bloqueia a 101ª é verificado com o
        // Lua real em LimiteDiarioLuaTest, e a mensagem enviada de verdade em
        // FluxoIaTextoChatgptTest::testCotaEstouradaAvisaOUsuarioSemChamarAOpenAi.
        // Aqui o que se trava é a LIGAÇÃO entre os dois: o número anunciado sai
        // do mesmo $info_limite que barrou a requisição, e não de um literal
        // que envelhece sozinho quando a cota mudar.
        $telegram = $this->comUpdate($this->updatePrivado(777));
        $id = $this->inteiroNaoNulo($telegram->UserID());
        $redis = $this->fakeRedis();

        $this->assertTrue(consumir_limite_diario($redis, $id, 1)['permitida']);
        $bloqueio = consumir_limite_diario($redis, $id, 1);

        $this->assertTrue($bloqueio['atingiu_limite']);
        $this->assertSame(1, $bloqueio['limite_maximo']);

        // O texto do comando, na íntegra e com a interpolação: nem o número
        // fixo nem outra variável passariam por aqui.
        $fonte = $this->fonteChatgpt();
        $this->assertStringContainsString(
            "'text' => \"Você atingiu o limite de {\$info_limite['limite_maximo']} requisições diárias. "
                . 'Tente novamente amanhã! 🙂",',
            $fonte
        );
        // E a cota que o comando aplica continua sendo 100, em qualquer chat: o
        // sujeito do balde muda (usuário no privado, grupo em grupo), o teto não.
        $this->assertStringContainsString(
            "\$info_limite = consumir_limite_diario(\n"
                . "        \$redis,\n"
                . "        limite_diario_sujeito(\$chatType, \$id_telegram, \$telegram->ChatID()),\n"
                . "        100\n"
                . '    );',
            $fonte
        );
        // O aviso, esse sim, só sai no privado.
        $this->assertStringContainsString("        if (\$chatType === 'private') {\n            \$telegram->sendMessage(", $fonte);
    }

    public function testIdAJusanteEDoUsuarioEmPrivadoEDoChatEmGrupo(): void
    {
        $privado = $this->comUpdate($this->updatePrivado(777));
        $grupoA = $this->comUpdate([
            'message' => [
                'message_id' => 11,
                'from' => ['id' => 777, 'is_bot' => false],
                'chat' => ['id' => -100500, 'type' => 'group'],
                'text' => 'oi',
            ],
        ]);
        $grupoB = $this->comUpdate([
            'message' => [
                'message_id' => 12,
                'from' => ['id' => 888, 'is_bot' => false],
                'chat' => ['id' => -100500, 'type' => 'supergroup'],
                'text' => 'oi',
            ],
        ]);

        // Em privado o id a jusante é o do USUÁRIO.
        $this->assertSame(777, $this->idAJusante($privado));
        // Em grupo/supergrupo o comando reescreve para o id do CHAT.
        $this->assertSame(-100500, $this->idAJusante($grupoA));
        $this->assertSame(-100500, $this->idAJusante($grupoB));

        // Consequência: dois membros distintos do MESMO grupo compartilham o
        // bucket (mesmo id a jusante)...
        $this->assertSame($this->idAJusante($grupoA), $this->idAJusante($grupoB));
        // ...enquanto o mesmo usuário em privado vs grupo fica isolado.
        $this->assertNotSame($this->idAJusante($privado), $this->idAJusante($grupoA));

        // Trava a regra de reescrita na fonte (comandos/chatgpt.php L93-97).
        $fonte = $this->fonteChatgpt();
        $this->assertStringContainsString(
            "if (\$chatType === 'group' || \$chatType === 'supergroup')",
            $fonte
        );
        $this->assertStringContainsString('$id_telegram = $chatId;', $fonte);
    }

    public function testFluxoPrivadoRegistraUsuarioUmaVezEIncrementaCota(): void
    {
        $telegram = $this->comUpdate($this->updatePrivado(777));
        $id = $this->inteiroNaoNulo($telegram->UserID());

        $bd = new bdTelegram(\BancoTeste::dsn($this->tmp));

        // O roteador registra o usuário (roteador.php L45) ANTES de o comando rodar.
        $usuario = $bd->usuario($id);
        // assertIsArray (@phpstan-assert array) narrowa $usuario de array|false.
        $this->assertIsArray($usuario);
        $this->assertEquals(777, $usuario['id_telegram'] ?? null);

        // O portão de cota incrementa o MESMO id derivado do update.
        $redis = $this->fakeRedis();
        $this->assertTrue(consumir_limite_diario($redis, $id)['permitida']);

        // Tratar o mesmo update de novo não duplica o usuário (idempotência do fluxo)...
        $bd->usuario($id);
        $this->assertCount(1, $bd->todosUsuarios());

        // ...e a cota segue no mesmo bucket diário.
        $this->assertTrue(consumir_limite_diario($redis, $id)['permitida']);
        $hoje = limite_diario_dia_atual();
        $this->assertSame('2', $redis->valorDe("limite_diario:777:{$hoje}"));
        $this->assertSame(86400, $redis->expiracaoDe("limite_diario:777:{$hoje}"));
    }
}
