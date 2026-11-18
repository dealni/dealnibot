<?php

declare(strict_types=1);

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/**
 * Variável de ambiente que traz o usuário do PostgreSQL deste bot. O portão
 * a apaga e a devolve para provar que produção recusa conexão sem usuário.
 */
const VARIAVEL_USUARIO_POSTGRES = 'DEALNI_DB_USER';
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

if (getenv('CHATBOTS_PG_TEST_DSN') === false || getenv('CHATBOTS_PG_TEST_DSN') === '') {
    passthru(implode(' ', array_map(escapeshellarg(...), ['bash', __DIR__ . '/with_postgres.sh', PHP_BINARY, __FILE__])), $statusPostgres);
    exit($statusPostgres);
}

// Gate separado: exige um banco descartável, vazio e nomeado chatbots_test_*.
// Não carrega configuração real, Redis ou transportes dos bots.
$raiz = dirname(__DIR__, 2);
require_once $raiz . '/inclusos/banco.php';
require_once $raiz . '/vendor/autoload.php';
require_once $raiz . '/inclusos/bdTelegram.php';

$dsn = getenv('CHATBOTS_PG_TEST_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'pgsql:')) {
    fwrite(STDERR, "Defina CHATBOTS_PG_TEST_DSN para um PostgreSQL descartável com nome chatbots_test_*.\n");
    exit(2);
}
putenv('APP_ENV=test');
putenv('CHATBOTS_TESTING=1');
putenv('CHATBOTS_NETWORK_DISABLED=1');
putenv('TEST_DATABASE_DSN=' . $dsn);
$pdo = conectarBancoDoBot();
$nomeBanco = ConsultaBanco::coluna($pdo, 'SELECT current_database()');
if (!is_string($nomeBanco) || !str_starts_with($nomeBanco, 'chatbots_test_')) {
    throw new RuntimeException('Teste recusado: banco sem prefixo chatbots_test_.');
}
$confirmar = static function (bool $condicao, string $mensagem): void {
    if (!$condicao) {
        throw new RuntimeException($mensagem);
    }
};
$confirmar((int) ConsultaBanco::coluna($pdo, "SELECT count(*) FROM pg_tables WHERE schemaname = 'public'") === 0, 'Teste exige banco vazio.');
// Todas as estruturas abaixo são criadas pelo próprio gate após a guarda de banco vazio.
require_once $raiz . '/scripts/deploy/postgres_migrate.php';
$confirmar(migrarPostgres([]) === 0, 'Bootstrap PostgreSQL falhou.');
try {
    migrarPostgres(['--adotar']);
    throw new LogicException('Adoção implícita PostgreSQL foi aceita.');
} catch (RuntimeException $erro) {
    $confirmar(str_contains($erro->getMessage(), 'aceita --status'), $erro->getMessage());
}
$pdo->exec('DELETE FROM schema_migrations_postgresql');
try {
    migrarPostgres([]);
    throw new LogicException('Baseline sobrescreveu schema preexistente sem razão.');
} catch (PDOException) {
    $confirmar((int) ConsultaBanco::coluna($pdo, 'SELECT count(*) FROM schema_migrations_postgresql') === 0, 'Erro deixou razão parcial.');
}
$pdo->exec("INSERT INTO schema_migrations_postgresql VALUES ('001_inicial.sql', 'incorreto', 'teste')");
try {
    migrarPostgres(['--status']);
    throw new LogicException('Hash adulterado foi aceito.');
} catch (RuntimeException $erro) {
    $confirmar(str_contains($erro->getMessage(), 'alterada'), $erro->getMessage());
}
// Limpa apenas o schema sintético que este processo acabou de criar.
$pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
$pdo->exec('BEGIN IMMEDIATE');
$pdo->rollBack();
migrarPostgres([]);
$pdo->exec('INSERT INTO usuarios (id, id_telegram) VALUES (900, 9876543210123)');
$pdo->query("SELECT setval(pg_get_serial_sequence('usuarios', 'id'), 900)");
$pdo->exec("INSERT INTO webhook_inbox (update_id, payload_json, recebido_em, disponivel_em) VALUES (123, '{}', 1700000000, 1700000000)");
try {
    $bloqueador = conectarBancoDoBot();
    $bloqueador->beginTransaction();
    $pdo->exec("SET lock_timeout = '20ms'");
    try {
        $pdo->beginTransaction();
        throw new LogicException('Transação ignorou o escritor concorrente.');
    } catch (PDOException) {
        $confirmar(!$pdo->inTransaction(), 'Falha de lock vazou transação.');
    } finally {
        $bloqueador->rollBack();
        $pdo->exec("SET lock_timeout = '30s'");
    }
    try {
        conectarBancoDoBot('pgsql:host=/caminho-inexistente-chatbots;dbname=chatbots_test_ausente', 1);
        throw new LogicException('Conexão indisponível foi aceita.');
    } catch (RuntimeException $erro) {
        $confirmar($erro->getMessage() === 'Não foi possível conectar ao PostgreSQL deste bot.', 'Erro de conexão não foi sanitizado.');
    }
    $usuarioAnterior = getenv(VARIAVEL_USUARIO_POSTGRES);
    putenv('APP_ENV=production');
    putenv(VARIAVEL_USUARIO_POSTGRES);
    try {
        conectarBancoDoBot($dsn);
        throw new LogicException('Produção aceitou usuário PostgreSQL ausente.');
    } catch (RuntimeException $erro) {
        $confirmar(str_contains($erro->getMessage(), 'DB_USER'), 'Validação do usuário falhou.');
    } finally {
        putenv('APP_ENV=test');
        putenv($usuarioAnterior === false ? VARIAVEL_USUARIO_POSTGRES : VARIAVEL_USUARIO_POSTGRES . '=' . $usuarioAnterior);
    }
    $bd = new bdTelegram();
    $usuario = $bd->usuario(9876543210124);
    $confirmar(is_array($usuario) && $usuario['id'] > 900, 'Sequência/ID de 64 bits não preservado.');
    $confirmar($bd->getUsuario(9876543210123) !== false, 'Usuário persistido inacessível no runtime.');
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO webhook_inbox (update_id, payload_json, recebido_em, disponivel_em) VALUES (?, ?, ?, ?)');
    $payload = '{"text":"ação 🚀 INSERT OR IGNORE `float`", "nullable":null}';
    $stmt->execute([9876543210123, $payload, 1700000000, 1700000000]);
    $confirmar($stmt->rowCount() === 1, 'Inbox não inseriu.');
    $stmt->execute([9876543210123, 'não pode substituir', 1700000000, 1700000000]);
    $confirmar($stmt->rowCount() === 0, 'Inbox duplicada inseriu novamente.');
    $confirmar(ConsultaBanco::coluna($pdo, 'SELECT payload_json FROM webhook_inbox WHERE update_id = 9876543210123') === $payload, 'Payload alterado.');
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM webhook_inbox');
    $pdo->rollBack();
    $confirmar((int) ConsultaBanco::coluna($pdo, 'SELECT count(*) FROM webhook_inbox') === 2, 'Rollback perdeu inbox.');
    $confirmar(ConsultaBanco::coluna($pdo, "SELECT 'Exemplo-123' LIKE 'exemplo-%'") === true, 'LIKE mudou a comparação de caixa.');
    $literal = ConsultaBanco::coluna($pdo, "SELECT 'MAX(0, 1) INSERT OR IGNORE `integer`' AS texto");
    $confirmar($literal === 'MAX(0, 1) INSERT OR IGNORE `integer`', 'Adaptador alterou literal SQL.');
    $data = ConsultaBanco::coluna($pdo, "SELECT DATE(datetime(1700000000, 'unixepoch', '-3 hours'))");
    $confirmar($data === '2023-11-14', 'Relatório mudou dia civil.');
    $hora = ConsultaBanco::coluna($pdo, "SELECT strftime('%H', datetime(1700000000, 'unixepoch', '-3 hours'))");
    $confirmar($hora === '19', 'Relatório mudou hora.');

    $json = $pdo->prepare("SELECT json_valid(payload) AS valido, json_extract(payload, '$.id_sms') AS id_sms, json_extract(payload, '$.provedor') AS provedor FROM (SELECT CAST(? AS TEXT) AS payload) fila");
    $json->execute(['{"id_sms":"000123","provedor":"ação 🚀"}']);
    $confirmar($json->fetch(PDO::FETCH_ASSOC) === ['valido' => true, 'id_sms' => '000123', 'provedor' => 'ação 🚀'], 'JSON alterou texto/zeros iniciais.');
    $json->execute(['{"id_sms":null}']);
    $confirmar($json->fetch(PDO::FETCH_ASSOC) === ['valido' => true, 'id_sms' => null, 'provedor' => null], 'JSON alterou NULL/campo ausente.');
    $confirmar(ConsultaBanco::coluna($pdo, "SELECT json_valid(payload) FROM (SELECT 'invalido' AS payload) fila") === false, 'JSON inválido foi aceito.');
    // ------------------------------------------------------------------
    // Lease da inbox do webhook, exercitada contra o PostgreSQL de verdade:
    // idempotência do registro, aquisição única, confirmação com guarda de
    // tentativa, expiração do lease e backoff do payload ilegível. O teste
    // unitário não alcança isso porque a corrida é resolvida pelo próprio
    // banco, em um único UPDATE ... RETURNING.
    // ------------------------------------------------------------------
    require_once $raiz . '/inclusos/funcoes_webhook_inbox.php';
    $confirmar(registrarUpdateWebhook($bd, ['update_id' => 445, 'message' => ['text' => '/start']], 10), 'Webhook não persistiu.');
    $confirmar(registrarUpdateWebhook($bd, ['update_id' => 445, 'message' => ['text' => 'reentrega']], 10), 'Reentrega do mesmo update_id não foi absorvida.');
    $confirmar(
        ConsultaBanco::coluna($pdo, 'SELECT payload_json FROM webhook_inbox WHERE update_id = 445') === '{"update_id":445,"message":{"text":"/start"}}',
        'Reentrega sobrescreveu o payload já persistido.'
    );
    $item = pegarUpdateWebhookPorId($bd, 445, 10);
    $confirmar($item !== null && $item['update_id'] === 445 && $item['tentativa'] === 1 && $item['payload']['message'] === ['text' => '/start'], 'Webhook não adquiriu o update imediato.');
    $confirmar(pegarUpdateWebhookPorId($bd, 445, 10) === null, 'Webhook adquiriu duas vezes a mesma lease.');
    $confirmar(marcarUpdateWebhookProcessado($bd, 445, 11, 1), 'Webhook não confirmou processamento.');
    $confirmar(marcarUpdateWebhookProcessado($bd, 445, 11, 1) === false, 'Confirmação repetida passou pela guarda de tentativa.');
    $confirmar(pegarUpdateWebhookPorId($bd, 445, 12) === null, 'Webhook reprocessou update concluído.');

    // Payload ilegível: a linha é reservada, marcada como falha e sai da cabeça
    // da fila pelo backoff, em vez de ser devolvida para sempre ao worker.
    $pdo->exec("INSERT INTO webhook_inbox (update_id, payload_json, recebido_em, disponivel_em) VALUES (446, 'json invalido', 10, 10)");
    $confirmar(pegarUpdateWebhookPorId($bd, 446, 10) === null, 'Webhook entregou payload ilegível ao roteador.');
    $confirmar(ConsultaBanco::coluna($pdo, 'SELECT status FROM webhook_inbox WHERE update_id = 446') === 'erro', 'Payload ilegível não foi marcado como falha.');
    $confirmar((int) ConsultaBanco::coluna($pdo, 'SELECT tentativas FROM webhook_inbox WHERE update_id = 446') === 1, 'Payload ilegível não registrou a tentativa que falhou.');
    $confirmar((int) ConsultaBanco::coluna($pdo, 'SELECT disponivel_em FROM webhook_inbox WHERE update_id = 446') > 10, 'Payload ilegível voltou sem backoff e travaria a cabeça da fila.');
    $confirmar(pegarUpdateWebhookPorId($bd, 446, 20) === null, 'Payload ilegível foi entregue depois do backoff.');
    $confirmar((int) ConsultaBanco::coluna($pdo, 'SELECT tentativas FROM webhook_inbox WHERE update_id = 446') === 2, 'Reentrega do payload ilegível não contou nova tentativa.');
    $pdo->exec('DELETE FROM webhook_inbox WHERE update_id IN (445, 446)');
    require_once __DIR__ . '/postgres_concorrencia.php';
    verificarConcorrenciaPostgres($pdo, $raiz);
    require_once $raiz . '/inclusos/funcoes_sondas.php';
    $confirmar(sondaPostgresResponde(), 'Readiness PostgreSQL falhou.');
    require_once $raiz . '/scripts/deploy/postgres_migrate.php';
    $confirmar(migrarPostgres(['--status']) === 0, 'Razão PostgreSQL divergente.');
    $confirmar(migrarPostgres([]) === 0, 'Reaplicação de migrations falhou.');
    echo "PostgreSQL: schema, hashes, rollback, IDs, replay, runtime e migrations OK.\n";
} finally {
    $pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
}
