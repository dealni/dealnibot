<?php

declare(strict_types=1);

namespace Tests;

use App\Support\TelegramContatos;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/bdTelegram.php';
require_once __DIR__ . '/../../inclusos/Telegram.php';

final class TelegramContatosPersistenciaTest extends TestCase
{
    private \PDO $pdo;
    private \bdTelegram $bd;
    private TelegramContatos $contatos;

    protected function setUp(): void
    {
        $chave = bin2hex(random_bytes(16));
        $this->pdo = \BancoTeste::conectar($chave);
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        $this->pdo->exec('INSERT INTO usuarios (id_telegram) VALUES (123), (456)');
        $this->bd = new \bdTelegram(\BancoTeste::dsn($chave));
        $this->contatos = new TelegramContatos($this->bd);
    }

    private function quantidadeContatos(): int
    {
        $stmt = $this->pdo->query('SELECT count(*) FROM telegram_contatos');
        self::assertInstanceOf(\PDOStatement::class, $stmt);
        $valor = $stmt->fetchColumn();
        self::assertIsInt($valor);
        return $valor;
    }

    /** @return array<string, mixed> */
    private function recusa(): array
    {
        return ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: user is deactivated'];
    }

    public function testRecusaIdempotentePreservaUsuarios(): void
    {
        $antes = $this->bd->todosUsuarios();
        $this->contatos->registrarResposta(123, $this->recusa(), 200);
        $this->contatos->registrarResposta(123, $this->recusa(), 200);
        self::assertFalse($this->contatos->disponivel(123));
        self::assertTrue($this->contatos->disponivel(456));
        self::assertSame($antes, $this->bd->todosUsuarios());
        self::assertSame(1, $this->quantidadeContatos());
    }

    public function testInteracaoReativaERecusaAntigaNaoVoltaABloquear(): void
    {
        $this->contatos->registrarResposta(123, $this->recusa(), 200);
        $this->contatos->registrarInteracao(['message' => ['date' => 300, 'chat' => ['id' => 123, 'type' => 'private'], 'from' => ['id' => 123, 'is_bot' => false]]]);
        $this->contatos->registrarResposta(123, $this->recusa(), 250);
        self::assertTrue($this->contatos->disponivel(123));
        $this->contatos->registrarResposta(123, $this->recusa(), 400);
        $this->contatos->registrarInteracao(['message' => ['date' => 300, 'chat' => ['id' => 123, 'type' => 'private'], 'from' => ['id' => 123]]]);
        self::assertFalse($this->contatos->disponivel(123));
    }

    public function testGrupoEOutroAutorNaoReativamPrivado(): void
    {
        $this->contatos->registrarResposta(123, $this->recusa(), 200);
        foreach ([['id' => -100, 'type' => 'supergroup'], ['id' => 456, 'type' => 'private']] as $chat) {
            $this->contatos->registrarInteracao(['message' => ['date' => 300, 'chat' => $chat, 'from' => ['id' => 123]]]);
        }
        self::assertFalse($this->contatos->disponivel(123));
    }

    public function testInteracaoIncompletaOuSemDataPositivaNaoReativaContato(): void
    {
        self::assertTrue($this->contatos->registrarResposta(123, $this->recusa(), 200));
        $mensagem = ['date' => 300, 'chat' => ['id' => 123, 'type' => 'private'], 'from' => ['id' => 123]];
        $updates = [
            [], ['message' => 'invalida'],
            ['message' => array_replace($mensagem, ['from' => ['id' => 123, 'is_bot' => true]])],
            ['message' => array_replace($mensagem, ['from' => null])],
            ['message' => array_replace($mensagem, ['chat' => null])],
            ['message' => array_replace($mensagem, ['date' => 0])],
            ['message' => array_replace($mensagem, ['date' => '300'])],
            ['message' => array_replace($mensagem, ['edit_date' => 0])],
            ['callback_query' => ['from' => ['id' => 123]]],
        ];
        foreach ($updates as $update) {
            $this->contatos->registrarInteracao($update);
            self::assertFalse($this->contatos->disponivel(123));
        }
        $this->contatos->registrarInteracao(['edited_message' => array_replace($mensagem, ['date' => 100, 'edit_date' => 300])]);
        self::assertTrue($this->contatos->disponivel(123));
    }

    public function testLimitesDeRegistroEFiltroNaoInventamContatos(): void
    {
        foreach ([[0, 200], [-1, 200], [123, 0], [123, -1]] as [$id, $momento]) {
            self::assertFalse($this->contatos->registrarResposta($id, $this->recusa(), $momento));
            $this->contatos->reativar($id, $momento);
        }
        self::assertFalse($this->contatos->registrarResposta(123, ['ok' => true], 200));
        self::assertSame(0, $this->quantidadeContatos());
        self::assertTrue($this->contatos->disponivel(0));
        self::assertTrue($this->contatos->disponivel(-123));
    }

    public function testCallbackRetomadoUsaHorarioOriginalDaInbox(): void
    {
        $this->pdo->exec("INSERT INTO webhook_inbox (update_id,payload_json,status,recebido_em,disponivel_em) VALUES (77,'{}','processado',150,150)");
        $this->contatos->registrarResposta(123, $this->recusa(), 200);
        $update = ['update_id' => 77, 'callback_query' => ['from' => ['id' => 123], 'message' => ['chat' => ['id' => 123, 'type' => 'private']]]];
        $this->contatos->registrarInteracao($update);
        self::assertFalse($this->contatos->disponivel(123));
        $this->pdo->exec('UPDATE webhook_inbox SET recebido_em=300 WHERE update_id=77');
        $this->contatos->registrarInteracao($update);
        self::assertTrue($this->contatos->disponivel(123));
    }

    public function testClienteRegistraRecusaRealSemEnviarParaRede(): void
    {
        $fake = new class ('test-token', false) extends \Telegram {
            protected function sendAPIRequest(string $url, array $content, bool $post = true): string
            {
                return '{"ok":false,"error_code":403,"description":"Forbidden: user is deactivated"}';
            }
        };
        $fake->acompanharContatos($this->bd);
        $r = $fake->sendMessage(['chat_id' => 123, 'text' => 'teste']);
        self::assertFalse($r['ok']);
        self::assertFalse($this->contatos->disponivel(123));
        $fake->sendPhoto(['chat_id' => -123, 'photo' => 'fake']);
        self::assertSame(1, $this->quantidadeContatos());
    }

}
