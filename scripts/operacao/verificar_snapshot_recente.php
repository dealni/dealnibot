<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__, 2) . '/inclusos/caminhos.php';
require_once dirname(__DIR__, 2) . '/inclusos/funcoes_snapshot_banco.php';
require_once dirname(__DIR__, 2) . '/inclusos/funcoes_snapshot_recencia.php';

// Só lê dumps locais e executa pg_restore --file=/dev/null: sem conexão ao
// banco, carregamento de tokens, geração de backup ou envio de alertas.
$diretorio = getenv(VARIAVEL_DO_DIRETORIO_DE_SNAPSHOTS);
if (!is_string($diretorio) || !str_starts_with($diretorio, '/')) {
    fwrite(STDERR, "Diretório absoluto de snapshots não configurado.\n");
    exit(2);
}
if (localizarSnapshotDiarioRecente($diretorio, time()) === null) {
    fwrite(STDERR, "Nenhum snapshot diário íntegro com até 36 horas; verifique o agendamento do backup.\n");
    exit(1);
}
fwrite(STDOUT, "Snapshot diário íntegro com até 36 horas.\n");
