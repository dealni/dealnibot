<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require_once dirname(__DIR__, 2) . '/inclusos/banco.php';
require_once dirname(__DIR__, 2) . '/inclusos/funcoes_snapshot_banco.php';
try {
    foreach (array_slice(ConsultaBanco::argumentos(), 1) as $argumento) {
        if ($argumento !== '--rotulo=pre-deploy') {
            throw new RuntimeException('Configure DEALNI_SNAPSHOTS_DIR e DEALNI_SNAPSHOTS_MANTER no ambiente.');
        }
    }
    $destino = getenv('DEALNI_SNAPSHOTS_DIR');
    $raiz = realpath(dirname(__DIR__, 2));
    if (!is_string($destino) || !str_starts_with($destino, '/') || caminhoSnapshotResolvido($destino) === null
        || str_starts_with(caminhoSnapshotResolvido($destino) . '/', $raiz . '/')) {
        throw new RuntimeException('Diretório de backup deve ser absoluto e externo ao checkout.');
    }
    // Prefixo e retenção próprios: o backup do deploy não entra na conta do
    // DEALNI_SNAPSHOTS_MANTER nem encurta a semana de snapshots diários.
    $resultado = executarSnapshotBanco(dsnBancoDoBot(), $destino, BACKUPS_PRE_DEPLOY_A_MANTER, null, PREFIXO_SNAPSHOT_PRE_DEPLOY);
    echo $resultado['arquivo'] . PHP_EOL;
} catch (Throwable $erro) {
    fwrite(STDERR, $erro->getMessage() . PHP_EOL);
    exit(1);
}
