<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/bdTelegram.php';
require_once __DIR__ . '/../../inclusos/funcoes_webhook_inbox.php';

final class RetencaoInboxLotesTest extends TestCase
{
    public function testLimitaLotesPreservaUpdatesRecentesEPropagaFalhas(): void
    {
        $dsn = \BancoTeste::dsn();
        $pdo = \BancoTeste::conectar($dsn);
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));
        $bd = new \bdTelegram($dsn);
        $agora = 40 * 86400;
        $stmt = $pdo->prepare('INSERT INTO webhook_inbox (update_id,payload_json,status,recebido_em,disponivel_em,processado_em) VALUES (?,\'{}\',?,?,0,?)');
        for ($id = 1; $id <= 7; $id++) {
            $stmt->execute([$id, 'processado', 1, 1]);
        }
        $stmt->execute([8, 'erro', 1, null]);
        $stmt->execute([9, 'processado', 1, $agora]);
        $stmt->execute([10, 'processando', 1, null]);
        $stmt->execute([11, 'pendente', 1, null]);
        self::assertSame(['processados' => 6, 'falhas' => 1], limparInboxWebhookRetida($bd, $agora, 3, 2));
        self::assertSame(['processados' => 1, 'falhas' => 0], limparInboxWebhookRetida($bd, $agora, 3, 2));
        self::assertSame(3, (int) \ConsultaBanco::coluna($pdo, 'SELECT count(*) FROM webhook_inbox'));
        $pdo->exec('DROP TABLE webhook_inbox');
        $this->expectException(\RuntimeException::class);
        limparInboxWebhookRetida($bd, $agora);
    }
}
