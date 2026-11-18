<?php

declare(strict_types=1);

require_once __DIR__ . '/funcoes_duracao.php';

/** Prefixo do snapshot diário do cron: é o único que a rotação diária conta. */
const PREFIXO_SNAPSHOT_DIARIO = 'bot';
/** Prefixo do backup do deploy, com retenção própria (BACKUPS_PRE_DEPLOY_A_MANTER). */
const PREFIXO_SNAPSHOT_PRE_DEPLOY = 'pre-deploy';

function nomeDoSnapshotBanco(int $instante, string $prefixo = PREFIXO_SNAPSHOT_DIARIO): string
{
    return $prefixo . '-' . gmdate('Y-m-d-His', $instante) . '.dump';
}

function ehArquivoDeSnapshotBanco(string $nome, string $prefixo = PREFIXO_SNAPSHOT_DIARIO): bool
{
    return preg_match('/^' . preg_quote($prefixo, '/') . '-\d{4}-\d{2}-\d{2}-\d{6}\.dump$/', $nome) === 1;
}

function caminhoFinalSnapshotEhSymlink(string $caminho): bool
{
    $semBarrasFinais = rtrim($caminho, '/\\');
    if ($semBarrasFinais === '') {
        $semBarrasFinais = DIRECTORY_SEPARATOR;
    }

    return is_link($semBarrasFinais);
}

function caminhoSnapshotResolvido(string $caminho): ?string
{
    if ($caminho === '') {
        return null;
    }
    $partes = explode('/', str_replace('\\', '/', $caminho));
    $prefixo = $partes[0] === '' ? '/' : '';
    foreach ($partes as $parte) {
        if ($parte === '' || $parte === '.') {
            continue;
        }
        if ($parte === '..') {
            return null;
        }
        $prefixo = $prefixo === '/' ? '/' . $parte : ($prefixo === '' ? $parte : $prefixo . '/' . $parte);
        if (file_exists($prefixo) || is_link($prefixo)) {
            $real = realpath($prefixo);
            if (!is_string($real)) {
                return null;
            }
            $prefixo = $real;
        }
    }

    return $prefixo;
}

function caminhoSnapshotEhSeguro(string $caminho): bool
{
    return caminhoSnapshotResolvido($caminho) !== null;
}

require_once __DIR__ . '/funcoes_snapshot_metadados.php';
require_once __DIR__ . '/funcoes_snapshot_rotacao.php';

function snapshotBancoIntegro(string $arquivo): bool
{
    if (caminhoFinalSnapshotEhSymlink($arquivo) || !is_file($arquivo) || !is_readable($arquivo)) {
        return false;
    }
    $arquivoResolvido = caminhoSnapshotResolvido($arquivo);
    if (!is_string($arquivoResolvido)) {
        return false;
    }
    $arquivo = $arquivoResolvido;

    try {
        executarUtilitarioPostgres(['pg_restore', '--file=/dev/null', $arquivo]);
        return snapshotBancoMetadadosConferem($arquivo);
    } catch (Throwable) {
        return false;
    }
}

function snapshotBancoMetadadosConferem(string $arquivo): bool
{
    $metadados = lerMetadadosSnapshotBanco($arquivo);
    if ($metadados === null) {
        return false;
    }

    return manifestoSnapshotBancoConfere(
        $metadados['manifesto'],
        basename($arquivo),
        $metadados['hash'],
        $metadados['bytes'],
    );
}

function gravarMetadadosSnapshotBanco(string $origem, string $arquivo): void
{
    $arquivo = resolverDestinoMetadadosSnapshot($arquivo);
    [$hash, $bytes] = calcularMetadadosSnapshot($arquivo);

    $dados = [
        'bot' => 'dealnibot',
        'arquivo' => basename($arquivo),
        'origem' => $origem,
        'bytes' => $bytes,
        'sha256' => $hash,
        'integridade' => 'ok',
        'cifrado' => false,
        'gerado_em' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'formato' => 'postgresql pg_dump custom',
    ];
    try {
        $json = json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (JsonException $e) {
        throw new RuntimeException("Não foi possível serializar os metadados do snapshot: {$arquivo}", 0, $e);
    }

    $temporarios = [
        $arquivo . '.sha256.tmp-' . getmypid() . '-' . bin2hex(random_bytes(8)),
        $arquivo . '.json.tmp-' . getmypid() . '-' . bin2hex(random_bytes(8)),
    ];
    $finais = [$arquivo . '.sha256', $arquivo . '.json'];
    $finaisPublicados = [];
    try {
        $conteudos = [
            $hash . '  ' . basename($arquivo) . PHP_EOL,
            $json,
        ];
        foreach ($temporarios as $indice => $temporario) {
            publicarArquivoMetadadosSnapshot($arquivo, $temporario, $finais[$indice], $conteudos[$indice]);
            $finaisPublicados[] = $finais[$indice];
            if (!unlink($temporario)) {
                throw new RuntimeException("Não foi possível limpar o temporário dos metadados do snapshot: {$arquivo}");
            }
        }
    } catch (Throwable $e) {
        foreach ($temporarios as $temporario) {
            @unlink($temporario);
        }
        foreach ($finaisPublicados as $final) {
            @unlink($final);
        }
        throw $e;
    }
}

function resolverDestinoMetadadosSnapshot(string $arquivo): string
{
    if (caminhoFinalSnapshotEhSymlink($arquivo) || !is_file($arquivo) || !is_readable($arquivo)) {
        throw new RuntimeException("Snapshot de destino inexistente, ilegível ou symlink: {$arquivo}");
    }
    $arquivoResolvido = caminhoSnapshotResolvido($arquivo);
    if (!is_string($arquivoResolvido)) {
        throw new RuntimeException("Snapshot de destino não pôde ser resolvido com segurança: {$arquivo}");
    }
    return $arquivoResolvido;
}

/** @return array{string, int} */
function calcularMetadadosSnapshot(string $arquivo): array
{
    $hash = hash_file('sha256', $arquivo);
    $bytes = filesize($arquivo);
    if (!is_string($hash) || !is_int($bytes)) {
        throw new RuntimeException("Não foi possível calcular os metadados do snapshot: {$arquivo}");
    }

    return [$hash, $bytes];
}

function publicarArquivoMetadadosSnapshot(string $arquivo, string $temporario, string $final, string $conteudo): void
{
    if (file_exists($temporario) || is_link($temporario)) {
        throw new RuntimeException("Temporário de metadados do snapshot já existe: {$arquivo}");
    }
    $arquivoTemporario = @fopen($temporario, 'x+b');
    if ($arquivoTemporario === false) {
        throw new RuntimeException("Não foi possível preparar os metadados do snapshot: {$arquivo}");
    }
    $escrito = fwrite($arquivoTemporario, $conteudo);
    $fechado = fclose($arquivoTemporario);
    if ($escrito !== strlen($conteudo) || !$fechado || !chmod($temporario, 0o600)) {
        throw new RuntimeException("Não foi possível preparar os metadados do snapshot: {$arquivo}");
    }
    if (file_exists($final) || is_link($final) || !link($temporario, $final)) {
        throw new RuntimeException("Metadados do snapshot já existem ou não puderam ser publicados: {$arquivo}");
    }
}

/** Exportação consistente em formato custom, publicada sem sobrescrever destinos. */
function gravarSnapshotBanco(string $origem, string $destino): string
{
    $resolvido = caminhoSnapshotResolvido($destino);
    if ($resolvido === null || caminhoFinalSnapshotEhSymlink($destino) || file_exists($destino)) {
        throw new RuntimeException('Destino de snapshot ausente, existente ou inseguro.');
    }
    $destino = $resolvido;
    $temporario = $destino . '.tmp-' . bin2hex(random_bytes(12));
    $handle = fopen($temporario, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível reservar o snapshot.');
    }
    fclose($handle);
    if (!chmod($temporario, 0600)) {
        @unlink($temporario);
        throw new RuntimeException('Não foi possível restringir as permissões do snapshot.');
    }
    $publicado = false;
    try {
        $comandoDump = ['pg_dump', '--format=custom', '--lock-wait-timeout=30s', '--file=' . $temporario];
        if (preg_match('/;options=--search_path=(teste_[a-f0-9]{32})$/', $origem, $schemaTeste) === 1) {
            $comandoDump[] = '--schema=' . $schemaTeste[1];
        }
        executarUtilitarioPostgres($comandoDump, $origem);
        executarUtilitarioPostgres(['pg_restore', '--file=/dev/null', $temporario]);
        if (!link($temporario, $destino)) {
            throw new RuntimeException('Não foi possível publicar o snapshot.');
        }
        $publicado = true;
        // Credenciais e DSN não são publicados no manifesto.
        gravarMetadadosSnapshotBanco('postgresql', $destino);
    } catch (Throwable $erro) {
        if ($publicado) {
            @unlink($destino);
        }
        throw $erro;
    } finally {
        @unlink($temporario);
    }
    return $destino;
}

/** Converte apenas parâmetros públicos do DSN; senhas seguem pelo ambiente privado. */
function parametrosLibpqDoBot(string $dsn): string
{
    if (!str_starts_with($dsn, 'pgsql:')) {
        throw new RuntimeException('Backup exige um DSN PostgreSQL.');
    }
    $parametros = [];
    foreach (explode(';', substr($dsn, 6)) as $parametro) {
        [$chave, $valor] = array_pad(explode('=', $parametro, 2), 2, '');
        if (!in_array($chave, ['host', 'port', 'dbname', 'sslmode', 'connect_timeout', 'options'], true)) {
            throw new RuntimeException('Parâmetro PostgreSQL não permitido no backup.');
        }
        $parametros[] = $chave . "='" . str_replace(["\\", "'"], ["\\\\", "\\'"], $valor) . "'";
    }
    return implode(' ', $parametros);
}

/** @param list<string> $comando */
function executarUtilitarioPostgres(array $comando, ?string $dsn = null): void
{
    $ambiente = getenv();
    if ($dsn !== null) {
        unset($ambiente['PGDATABASE']);
        $comando = array_values(array_filter($comando, static fn (string $arg): bool => !str_starts_with($arg, '--dbname=')));
        $comando[] = '--dbname=' . parametrosLibpqDoBot($dsn);
        $usuario = getenv('DEALNI_DB_USER');
        if (!is_string($usuario) || trim($usuario) === '') {
            throw new RuntimeException('Defina DEALNI_DB_USER para o cliente PostgreSQL.');
        }
        $ambiente['PGUSER'] = $usuario;
        $senha = getenv('DEALNI_DB_PASSWORD');
        $ambiente['PGPASSWORD'] = is_string($senha) ? $senha : '';
        $ambiente['PGCONNECT_TIMEOUT'] = '5';
    }
    $processo = proc_open($comando, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $ambiente);
    if (!is_resource($processo) || proc_close($processo) !== 0) {
        throw new RuntimeException('Utilitário PostgreSQL falhou: ' . ($comando[0] ?? 'desconhecido'));
    }
}

/**
 * @param (callable(): int)|null $agora
 * @param string $prefixo PREFIXO_SNAPSHOT_DIARIO ou PREFIXO_SNAPSHOT_PRE_DEPLOY; a rotação só conta os do mesmo prefixo
 * @return array{arquivo: string, bytes: int, removidos: list<string>}
 */
function executarSnapshotBanco(
    string $origem,
    string $diretorio,
    int $manter,
    ?callable $agora = null,
    string $prefixo = PREFIXO_SNAPSHOT_DIARIO
): array {
    $diretorioResolvido = caminhoSnapshotResolvido($diretorio);
    if (caminhoFinalSnapshotEhSymlink($diretorio) || !is_string($diretorioResolvido)) {
        throw new RuntimeException("Diretório de snapshots não pode ser um symlink: {$diretorio}");
    }
    $diretorio = $diretorioResolvido;
    if (!is_dir($diretorio) && !@mkdir($diretorio, 0o750, true) && !is_dir($diretorio)) {
        throw new RuntimeException("Não foi possível criar o diretório de snapshots: {$diretorio}");
    }
    if (!@chmod($diretorio, 0o700)) {
        throw new RuntimeException("Não foi possível restringir o diretório de snapshots para 0700: {$diretorio}");
    }
    $permissoesDiretorio = fileperms($diretorio);
    if (!is_int($permissoesDiretorio) || ($permissoesDiretorio & 0777) !== 0700) {
        throw new RuntimeException("O diretório de snapshots não ficou restrito a 0700: {$diretorio}");
    }

    $instante = $agora === null ? time() : $agora();
    $destino = rtrim($diretorio, '/') . '/' . nomeDoSnapshotBanco($instante, $prefixo);

    $arquivo = gravarSnapshotBanco($origem, $destino);
    $bytes = filesize($arquivo);

    return [
        'arquivo' => $arquivo,
        'bytes' => $bytes === false ? 0 : $bytes,
        'removidos' => rotacionarSnapshotsBanco($diretorio, $manter, $prefixo),
    ];
}

/**
 * O job diário: roda o snapshot e registra o desfecho no canal
 * `snapshot_banco`, que é onde o runbook confere a rotina. O caminho do arquivo
 * continua no STDOUT (descartado pelo cron) e a falha, no STDERR, que o
 * avisar_se_falhar.sh leva ao Telegram. O snapshot chega como closure para que
 * também a leitura do ambiente (DSN, diretório) caia no mesmo registro.
 * Sucesso e falha levam `duration_ms`, medido no relógio monotônico.
 *
 * @param callable(): array{arquivo: string, bytes: int, removidos: list<string>} $snapshot
 * @param resource $saida
 * @param resource $erros
 * @param (callable(): (int|float))|null $relogioNs hrtime(true) injetável para os testes
 * @return int código de saída do job
 */
function rodarSnapshotDiarioBanco(
    callable $snapshot,
    \Psr\Log\LoggerInterface $log,
    $saida,
    $erros,
    ?callable $relogioNs = null
): int {
    $relogio = $relogioNs ?? static fn (): int|float => hrtime(true);
    $inicio = $relogio();
    try {
        $resultado = $snapshot();
    } catch (Throwable $erro) {
        $log->error('Snapshot PostgreSQL falhou', [
            'err' => ['class' => $erro::class, 'message' => 'Falha ao executar snapshot PostgreSQL.'],
            'duration_ms' => duracaoEmMilissegundos($inicio, $relogio()),
        ]);
        fwrite($erros, 'Falha ao executar snapshot PostgreSQL.' . PHP_EOL);

        return 1;
    }
    $log->info('Snapshot PostgreSQL concluído', [
        'arquivo' => basename($resultado['arquivo']),
        'bytes' => $resultado['bytes'],
        'removidos' => $resultado['removidos'],
        'duration_ms' => duracaoEmMilissegundos($inicio, $relogio()),
    ]);
    fwrite($saida, 'Snapshot PostgreSQL: ' . $resultado['arquivo'] . PHP_EOL);

    return 0;
}
