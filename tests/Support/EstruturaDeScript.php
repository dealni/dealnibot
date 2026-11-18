<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Lê a ESTRUTURA de um script de produção que não pode ser incluído nos testes
 * (comandos executam código top-level com efeitos reais no include).
 *
 * Serve para travar invariantes que nenhum dublê consegue observar: onde a
 * limpeza de arquivo temporário está declarada e o que o comando tem permissão
 * de chamar.
 *
 * Desde 2026-08-24 o corpo dos comandos de IA vive numa função do funcoes_*.php
 * irmão, e não mais no topo do script. Quem quiser olhar só o fluxo usa
 * corpoDaFuncao() para recortá-lo antes de varrer.
 *
 * A leitura usa o tokenizer do PHP; nenhuma linha do script é executada.
 */
final class EstruturaDeScript
{
    /**
     * Nomes de tudo que o arquivo chama: funções (`nome(`), métodos estáticos
     * (`Classe::nome(`) e instanciações (`new Classe`, devolvida como
     * "new Classe"). Declarações de função e chamadas de método de objeto
     * (`$obj->metodo(`) ficam de fora — o alvo é o que o código executa por
     * conta própria.
     *
     * Com $dentroDaFuncao, varre só o corpo daquela função. É o que o contrato
     * dos comandos de IA precisa desde que o fluxo deles saiu do topo do script
     * para o funcoes_*.php irmão, onde há outras funções em volta: sem o
     * recorte, a lista permitida teria de admitir tudo que os vizinhos chamam e
     * pararia de travar o que quer que fosse.
     *
     * @param string $caminhoArquivo Caminho do arquivo de produção
     * @param string|null $dentroDaFuncao Restringe a varredura a esta função
     * @return array<int, string> Nomes únicos, em ordem alfabética
     */
    public static function funcoesChamadas(string $caminhoArquivo, ?string $dentroDaFuncao = null): array
    {
        $tokens = $dentroDaFuncao === null
            ? self::tokens($caminhoArquivo)
            : token_get_all('<?php ' . self::corpoDaFuncao($caminhoArquivo, $dentroDaFuncao));
        $totalTokens = count($tokens);
        $nomes = [];

        for ($indice = 0; $indice < $totalTokens; $indice++) {
            $token = $tokens[$indice];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NEW) {
                $indiceClasse = self::proximoSignificativo($tokens, $indice + 1);
                $tokenClasse = $indiceClasse === null ? null : $tokens[$indiceClasse];
                if (is_array($tokenClasse) && self::ehNome($tokenClasse[0])) {
                    $nomes[] = 'new ' . $tokenClasse[1];
                }
                continue;
            }

            if (!self::ehNome($token[0])) {
                continue;
            }

            $indiceAbertura = self::proximoSignificativo($tokens, $indice + 1);
            if ($indiceAbertura === null || $tokens[$indiceAbertura] !== '(') {
                continue; // constante, tipo declarado ou nome solto
            }

            $indiceAnterior = self::anteriorSignificativo($tokens, $indice - 1);
            $tokenAnterior = $indiceAnterior === null ? null : $tokens[$indiceAnterior];
            if (is_array($tokenAnterior)
                && in_array(
                    $tokenAnterior[0],
                    [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_NEW],
                    true
                )
            ) {
                continue; // declaração da própria função ou chamada de método
            }

            $nomes[] = $token[1];
        }

        $nomes = array_values(array_unique($nomes));
        sort($nomes);

        return $nomes;
    }

    /**
     * Texto do único bloco `try ... finally` do script, separado em três
     * partes: o corpo do `try`, o corpo do `finally` e todo o código que vem
     * DEPOIS do `finally`.
     *
     * @param string $caminhoArquivo Caminho do script de produção
     * @return array{try: string, finally: string, depois: string}
     */
    public static function blocoTryFinally(string $caminhoArquivo): array
    {
        $tokens = self::tokens($caminhoArquivo);
        $totalTokens = count($tokens);

        $indicesTry = [];
        for ($indice = 0; $indice < $totalTokens; $indice++) {
            $token = $tokens[$indice];
            if (is_array($token) && $token[0] === T_TRY) {
                $indicesTry[] = $indice;
            }
        }
        if (count($indicesTry) !== 1) {
            throw new RuntimeException(
                "Esperado exatamente um try em {$caminhoArquivo}; encontrados " . count($indicesTry) . '.'
            );
        }

        list($inicioTry, $fimTry) = self::delimitarBloco($tokens, $indicesTry[0], $caminhoArquivo);

        $indice = self::proximoSignificativo($tokens, $fimTry + 1);
        while ($indice !== null && is_array($tokens[$indice]) && $tokens[$indice][0] === T_CATCH) {
            list(, $fimCatch) = self::delimitarBloco($tokens, $indice, $caminhoArquivo);
            $indice = self::proximoSignificativo($tokens, $fimCatch + 1);
        }

        if ($indice === null || !is_array($tokens[$indice]) || $tokens[$indice][0] !== T_FINALLY) {
            throw new RuntimeException("O try de {$caminhoArquivo} não tem finally.");
        }

        list($inicioFinally, $fimFinally) = self::delimitarBloco($tokens, $indice, $caminhoArquivo);

        return [
            'try' => self::texto($tokens, $inicioTry + 1, $fimTry - 1),
            'finally' => self::texto($tokens, $inicioFinally + 1, $fimFinally - 1),
            'depois' => self::texto($tokens, $fimFinally + 1, $totalTokens - 1),
        ];
    }

    /**
     * Variante para scripts que possuem mais de um try/finally aninhado. O
     * bloco escolhido é o primeiro cujo corpo contém o trecho informado.
     *
     * @param string $caminhoArquivo
     * @param string $trechoNoTry
     * @return array{try: string, finally: string, depois: string}
     */
    public static function blocoTryFinallyQueContem(string $caminhoArquivo, string $trechoNoTry): array
    {
        $tokens = self::tokens($caminhoArquivo);
        $totalTokens = count($tokens);

        // Procura de trás para frente para escolher o try mais interno quando
        // existe um lock abrangendo um bloco de limpeza menor.
        for ($indiceTry = $totalTokens - 1; $indiceTry >= 0; $indiceTry--) {
            $token = $tokens[$indiceTry];
            if (!is_array($token) || $token[0] !== T_TRY) {
                continue;
            }

            list($inicioTry, $fimTry) = self::delimitarBloco($tokens, $indiceTry, $caminhoArquivo);
            $indice = self::proximoSignificativo($tokens, $fimTry + 1);
            while ($indice !== null && is_array($tokens[$indice]) && $tokens[$indice][0] === T_CATCH) {
                list(, $fimCatch) = self::delimitarBloco($tokens, $indice, $caminhoArquivo);
                $indice = self::proximoSignificativo($tokens, $fimCatch + 1);
            }
            if ($indice === null || !is_array($tokens[$indice]) || $tokens[$indice][0] !== T_FINALLY) {
                continue;
            }

            list($inicioFinally, $fimFinally) = self::delimitarBloco($tokens, $indice, $caminhoArquivo);
            $corpoTry = self::texto($tokens, $inicioTry + 1, $fimTry - 1);
            if (strpos($corpoTry, $trechoNoTry) === false) {
                continue;
            }

            return [
                'try' => $corpoTry,
                'finally' => self::texto($tokens, $inicioFinally + 1, $fimFinally - 1),
                'depois' => self::texto($tokens, $fimFinally + 1, $totalTokens - 1),
            ];
        }

        throw new RuntimeException(
            "Nenhum try/finally contendo '{$trechoNoTry}' foi encontrado em {$caminhoArquivo}."
        );
    }

    /**
     * Recorta o corpo de UMA função do arquivo, sem executar nada.
     *
     * `{` de interpolação (`{$var}` dentro de string) chega como T_CURLY_OPEN e
     * fecha com `}` cru: contar só os literais desbalancearia a conta e cortaria
     * a função no meio da primeira string interpolada.
     */
    public static function corpoDaFuncao(string $caminhoArquivo, string $nome): string
    {
        $tokens = self::tokens($caminhoArquivo);
        $total = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < $total; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $nome) {
                    break;
                }

                $profundidade = 0;
                $corpo = '';
                for ($p = $j; $p < $total; $p++) {
                    $token = $tokens[$p];
                    $texto = is_array($token) ? $token[1] : $token;
                    if ($profundidade > 0) {
                        $corpo .= $texto;
                    }
                    if ($token === '{'
                        || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
                    ) {
                        $profundidade++;
                        continue;
                    }
                    if ($token === '}') {
                        $profundidade--;
                        if ($profundidade === 0) {
                            return $corpo;
                        }
                    }
                }
            }
        }

        throw new RuntimeException("Função {$nome} não encontrada em {$caminhoArquivo}.");
    }

    /**
     * @param string $caminhoArquivo
     * @return array<int, array{0: int, 1: string, 2: int}|string>
     */
    private static function tokens(string $caminhoArquivo): array
    {
        $codigo = file_get_contents($caminhoArquivo);
        if ($codigo === false) {
            throw new RuntimeException("Não foi possível ler {$caminhoArquivo}.");
        }

        return token_get_all($codigo);
    }

    /**
     * Índices da primeira `{` a partir da palavra-chave e da `}` que a fecha.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $inicio Índice da palavra-chave (try, catch, finally)
     * @param string $caminhoArquivo
     * @return array{0: int, 1: int}
     */
    private static function delimitarBloco(array $tokens, int $inicio, string $caminhoArquivo): array
    {
        $totalTokens = count($tokens);
        $profundidade = 0;
        $abertura = null;

        for ($indice = $inicio; $indice < $totalTokens; $indice++) {
            $token = $tokens[$indice];

            if ($token === '{'
                || (is_array($token)
                    && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            ) {
                if ($abertura === null) {
                    $abertura = $indice;
                }
                $profundidade++;
                continue;
            }

            if ($token === '}') {
                $profundidade--;
                if ($abertura !== null && $profundidade === 0) {
                    return [$abertura, $indice];
                }
            }
        }

        throw new RuntimeException("Bloco de chaves não fechado em {$caminhoArquivo}.");
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $de
     * @param int $ate
     * @return string
     */
    private static function texto(array $tokens, int $de, int $ate): string
    {
        $texto = '';
        for ($indice = $de; $indice <= $ate; $indice++) {
            if (!isset($tokens[$indice])) {
                break;
            }
            $token = $tokens[$indice];
            $texto .= is_array($token) ? $token[1] : $token;
        }

        return $texto;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $inicio
     * @return int|null
     */
    private static function proximoSignificativo(array $tokens, int $inicio): ?int
    {
        $totalTokens = count($tokens);
        for ($indice = $inicio; $indice < $totalTokens; $indice++) {
            if (!self::ehIgnoravel($tokens[$indice])) {
                return $indice;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $inicio
     * @return int|null
     */
    private static function anteriorSignificativo(array $tokens, int $inicio): ?int
    {
        for ($indice = $inicio; $indice >= 0; $indice--) {
            if (!self::ehIgnoravel($tokens[$indice])) {
                return $indice;
            }
        }

        return null;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     * @return bool
     */
    private static function ehIgnoravel($token): bool
    {
        return is_array($token)
            && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param int $tipo
     * @return bool
     */
    private static function ehNome(int $tipo): bool
    {
        return in_array($tipo, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
    }
}
