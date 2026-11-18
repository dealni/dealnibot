<?php

declare(strict_types=1);

/**
 * Teste de concorrência exclusivamente chamado pelo gate PostgreSQL.
 *
 * Oito processos disputam a MESMA escrita idempotente deste produto; o número
 * conferido no fim sai dessa escrita, e por isso o cenário é dado deste
 * repositório, não mecânica do portão.
 *
 * Qual repositório foi carregado se descobre pelo `name` do `composer.json` da
 * raiz recebida, nunca pelo nome da pasta: checkout renomeado, worktree ou
 * clone com sufixo continuam sendo este repositório, e o portão não pode
 * reprovar por causa disso. Manifesto de outro projeto, esse sim, para aqui.
 */
// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
// Escrita deste bot: o contador de tentativas do webhook_inbox, incrementado
// sob transação. Oito incrementos têm de virar oito, e o portão entrega a
// conexão já aberta porque a conferência é uma leitura direta da tabela.
function verificarConcorrenciaPostgres(PDO $pdo, string $raiz): void
{
    $pacoteEsperado = 'dealni/dealnibot';
    $codigo = <<<'CODE'
        putenv('APP_ENV=test'); putenv('CHATBOTS_TESTING=1'); putenv('CHATBOTS_NETWORK_DISABLED=1');
        putenv('TEST_DATABASE_DSN=' . getenv('CHATBOTS_PG_TEST_DSN'));
        require $argv[1] . '/vendor/autoload.php'; require $argv[1] . '/inclusos/bdTelegram.php';
        $pdo = conectarBancoDoBot();
        $pdo->exec('BEGIN IMMEDIATE');
        $q = $pdo->query('SELECT tentativas FROM webhook_inbox WHERE update_id = 9876543210123');
        $n = (int) $q->fetchColumn(); usleep(15000);
        $pdo->prepare('UPDATE webhook_inbox SET tentativas = ? WHERE update_id = 9876543210123')->execute([$n+1]);
        $pdo->commit();
        CODE;
    // --- FIM DOS DADOS; daqui para baixo o código é canônico ----------------

    $pacote = pacoteDoManifesto($raiz);
    if ($pacote !== $pacoteEsperado) {
        throw new RuntimeException("Gate de concorrência é deste repositório; manifesto recebido: {$pacote}.");
    }
    $processos = [];
    for ($i = 0; $i < 8; ++$i) {
        $processo = proc_open([PHP_BINARY, '-r', $codigo, $raiz, $pacoteEsperado], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($processo)) {
            throw new RuntimeException('Falha iniciando concorrente.');
        }
        $processos[] = [$processo, $pipes];
    }
    foreach ($processos as [$processo, $pipes]) {
        $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($processo) !== 0) {
            throw new RuntimeException('Concorrente falhou: ' . $saida);
        }
    }

    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) ----
    // Nenhum incremento perdido: os oito têm de estar no contador.
    $ok = (int) ConsultaBanco::coluna($pdo, 'SELECT tentativas FROM webhook_inbox WHERE update_id = 9876543210123') === 8;
    // --- FIM DOS DADOS; daqui para baixo o código é canônico ----------------
    if (!$ok) {
        throw new RuntimeException('Escrita perdida ou crédito duplicado sob concorrência.');
    }
    echo "Concorrência: oito processos, sem crédito duplicado/escrita perdida.\n";
}

/** Nome do pacote declarado no composer.json da raiz recebida. */
function pacoteDoManifesto(string $raiz): string
{
    $bruto = @file_get_contents($raiz . '/composer.json');
    if (!is_string($bruto)) {
        throw new RuntimeException("composer.json ausente em {$raiz}.");
    }
    try {
        $manifesto = json_decode($bruto, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $erro) {
        throw new RuntimeException("composer.json inválido em {$raiz}: " . $erro->getMessage());
    }
    $nome = is_array($manifesto) ? ($manifesto['name'] ?? null) : null;

    return is_string($nome) ? $nome : '<sem name>';
}
