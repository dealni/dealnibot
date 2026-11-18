<?php

declare(strict_types=1);

namespace Quality;

use UnexpectedValueException;

require_once __DIR__ . '/check_tamanho.php';

const LIMITE_LINHAS_FUNCAO = 150;

/**
 * Linhas efetivas: tokens de código, inclusive literais multilinha; comentários
 * e linhas vazias não contam. A assinatura e as chaves pertencem à função.
 *
 * @param list<array{int, string, int}|string> $tokens
 */
function linhasEfetivasDaFuncao(array $tokens, int $inicio, int $fim): int
{
    $linhas = [];
    $linha = 1;
    foreach ($tokens as $i => $token) {
        $texto = is_array($token) ? $token[1] : $token;
        if ($i >= $inicio && $i <= $fim
            && !(is_array($token) && in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_OPEN_TAG, \T_CLOSE_TAG], true))) {
            foreach (explode("\n", $texto) as $deslocamento => $parte) {
                if (trim($parte) !== '') {
                    $linhas[$linha + $deslocamento] = true;
                }
            }
        }
        $linha += substr_count($texto, "\n");
    }

    return count($linhas);
}

/**
 * Identidade lexical sem linha nem corpo: o nome e o escopo, ou a assinatura
 * e a chamada/atribuição que recebe um callback. Ambiguidade acima do limite
 * reprova, em vez de compartilhar a exceção de outra função.
 *
 * @param list<array{int, string, int}|string> $tokens
 */
function ancoraDeFuncao(array $tokens, int $inicio, int $corpo): string
{
    $partes = [];
    for ($i = $inicio - 1; $i >= 0 && count($partes) < 24; $i--) {
        $token = $tokens[$i];
        if (in_array($token, [';', '{', '}'], true)) {
            break;
        }
        if (!is_array($token) || !in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_OPEN_TAG, \T_CLOSE_TAG], true)) {
            array_unshift($partes, is_array($token) ? $token[1] : $token);
        }
    }
    for ($i = $inicio; $i <= $corpo; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || !in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_OPEN_TAG, \T_CLOSE_TAG], true)) {
            $partes[] = is_array($token) ? $token[1] : $token;
        }
    }

    return 'callback:' . substr(hash('sha256', implode('', $partes)), 0, 20);
}


/**
 * Inclui modificadores e atributos da declaração, mesmo em linhas separadas.
 *
 * @param list<array{int, string, int}|string> $tokens
 * @param array<int, int> $pares
 */
function inicioDaDeclaracaoDeFuncao(array $tokens, array $pares, int $indice): int
{
    $fechamentos = array_flip($pares);
    $inicio = $indice;
    for ($i = $indice - 1; $i >= 0; $i--) {
        $token = $tokens[$i];
        if (is_array($token) && in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
            continue;
        }
        if (is_array($token) && in_array($token[0], [\T_PUBLIC, \T_PROTECTED, \T_PRIVATE, \T_STATIC, \T_FINAL, \T_ABSTRACT, \T_READONLY], true)) {
            $inicio = $i;
            continue;
        }
        $abertura = $fechamentos[$i] ?? null;
        if ($token === ']' && $abertura !== null && is_array($tokens[$abertura]) && $tokens[$abertura][0] === \T_ATTRIBUTE) {
            $inicio = $abertura;
            $i = $abertura;
            continue;
        }
        break;
    }

    return $inicio;
}

/**
 * @return array<string, non-empty-list<int>>
 */
function tamanhosDasFuncoes(string $codigo): array
{
    try {
        $tokens = token_get_all($codigo, TOKEN_PARSE);
    } catch (\ParseError) {
        throw new UnexpectedValueException('Código PHP inválido no gate de linhas de função.');
    }
    $pares = paresDeDelimitadores($tokens);
    $escopos = [];
    $resultado = [];
    $total = count($tokens);
    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || !in_array($token[0], [\T_FUNCTION, \T_FN, \T_CLASS, \T_TRAIT, \T_INTERFACE, \T_ENUM], true)) {
            continue;
        }
        if ($token[0] === \T_CLASS) {
            $anterior = $i - 1;
            while ($anterior >= 0 && is_array($tokens[$anterior])
                && in_array($tokens[$anterior][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_OPEN_TAG, \T_CLOSE_TAG], true)) {
                $anterior--;
            }
            if ($anterior >= 0 && is_array($tokens[$anterior]) && $tokens[$anterior][0] === \T_DOUBLE_COLON) {
                continue;
            }
        }
        $funcao = in_array($token[0], [\T_FUNCTION, \T_FN], true);
        $nome = '';
        for ($j = $i + 1; $j < $total; $j++) {
            $parte = $tokens[$j];
            if (is_array($parte) && in_array($parte[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            if ((is_array($parte) ? $parte[1] : $parte) === '&') {
                continue;
            }
            if (is_array($parte) && $parte[0] === \T_STRING) {
                $nome = $parte[1];
            }
            break;
        }
        $inicio = null;
        $fim = $total - 1;
        for ($j = $i + 1; $j < $total; $j++) {
            if (isset($pares[$j])) {
                if ($tokens[$j] === '{') {
                    $inicio = $j;
                    $fim = $pares[$j];
                    break;
                }
                $j = $pares[$j];
            } elseif ($token[0] === \T_FN && is_array($tokens[$j]) && $tokens[$j][0] === \T_DOUBLE_ARROW) {
                $inicio = $j;
                $fim = fimDaArrow($tokens, $pares, $j + 1) - 1;
                break;
            } elseif (in_array($tokens[$j], [';', ')'], true)) {
                break;
            }
        }
        if ($inicio === null) {
            continue;
        }
        if ($nome === '' || $token[0] === \T_FN) {
            $nome = ancoraDeFuncao($tokens, $i, $inicio);
        }
        $pais = [];
        foreach ($escopos as $escopo) {
            if ($escopo['inicio'] < $i && $escopo['fim'] > $i) {
                $pais[] = $escopo['nome'];
            }
        }
        $identidade = implode('/', [...$pais, $nome]);
        if ($funcao) {
            $inicioDaDeclaracao = inicioDaDeclaracaoDeFuncao($tokens, $pares, $i);
            $resultado[$identidade][] = linhasEfetivasDaFuncao($tokens, $inicioDaDeclaracao, $fim);
        }
        $escopos[] = ['inicio' => $inicio, 'fim' => $fim, 'nome' => $nome];
    }
    ksort($resultado);

    return $resultado;
}

/**
 * @return array{arquivos_medidos: int, excecoes: array<string, int>}
 */
function medirLinhasDeFuncao(string $raiz): array
{
    $arquivos = arquivosDoProduto($raiz);
    $excecoes = [];
    foreach ($arquivos as $arquivo) {
        $codigo = file_get_contents($raiz . '/' . $arquivo);
        if (!is_string($codigo)) {
            throw new UnexpectedValueException('Não foi possível medir ' . $arquivo . '.');
        }
        foreach (tamanhosDasFuncoes($codigo) as $funcao => $tamanhos) {
            $maior = max($tamanhos);
            $chave = $arquivo . '::' . $funcao;
            if ($maior > LIMITE_LINHAS_FUNCAO && count($tamanhos) > 1) {
                throw new UnexpectedValueException('Identidade de função ambígua: ' . $chave . '. Nomeie os callbacks.');
            }
            if ($maior > LIMITE_LINHAS_FUNCAO) {
                $excecoes[$chave] = $maior;
            }
        }
    }

    return ['arquivos_medidos' => count($arquivos), 'excecoes' => $excecoes];
}

/**
 * @param list<string> $argumentos
 * @param resource|null $erro
 */
function mainLinhasDeFuncao(array $argumentos, $erro = null): int
{
    $erro ??= STDERR;
    $raiz = dirname(__DIR__, 2);
    $atualizar = false;
    foreach (array_slice($argumentos, 1) as $opcao) {
        if ($opcao === '--atualizar-baseline') {
            $atualizar = true;
        } elseif (str_starts_with($opcao, '--raiz=')) {
            $raiz = rtrim(substr($opcao, 7), '/');
        } else {
            throw new UnexpectedValueException('Opção inválida no gate de linhas de função.');
        }
    }
    $somenteLeitura = baselineEhSomenteLeitura();
    if ($atualizar && $somenteLeitura) {
        throw new UnexpectedValueException('No CI o baseline de funções é somente leitura.');
    }
    $medicao = medirLinhasDeFuncao($raiz);
    if ($medicao['arquivos_medidos'] === 0) {
        throw new UnexpectedValueException('Nenhum arquivo do produto encontrado.');
    }
    printf("Funções: %d acima do limite de 150 linhas efetivas.\n", count($medicao['excecoes']));
    $registrada = lerBaseline()['linhas_de_funcao'] ?? null;
    if (!$atualizar) {
        if (!is_array($registrada) || !is_array($registrada['excecoes'] ?? null)) {
            throw new UnexpectedValueException('Baseline de linhas de função obrigatório; use composer complexity:baseline.');
        }
        if (array_key_exists('objetivo', $registrada)
            || ($registrada['limite'] ?? null) !== LIMITE_LINHAS_FUNCAO) {
            throw new UnexpectedValueException('Limiares incompatíveis no baseline de linhas de função.');
        }
        foreach ($registrada['excecoes'] as $identidade => $cap) {
            if (!is_string($identidade) || !is_int($cap) || $cap <= LIMITE_LINHAS_FUNCAO) {
                throw new UnexpectedValueException('Exceção inválida no baseline de linhas de função.');
            }
        }
        $piso = numeroDoBaseline($registrada, 'arquivos_medidos')['inteiro'];
        if (escopoEncolheu($medicao['arquivos_medidos'], $piso)) {
            throw new UnexpectedValueException('O escopo de linhas de função encolheu.');
        }
        $veredito = compararDivida($medicao['excecoes'], $registrada['excecoes'], 'Função em linhas efetivas', LIMITE_LINHAS_FUNCAO);
        if ($veredito['problemas'] !== []) {
            fwrite($erro, implode("\n", $veredito['problemas']) . "\n");

            return 1;
        }
        if (!$veredito['melhorou'] || $somenteLeitura) {
            return 0;
        }
    }
    gravarSecaoDoBaseline('linhas_de_funcao', [
        'limite' => LIMITE_LINHAS_FUNCAO,
        'arquivos_medidos' => $medicao['arquivos_medidos'],
        'excecoes' => $medicao['excecoes'],
    ]);

    return 0;
}

if (executadoDiretamente(__FILE__)) {
    try {
        $brutos = $_SERVER['argv'] ?? [];
        if (!is_array($brutos)) {
            throw new UnexpectedValueException('Argumentos CLI inválidos.');
        }
        $argumentos = [];
        foreach ($brutos as $argumento) {
            if (!is_string($argumento)) {
                throw new UnexpectedValueException('Argumento CLI inválido.');
            }
            $argumentos[] = $argumento;
        }
        exit(mainLinhasDeFuncao($argumentos));
    } catch (UnexpectedValueException $erro) {
        fwrite(STDERR, $erro->getMessage() . "\n");
        exit(2);
    }
}
