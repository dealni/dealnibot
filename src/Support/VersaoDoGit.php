<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Versão do código lida do metadado Git COMO ARQUIVO, sem subprocesso.
 *
 * É o substituto do carimbo de publicação fora do host publicado: `.git` pode
 * ser um diretório ou o ponteiro de um linked worktree (`gitdir:`), a ref
 * apontada pelo HEAD pode viver solta ou em `packed-refs` (no gitdir comum),
 * e nada disso pode custar um fork por evento de log nem depender de o
 * binário `git` existir no PATH do www-data. `ContextoDeExecucao::versao()`
 * é o único chamador.
 */
final class VersaoDoGit
{
    /**
     * Um SHA inteiro como o Git grava em HEAD, numa ref solta ou em
     * `packed-refs`: 40 dígitos hexadecimais (SHA-1) ou 64 (SHA-256).
     */
    private const PADRAO_SHA = '/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/i';

    /**
     * Commit curto do checkout em `$raiz`, ou `null` fora de um checkout.
     */
    public static function commitCurto(string $raiz): ?string
    {
        $gitDir = self::diretorioDoGit($raiz . '/.git');
        if ($gitDir === null) {
            return null;
        }

        $head = @file_get_contents($gitDir . '/HEAD');
        if (!is_string($head)) {
            return null;
        }
        $head = trim($head);

        // HEAD destacado: o próprio arquivo já é o SHA (inteiro: 40 dígitos em
        // SHA-1, 64 em SHA-256; prefixo ou sufixo espúrio não é um SHA).
        if (preg_match(self::PADRAO_SHA, $head) === 1) {
            return substr($head, 0, 7);
        }

        if (preg_match('#^ref:\s*(\S+)$#', $head, $captura) !== 1) {
            return null;
        }
        $ref = $captura[1];

        // HEAD é dado do repositório, mas nunca deve ser confiado como caminho
        // do sistema de arquivos. Refs do Git começam em refs/, e um gitfile
        // malformado não pode fazer esta leitura sair do diretório de
        // metadados do repositório.
        if (!self::refGitValida($ref)) {
            return null;
        }

        // Em um linked worktree o HEAD é privado, mas refs e packed-refs vivem
        // no gitdir comum indicado por `commondir`; só as refs por worktree
        // (`refs/bisect/`, `refs/rewritten/`, `refs/worktree/`) ficam no gitdir
        // privado. Num checkout comum os dois diretórios são o mesmo, então a
        // regra cobre ambos sem chamar Git (este caminho é executado durante
        // cada processo web/CLI).
        $comum = self::diretorioComumDoGit($gitDir);
        $diretorioDaRef = preg_match('#^refs/(?:bisect|rewritten|worktree)/#', $ref) === 1
            ? $gitDir
            : $comum;
        $solto = @file_get_contents($diretorioDaRef . '/' . $ref);
        if (is_string($solto)) {
            $solto = trim($solto);
            if (preg_match(self::PADRAO_SHA, $solto) === 1) {
                return substr($solto, 0, 7);
            }
        }

        return self::doPackedRefs($comum, $ref);
    }

    /**
     * Resolve `.git` tanto como diretório quanto como o ponteiro de um linked
     * worktree (`gitdir: ...`), formato documentado pelo Git.
     */
    private static function diretorioDoGit(string $entrada): ?string
    {
        if (is_dir($entrada)) {
            $resolvido = realpath($entrada);

            return is_string($resolvido) ? $resolvido : null;
        }
        if (!is_file($entrada)) {
            return null;
        }

        $ponteiro = @file_get_contents($entrada);
        if (!is_string($ponteiro) || preg_match('/^gitdir:\s*(.+)$/i', trim($ponteiro), $captura) !== 1) {
            return null;
        }

        $caminho = trim($captura[1]);
        if ($caminho === '') {
            return null;
        }
        if (!self::caminhoAbsoluto($caminho)) {
            $caminho = dirname($entrada) . '/' . $caminho;
        }
        $resolvido = realpath($caminho);

        return is_string($resolvido) && is_dir($resolvido) ? $resolvido : null;
    }

    /**
     * O gitdir privado de um worktree aponta ao gitdir comum por este arquivo;
     * o conteúdo relativo é relativo ao próprio gitdir privado.
     */
    private static function diretorioComumDoGit(string $gitDir): string
    {
        $arquivo = $gitDir . '/commondir';
        if (!is_file($arquivo)) {
            return $gitDir;
        }

        $conteudo = @file_get_contents($arquivo);
        if (!is_string($conteudo) || trim($conteudo) === '') {
            return $gitDir;
        }

        $caminho = trim($conteudo);
        if (!self::caminhoAbsoluto($caminho)) {
            $caminho = $gitDir . '/' . $caminho;
        }
        $resolvido = realpath($caminho);

        return is_string($resolvido) && is_dir($resolvido) ? $resolvido : $gitDir;
    }

    private static function caminhoAbsoluto(string $caminho): bool
    {
        return str_starts_with($caminho, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $caminho) === 1;
    }

    private static function refGitValida(string $ref): bool
    {
        return str_starts_with($ref, 'refs/')
            && !str_contains($ref, '..')
            && !str_contains($ref, '//')
            && !str_contains($ref, '\\')
            && preg_match('/[\x00-\x20\x7f]/', $ref) !== 1;
    }

    /**
     * Ref empacotada: depois de um `git gc` o arquivo solto some e o SHA passa
     * a viver só em `packed-refs` (no diretório comum em um linked worktree).
     * Sem esta leitura a versão de um checkout compactado viraria
     * `desconhecida` sem motivo.
     */
    private static function doPackedRefs(string $gitDir, string $ref): ?string
    {
        $empacotadas = @file_get_contents($gitDir . '/packed-refs');
        if (!is_string($empacotadas)) {
            return null;
        }

        $padrao = '/^((?:[0-9a-f]{40}|[0-9a-f]{64}))\s+' . preg_quote($ref, '/') . '$/mi';

        return preg_match($padrao, $empacotadas, $captura) === 1
            ? substr($captura[1], 0, 7)
            : null;
    }
}
