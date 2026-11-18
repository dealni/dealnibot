<?php

declare(strict_types=1);

namespace Quality;

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/**
 * O código DO PRODUTO deste bot: diretórios varridos recursivamente e arquivos
 * de topo. Ferramenta (`scripts/`, `database/`) e teste ficam de fora pelo mesmo
 * motivo que já os tira da cobertura — não é código que o bot executa em
 * produção.
 *
 * O escopo é declarado, e não descoberto: varrer a raiz custaria o mesmo, mas
 * arrastaria ferramenta e utilitário para dentro de um ratchet que existe para
 * o produto, e o primeiro script grande viraria dívida registrada.
 */
const ALVOS_DE_TAMANHO = [
    'comandos', 'config', 'inclusos', 'processos', 'src',
];
const ARQUIVOS_DE_TAMANHO = [
    'bot.php', 'bot_web.php', 'health.php', 'index.php', 'ready.php', 'roteador.php',
];
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

require_once __DIR__ . '/baseline_tamanho.php';

use UnexpectedValueException;

/*
 * Ratchet de tamanho de arquivo e de complexidade de função.
 *
 * O limite sozinho não serve: posto no valor de hoje, ele aprova tudo o que já
 * existe e some; posto no valor ideal, reprova o repositório inteiro no dia em
 * que nasce, e a saída vira desligá-lo. Aqui os dois limites são fixos e o que
 * o baseline guarda é a DÍVIDA: quem já passa do limite, com o número de hoje.
 *
 * Daí as três reprovações: infrator novo, infrator que piorou, e escopo que
 * encolheu. Melhora aperta o baseline sozinho — a dívida só anda para baixo, e
 * o arquivo regravado aparece no diff, que é onde a decisão se revisa.
 */

/** Acima disto, o arquivo é dívida registrada; abaixo, é só código. */
const LIMITE_LINHAS = 500;

/**
 * Complexidade ciclomática por função: limiar comum 15; as exceções do
 * legado ficam individuais no baseline e não podem piorar.
 */
const LIMITE_COMPLEXIDADE = 15;

/**
 * Tokens que abrem um caminho de execução. Closure e arrow function contam para
 * a função que as contém, de propósito: mover ramo para dentro de uma closure
 * não é simplificar, é esconder.
 *
 * @return list<int>
 */
function tokensDeDecisao(): array
{
    return [
        \T_IF, \T_ELSEIF, \T_FOR, \T_FOREACH, \T_WHILE, \T_CASE, \T_CATCH,
        \T_BOOLEAN_AND, \T_BOOLEAN_OR, \T_LOGICAL_AND, \T_LOGICAL_OR, \T_COALESCE,
    ];
}

/**
 * Pares de delimitadores, incluindo as chaves da interpolação de strings.
 *
 * @param list<array{int, string, int}|string> $tokens
 * @return array<int, int>
 */
function paresDeDelimitadores(array $tokens): array
{
    $pilha = [];
    $pares = [];
    foreach ($tokens as $i => $token) {
        if (in_array($token, ['(', '[', '{'], true)
            || (is_array($token) && in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES, \T_ATTRIBUTE], true))) {
            $pilha[] = $i;
        } elseif (in_array($token, [')', ']', '}'], true)) {
            $abertura = array_pop($pilha);
            if ($abertura !== null) {
                $pares[$abertura] = $i;
            }
        }
    }

    return $pares;
}

/**
 * A expressão de uma arrow termina no delimitador externo, preservando os
 * ternários próprios. Assim, a arrow no ramo verdadeiro não absorve o falso.
 *
 * @param list<array{int, string, int}|string> $tokens
 * @param array<int, int> $pares
 */
function fimDaArrow(array $tokens, array $pares, int $inicio): int
{
    $ternarios = 0;
    $chaveDeYield = false;
    $total = count($tokens);
    for ($i = $inicio; $i < $total; $i++) {
        if (isset($pares[$i])) {
            $i = $pares[$i];
            continue;
        }
        $token = $tokens[$i];
        if (is_array($token) && $token[0] === \T_FN) {
            $j = $i + 1;
            while ($j < $total) {
                if (isset($pares[$j])) {
                    $j = $pares[$j];
                } elseif (is_array($tokens[$j]) && $tokens[$j][0] === \T_DOUBLE_ARROW) {
                    $i = fimDaArrow($tokens, $pares, $j + 1) - 1;
                    break;
                }
                $j++;
            }
        } elseif (is_array($token) && $token[0] === \T_YIELD) {
            $chaveDeYield = true;
        } elseif (is_array($token) && $token[0] === \T_DOUBLE_ARROW) {
            if (!$chaveDeYield) {
                return $i;
            }
            $chaveDeYield = false;
        } elseif ($token === '?') {
            $ternarios++;
        } elseif ($token === ':') {
            if ($ternarios === 0) {
                return $i;
            }
            $ternarios--;
        } elseif (in_array($token, [',', ';', ')', ']', '}'], true)) {
            return $i;
        }
    }

    return $total;
}

/**
 * Cada condição de match equivale a um case; default não adiciona caminho.
 * Os => de arrays e arrows e as vírgulas das expressões não são braços.
 *
 * @param list<array{int, string, int}|string> $tokens
 * @param array<int, int> $pares
 * @return array<int, int>
 */
function decisoesDeMatch(array $tokens, array $pares): array
{
    $decisoes = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== \T_MATCH) {
            continue;
        }
        $j = $i + 1;
        while (isset($tokens[$j]) && $tokens[$j] !== '(') {
            $j++;
        }
        $j = ($pares[$j] ?? $j) + 1;
        while (isset($tokens[$j]) && $tokens[$j] !== '{') {
            $j++;
        }
        $fim = $pares[$j] ?? $j;
        $condicoes = 1;
        $cabecalho = true;
        $padrao = false;
        for ($j++; $j < $fim; $j++) {
            if (isset($pares[$j])) {
                $j = $pares[$j];
                continue;
            }
            $parte = $tokens[$j];
            if (is_array($parte) && $parte[0] === \T_FN) {
                $arrow = $j + 1;
                while ($arrow < $fim) {
                    if (isset($pares[$arrow])) {
                        $arrow = $pares[$arrow];
                    } elseif (is_array($tokens[$arrow]) && $tokens[$arrow][0] === \T_DOUBLE_ARROW) {
                        $j = fimDaArrow($tokens, $pares, $arrow + 1) - 1;
                        break;
                    }
                    $arrow++;
                }
                continue;
            }
            if (is_array($parte) && $parte[0] === \T_DEFAULT && $cabecalho) {
                $padrao = true;
            } elseif (is_array($parte) && $parte[0] === \T_DOUBLE_ARROW && $cabecalho) {
                $decisoes[$j] = $padrao ? 0 : $condicoes;
                $cabecalho = false;
            } elseif ($parte === ',') {
                if ($cabecalho) {
                    $condicoes++;
                } else {
                    $cabecalho = true;
                    $condicoes = 1;
                    $padrao = false;
                }
            }
        }
    }

    return $decisoes;
}

/**
 * Mede funções, closures e arrows separadamente. As decisões internas também
 * permanecem na unidade continente, sem somar novamente a métrica da filha.
 *
 * @return array<string, int>
 */
function complexidadeDasFuncoes(string $codigo): array
{
    $tokens = token_get_all($codigo);
    $total = count($tokens);
    $pares = paresDeDelimitadores($tokens);
    $match = decisoesDeMatch($tokens, $pares);
    $decisoes = tokensDeDecisao();
    $funcoes = [];
    $unidades = [];
    $assinaturas = [];

    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || !in_array($token[0], [\T_FUNCTION, \T_FN], true)) {
            continue;
        }
        $arrow = $token[0] === \T_FN;
        $nome = ($arrow ? 'arrow@' : 'closure@') . $token[2];
        for ($j = $i + 1; $j < $total; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === \T_STRING && !$arrow) {
                $nome = $tokens[$j][1];
                break;
            }
            if ($tokens[$j] === '(') {
                break;
            }
        }
        $inicio = null;
        $fim = $total;
        for ($j = $i + 1; $j < $total; $j++) {
            if (isset($pares[$j])) {
                if ($tokens[$j] === '{') {
                    $inicio = $j + 1;
                    $fim = $pares[$j];
                    break;
                }
                $j = $pares[$j];
            } elseif ($arrow && is_array($tokens[$j]) && $tokens[$j][0] === \T_DOUBLE_ARROW) {
                $inicio = $j + 1;
                $fim = fimDaArrow($tokens, $pares, $inicio);
                break;
            } elseif ($tokens[$j] === ';') {
                break;
            }
        }
        if ($inicio === null) {
            continue;
        }
        $unidades[] = [$nome, $inicio, $fim];
        for ($j = $i + 1; $j < $inicio; $j++) {
            $assinaturas[$j] = true;
        }
    }
    foreach ($unidades as [$nome, $inicio, $fim]) {
        $complexidade = 1;
        for ($j = $inicio; $j < $fim; $j++) {
            $corpo = $tokens[$j];
            if (($corpo === '?' && !isset($assinaturas[$j])) || (is_array($corpo) && in_array($corpo[0], $decisoes, true))) {
                $complexidade++;
            }
            $complexidade += $match[$j] ?? 0;
        }
        $chave = $nome;
        $repeticao = 1;
        while (isset($funcoes[$chave])) {
            $chave = $nome . '#' . (++$repeticao);
        }
        $funcoes[$chave] = $complexidade;
    }

    return $funcoes;
}

/**
 * Arquivos PHP do produto, relativos à raiz.
 *
 * @return list<string>
 */
function arquivosDoProduto(string $raiz): array
{
    $encontrados = [];
    foreach (ALVOS_DE_TAMANHO as $diretorio) {
        $base = $raiz . '/' . $diretorio;
        if (!is_dir($base)) {
            continue;
        }
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterador as $arquivo) {
            if ($arquivo instanceof \SplFileInfo && $arquivo->isFile() && $arquivo->getExtension() === 'php') {
                $encontrados[] = $diretorio . '/' . substr($arquivo->getPathname(), strlen($base) + 1);
            }
        }
    }
    foreach (ARQUIVOS_DE_TAMANHO as $arquivo) {
        if (is_file($raiz . '/' . $arquivo)) {
            $encontrados[] = $arquivo;
        }
    }
    sort($encontrados);

    return $encontrados;
}

/**
 * @return array{arquivos: int, linhas: array<string, int>, funcoes: array<string, int>}
 */
function medirTamanho(string $raiz): array
{
    $linhas = [];
    $funcoes = [];
    $arquivos = arquivosDoProduto($raiz);

    foreach ($arquivos as $relativo) {
        $codigo = file_get_contents($raiz . '/' . $relativo);
        if (!is_string($codigo)) {
            throw new UnexpectedValueException('Não foi possível medir ' . $relativo . '.');
        }
        $quantidade = substr_count($codigo, "\n") + (str_ends_with($codigo, "\n") ? 0 : 1);
        if ($quantidade > LIMITE_LINHAS) {
            $linhas[$relativo] = $quantidade;
        }
        foreach (complexidadeDasFuncoes($codigo) as $funcao => $complexidade) {
            if ($complexidade > LIMITE_COMPLEXIDADE) {
                $funcoes[$relativo . '::' . $funcao] = $complexidade;
            }
        }
    }

    ksort($linhas);
    ksort($funcoes);

    return ['arquivos' => count($arquivos), 'linhas' => $linhas, 'funcoes' => $funcoes];
}

/**
 * Compara a dívida medida com a registrada.
 *
 * @param array<string, int> $medida
 * @param array<mixed> $registrada
 * @return array{problemas: list<string>, melhorou: bool}
 */
function compararDivida(array $medida, array $registrada, string $rotulo, int $limite): array
{
    $problemas = [];
    foreach ($medida as $alvo => $valor) {
        $referencia = $registrada[$alvo] ?? null;
        if (!is_int($referencia)) {
            $problemas[] = sprintf(
                '%s fora do limite e sem registro: %s tem %d (limite %d). Divida a unidade em '
                . 'vez de registrar dívida nova.',
                $rotulo,
                $alvo,
                $valor,
                $limite,
            );
            continue;
        }
        if ($valor > $referencia) {
            $problemas[] = sprintf(
                '%s piorou: %s foi de %d para %d (limite %d).',
                $rotulo,
                $alvo,
                $referencia,
                $valor,
                $limite,
            );
        }
    }

    $melhorou = false;
    foreach ($registrada as $alvo => $referencia) {
        if (!is_string($alvo) || !is_int($referencia)) {
            throw new UnexpectedValueException(
                'tools/quality/tamanho-baseline.json: dívida de tamanho inválida.',
            );
        }
        $atual = $medida[$alvo] ?? null;
        if ($atual === null || $atual < $referencia) {
            $melhorou = true;
        }
    }

    return ['problemas' => $problemas, 'melhorou' => $melhorou];
}

/**
 * @param list<mixed> $argumentos
 * @param resource|null $erro
 */
function mainTamanho(array $argumentos, $erro = null): int
{
    $erro ??= STDERR;
    $raiz = dirname(__DIR__, 2);
    $atualizar = false;
    foreach (array_slice($argumentos, 1) as $opcao) {
        if ($opcao === '--atualizar-baseline') {
            $atualizar = true;
            continue;
        }
        if (is_string($opcao) && str_starts_with($opcao, '--raiz=')) {
            $raiz = rtrim(substr($opcao, 7), '/');
            continue;
        }
        fwrite($erro, "Uso: php scripts/quality/check_tamanho.php [--raiz=DIR] [--atualizar-baseline]\n");

        return 2;
    }

    $medicao = medirTamanho($raiz);
    if ($medicao['arquivos'] === 0) {
        fwrite($erro, "Nenhum arquivo do produto foi encontrado; confira o bloco de dados do topo.\n");

        return 2;
    }

    printf(
        "Tamanho: %d arquivo(s) do produto; %d acima de %d linhas, %d função(ões) acima de "
        . "complexidade %d.\n",
        $medicao['arquivos'],
        count($medicao['linhas']),
        LIMITE_LINHAS,
        count($medicao['funcoes']),
        LIMITE_COMPLEXIDADE,
    );

    $baseline = lerBaseline()['tamanho'] ?? null;
    $baseline = is_array($baseline) ? $baseline : null;
    $ciSomenteLeitura = baselineEhSomenteLeitura();

    if ($ciSomenteLeitura && $atualizar) {
        fwrite($erro, "No CI o baseline de tamanho é somente leitura; remova --atualizar-baseline.\n");

        return 2;
    }

    if ($baseline === null && !$atualizar) {
        fwrite($erro, "Baseline de tamanho obrigatório; crie explicitamente com composer complexity:baseline.\n");

        return 2;
    }

    if ($atualizar) {
        gravarSecaoDoBaseline('tamanho', secaoParaGravar($medicao));
        printf(
            "Baseline de tamanho %s.\n",
            $baseline === null ? 'criado' : 'regravado por --atualizar-baseline',
        );

        return 0;
    }

    $arquivosBase = numeroDoBaseline($baseline, 'arquivos_medidos')['inteiro'];
    $problemas = [];
    if (escopoEncolheu($medicao['arquivos'], $arquivosBase)) {
        $problemas[] = sprintf(
            'o escopo medido encolheu (%d → %d arquivos). Dívida que some porque o arquivo saiu '
            . 'do escopo não é dívida paga.',
            $arquivosBase,
            $medicao['arquivos'],
        );
    }

    $porArquivo = $baseline['excecoes_de_arquivo'] ?? null;
    $porFuncao = $baseline['excecoes_de_funcao'] ?? null;
    if (!is_array($porArquivo) || !is_array($porFuncao)) {
        throw new UnexpectedValueException(
            'tools/quality/tamanho-baseline.json: seção `tamanho` sem as exceções. '
            . 'Regrave com `composer complexity:baseline`.',
        );
    }

    if (($baseline['limite_linhas'] ?? null) !== LIMITE_LINHAS
        || ($baseline['limite_complexidade'] ?? null) !== LIMITE_COMPLEXIDADE) {
        throw new UnexpectedValueException('Limiares incompatíveis no baseline de tamanho.');
    }

    $arquivos = compararDivida($medicao['linhas'], $porArquivo, 'Arquivo', LIMITE_LINHAS);
    $funcoes = compararDivida($medicao['funcoes'], $porFuncao, 'Função', LIMITE_COMPLEXIDADE);
    $problemas = array_merge($problemas, $arquivos['problemas'], $funcoes['problemas']);

    if ($problemas !== []) {
        fwrite($erro, "Ratchet de tamanho e complexidade reprovou:\n\n");
        foreach ($problemas as $problema) {
            fwrite($erro, "  - {$problema}\n");
        }
        fwrite(
            $erro,
            "\nSe a piora for deliberada (código que chegou pronto de outro lugar), regrave com\n"
            . "`composer complexity:baseline` no mesmo commit, para a decisão aparecer na revisão.\n",
        );

        return 1;
    }

    if (!$arquivos['melhorou'] && !$funcoes['melhorou']) {
        return 0;
    }

    if ($ciSomenteLeitura) {
        echo "Dívida de tamanho menor que a registrada; no CI o arquivo não é regravado.\n";

        return 0;
    }

    gravarSecaoDoBaseline('tamanho', secaoParaGravar($medicao));
    echo "Dívida de tamanho caiu: baseline apertado. Commite tools/quality/tamanho-baseline.json.\n";

    return 0;
}

/**
 * @param array{arquivos: int, linhas: array<string, int>, funcoes: array<string, int>} $medicao
 * @return array<string, mixed>
 */
function secaoParaGravar(array $medicao): array
{
    return [
        'limite_linhas' => LIMITE_LINHAS,
        'limite_complexidade' => LIMITE_COMPLEXIDADE,
        'arquivos_medidos' => $medicao['arquivos'],
        'excecoes_de_arquivo' => $medicao['linhas'],
        'excecoes_de_funcao' => $medicao['funcoes'],
    ];
}

if (executadoDiretamente(__FILE__)) {
    $argumentos = $_SERVER['argv'] ?? [];
    try {
        exit(mainTamanho(is_array($argumentos) ? array_values($argumentos) : []));
    } catch (UnexpectedValueException $falha) {
        // Baseline quebrado sai 2, reprovação sai 1.
        fwrite(STDERR, mensagemDeThrowable($falha) . "\n");
        exit(2);
    }
}
