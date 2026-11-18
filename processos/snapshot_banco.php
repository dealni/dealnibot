<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inclusos/banco.php';
require_once __DIR__ . '/../inclusos/funcoes_snapshot_banco.php';

// `forcar`: o snapshot não carrega dados_bot.php (LOGS_ATIVO), e o desfecho
// precisa chegar ao canal `snapshot_banco` do app.log mesmo assim.
exit(rodarSnapshotDiarioBanco(
    static fn (): array => executarSnapshotBanco(dsnBancoDoBot(), caminhoSnapshotsDoBot(), quantosSnapshotsManter()),
    \App\Support\LoggerFactory::channel('snapshot_banco', true),
    STDOUT,
    STDERR,
));
