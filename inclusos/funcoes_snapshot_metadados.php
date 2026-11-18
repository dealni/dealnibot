<?php

declare(strict_types=1);

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/** O `bot` que o manifesto de snapshot precisa declarar para ser deste checkout. */
const NOME_DO_BOT_NO_MANIFESTO = 'dealnibot';
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

/**
 * Leitura e validação dos sidecars de um snapshot publicado.
 *
 * Este apoio fica separado da rotina de criação/rotação para manter cada
 * decisão pequena e evitar que a função pública de integridade vire um
 * acumulador de regras.
 */

/**
 * @return array{hash: string, bytes: int, manifesto: array<int|string, mixed>}|null
 */
function lerMetadadosSnapshotBanco(string $arquivo): ?array
{
    $sha256Arquivo = $arquivo . '.sha256';
    $manifestoArquivo = $arquivo . '.json';
    foreach ([$sha256Arquivo, $manifestoArquivo] as $sidecar) {
        if (caminhoFinalSnapshotEhSymlink($sidecar) || !is_file($sidecar) || !is_readable($sidecar)) {
            return null;
        }
    }

    $bytes = filesize($arquivo);
    $hash = hash_file('sha256', $arquivo);
    if (!is_int($bytes) || !is_string($hash)) {
        return null;
    }

    $conteudoSha256 = @file_get_contents($sha256Arquivo);
    if (!is_string($conteudoSha256)) {
        return null;
    }
    $hashSidecar = hashDoSidecarSnapshot($conteudoSha256, basename($arquivo));
    if (!is_string($hashSidecar) || !hash_equals(strtolower($hash), strtolower($hashSidecar))) {
        return null;
    }

    try {
        $manifesto = json_decode((string) file_get_contents($manifestoArquivo), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($manifesto)) {
        return null;
    }

    return ['hash' => $hash, 'bytes' => $bytes, 'manifesto' => $manifesto];
}

function hashDoSidecarSnapshot(string $conteudo, string $nomeArquivo): ?string
{
    $linhas = preg_split('/\R/', trim($conteudo), -1, PREG_SPLIT_NO_EMPTY);
    if ($linhas === false || count($linhas) !== 1) {
        return null;
    }
    $partes = preg_split('/\s+/', trim($linhas[0]), 2);
    if ($partes === false || count($partes) !== 2 ||
        preg_match('/^[0-9a-fA-F]{64}$/', $partes[0]) !== 1 || $partes[1] !== $nomeArquivo) {
        return null;
    }

    return $partes[0];
}

/**
 * @param array<int|string, mixed> $manifesto
 */
function manifestoSnapshotBancoConfere(array $manifesto, string $nomeArquivo, string $hash, int $bytes): bool
{
    return manifestoSnapshotBancoCamposDoArquivoConferem($manifesto, $nomeArquivo, $hash, $bytes) &&
        manifestoSnapshotBancoIdentidadeConfere($manifesto);
}

/**
 * @param array<int|string, mixed> $manifesto
 */
function manifestoSnapshotBancoCamposDoArquivoConferem(array $manifesto, string $nomeArquivo, string $hash, int $bytes): bool
{
    return ($manifesto['arquivo'] ?? null) === $nomeArquivo &&
        ($manifesto['bytes'] ?? null) === $bytes &&
        ($manifesto['sha256'] ?? null) === $hash;
}

/**
 * @param array<int|string, mixed> $manifesto
 */
function manifestoSnapshotBancoIdentidadeConfere(array $manifesto): bool
{
    return ($manifesto['bot'] ?? null) === NOME_DO_BOT_NO_MANIFESTO &&
        ($manifesto['integridade'] ?? null) === 'ok' &&
        ($manifesto['formato'] ?? null) === 'postgresql pg_dump custom' &&
        ($manifesto['cifrado'] ?? null) === false &&
        (!array_key_exists('gpg_recipient', $manifesto) || $manifesto['gpg_recipient'] === null);
}
