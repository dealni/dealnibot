<?php

declare(strict_types=1);

/**
 * Enumeração e rotação dos snapshots locais.
 *
 * Um nome `.dump` só é considerado publicado quando os dois sidecars também
 * existem e conferem. Uma interrupção entre a publicação do banco e dos
 * sidecars deixa uma publicação incompleta; a rotação a ignora e a mantém
 * para diagnóstico, em vez de tentar removê-la por caminho. Snapshots sem
 * metadados completos não entram na rotação.
 */

/**
 * Arquivos regulares com nome reservado à rotina, publicados ou não.
 *
 * @return list<string> Nomes de arquivo, sem o diretório
 */
function listarCandidatosSnapshotsBanco(string $diretorio, string $prefixo = PREFIXO_SNAPSHOT_DIARIO): array
{
    $diretorioResolvido = caminhoSnapshotResolvido($diretorio);
    if (caminhoFinalSnapshotEhSymlink($diretorio) || !is_string($diretorioResolvido)) {
        return [];
    }
    $diretorio = $diretorioResolvido;
    if (!is_dir($diretorio) || !is_readable($diretorio)) {
        return [];
    }
    $entradas = @scandir($diretorio);
    if ($entradas === false) {
        return [];
    }

    $candidatos = [];
    foreach ($entradas as $entrada) {
        $arquivo = $diretorio . '/' . $entrada;
        if (ehArquivoDeSnapshotBanco($entrada, $prefixo) && !is_link($arquivo) && is_file($arquivo)) {
            $candidatos[] = $entrada;
        }
    }

    sort($candidatos);

    return $candidatos;
}

/**
 * Snapshots publicados, do mais antigo para o mais novo. A validação dos
 * sidecars confere hash, nome, tamanho, formato e identidade, sem abrir o
 * arquivo PostgreSQL; publicações incompletas ou incoerentes ficam fora da retenção.
 *
 * @return list<string> Nomes de arquivo, sem o diretório
 */
function listarSnapshotsBanco(string $diretorio, string $prefixo = PREFIXO_SNAPSHOT_DIARIO): array
{
    $snapshots = [];
    foreach (listarCandidatosSnapshotsBanco($diretorio, $prefixo) as $nome) {
        if (snapshotBancoMetadadosConferem($diretorio . '/' . $nome)) {
            $snapshots[] = $nome;
        }
    }

    return $snapshots;
}


/**
 * @param list<string> $snapshots Publicações completas do prefixo diário.
 * @return list<string>
 */
function snapshotsDiariosExpirados(string $diretorio, array $snapshots, int $manter, int $agora): array
{
    $instantes = [];
    foreach ($snapshots as $nome) {
        $data = substr($nome, strlen(PREFIXO_SNAPSHOT_DIARIO) + 1, 17);
        $instante = DateTimeImmutable::createFromFormat('!Y-m-d-His', $data, new DateTimeZone('UTC'));
        clearstatcache(true, $diretorio . '/' . $nome);
        $mtime = @filemtime($diretorio . '/' . $nome);
        if ($instante === false || $instante->format('Y-m-d-His') !== $data || $mtime === false || $mtime <= 0) {
            continue;
        }
        // Restaurar/copiar um dump antigo nunca autoriza apagá-lo imediatamente.
        $idadeDesde = max($instante->getTimestamp(), $mtime);
        if ($idadeDesde > $agora) {
            continue;
        }
        $instantes[$nome] = $idadeDesde;
    }
    asort($instantes);
    $candidatos = array_slice(array_keys($instantes), 0, max(0, count($instantes) - 3));
    $limite = $agora - $manter * 86400;
    return array_values(array_filter($candidatos, static fn (string $nome): bool => $instantes[$nome] < $limite));
}

/** Remove apenas um dump regular seguro e seus sidecars após excluir o dump. */
function removerSnapshotPublicado(string $arquivo): bool
{
    if (caminhoFinalSnapshotEhSymlink($arquivo) || !caminhoSnapshotEhSeguro($arquivo) || is_link($arquivo) || !is_file($arquivo)) {
        return false;
    }
    if (!@unlink($arquivo)) {
        return false;
    }
    @unlink($arquivo . '.sha256');
    @unlink($arquivo . '.json');

    return true;
}

/**
 * Só conta os dumps do mesmo prefixo: o backup de pré-deploy não empurra o
 * diário para fora, nem o contrário. Diários cobrem dias corridos e preservam
 * pelo menos três publicações completas, inclusive durante falhas prolongadas.
 * Pré-deploy continua com retenção por quantidade.
 *
 * @return list<string> Nomes removidos; somente dumps com metadados completos.
 */
function rotacionarSnapshotsBanco(string $diretorio, int $manter, string $prefixo = PREFIXO_SNAPSHOT_DIARIO, ?int $agora = null): array
{
    if ($manter < 1) {
        throw new RuntimeException('A rotação precisa manter pelo menos 1 snapshot.');
    }
    $diretorioResolvido = caminhoSnapshotResolvido($diretorio);
    if (caminhoFinalSnapshotEhSymlink($diretorio) || !is_string($diretorioResolvido)) {
        return [];
    }
    $diretorio = $diretorioResolvido;
    if (!is_dir($diretorio) || !is_readable($diretorio)) {
        return [];
    }

    $removidos = [];
    $snapshots = listarSnapshotsBanco($diretorio, $prefixo);
    $candidatos = array_slice($snapshots, 0, max(0, count($snapshots) - $manter));
    if ($prefixo === PREFIXO_SNAPSHOT_DIARIO) {
        $candidatos = snapshotsDiariosExpirados($diretorio, $snapshots, $manter, $agora ?? time());
    }
    foreach ($candidatos as $nome) {
        $arquivo = $diretorio . '/' . $nome;
        if (removerSnapshotPublicado($arquivo)) {
            $removidos[] = $nome;
        }
    }

    sort($removidos);

    return $removidos;
}
