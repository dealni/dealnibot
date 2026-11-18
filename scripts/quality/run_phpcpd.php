<?php

declare(strict_types=1);

/*
 * Detector de cópia/colagem com TETO de clones e de linhas duplicadas.
 *
 * O teto segue um ratchet próprio: `teto = min(teto_atual, medido)` no fluxo
 * normal. Só `--atualizar-baseline` regrava uma decisão explícita. Controlar as
 * duas medidas evita que um clone existente cresça sem reprovar só porque a
 * QUANTIDADE ficou igual.
 *
 * O phpcpd vive em tools/phpcpd/ com o próprio composer.json — o porquê está em
 * docs/TESTES.md, "Duplicação (PHPCPD)". Sem ele instalado, o gate reprova para
 * não produzir um falso verde local.
 */

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/**
 * Caminhos deste repositório que a varredura NÃO deve ler, além da lista
 * canônica abaixo. Subprojeto com vendor próprio e diretório de artefato
 * gerado entram aqui; diretório de código do produto, nunca.
 */
const EXCLUSOES_DO_BOT = [];
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

$argumentos = $_SERVER['argv'] ?? [];
$atualizarBaseline = is_array($argumentos)
    && count($argumentos) === 2
    && ($argumentos[1] ?? null) === '--atualizar-baseline';
if (!is_array($argumentos) || (count($argumentos) !== 1 && !$atualizarBaseline)) {
    fwrite(STDERR, "Uso: php scripts/quality/run_phpcpd.php [--atualizar-baseline]\n");
    exit(2);
}

$ci = getenv('CI');
$ciSomenteLeitura = is_string($ci) && $ci !== '' && $ci !== '0' && $ci !== 'false';
if ($ciSomenteLeitura && $atualizarBaseline) {
    fwrite(STDERR, "No CI o baseline de duplicação é somente leitura; remova --atualizar-baseline.\n");
    exit(2);
}

$raiz = dirname(__DIR__, 2);
$caminhoBaseline = $raiz . '/tools/quality/duplicacao-baseline.json';
$conteudoBaseline = @file_get_contents($caminhoBaseline);
$baseline = null;
if (is_string($conteudoBaseline)) {
    try {
        $baseline = json_decode($conteudoBaseline, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        // A validação estrutural abaixo produz a mensagem pública do gate.
    }
}
$duplicacaoBaseline = is_array($baseline) ? ($baseline['duplicacao'] ?? null) : null;
if (!is_array($duplicacaoBaseline)
    || !isset($duplicacaoBaseline['clones'], $duplicacaoBaseline['linhas'])
    || !is_int($duplicacaoBaseline['clones'])
    || !is_int($duplicacaoBaseline['linhas'])
    || $duplicacaoBaseline['clones'] < 0
    || $duplicacaoBaseline['linhas'] < 0) {
    fwrite(STDERR, "tools/quality/duplicacao-baseline.json sem seção 'duplicacao' válida.\n");
    exit(2);
}
$tetoDeClones = $duplicacaoBaseline['clones'];
$tetoDeLinhas = $duplicacaoBaseline['linhas'];
$phpcpd = $raiz . '/tools/phpcpd/vendor/bin/phpcpd';

if (!is_file($phpcpd)) {
    fwrite(STDERR, "phpcpd não instalado — a detecção de cópia NÃO rodou.\n");
    fwrite(STDERR, "    composer install -d tools/phpcpd\n");
    exit(2);
}

// A ferramenta aceita diretórios, não arquivos individuais. Passar só uma lista
// de diretórios deixava de fora bot.php, health.php e os demais entrypoints PHP
// da raiz, que são exatamente onde a cópia entre bots costuma aparecer. A
// varredura parte da raiz e usa a exclusão oficial do PHPCPD para podar
// dependência, teste, ferramenta e documentação.
//
// Medido em 19/09/2026 nos quatro repositórios: com as exclusões abaixo a
// varredura da raiz custa 0,09-0,18 s e no máximo 49 MB de RSS, contra
// 0,09-0,14 s e 34-44 MB da lista de diretórios — e lê de 6 a 51 arquivos a
// mais. Sem as exclusões (vendor/ incluído) seriam 3,8-5,2 s e até 623 MB, que
// é a leitura que originou a lenda de que a raiz estoura o memory_limit: o
// --exclude poda os diretórios ANTES da leitura, e por isso não estoura.
//
// Os caminhos são absolutos porque o PHPCPD compara o caminho real do arquivo
// com o caminho real de cada exclusão; caminho ausente é simplesmente omitido
// (por exemplo, node_modules num checkout sem npm ci).
$caminhosExcluidos = [
    'vendor',
    'node_modules',
    'tests',
    'tools',
    '.git',
    '.artifacts',
    '.github',
    '.vscode',
    'docs',
    'deploy',
    'logs',
    'composer-unused.php',
    'rector.php',
    '.php-cs-fixer.dist.php',
    ...EXCLUSOES_DO_BOT,
];
$exclusoes = [];
foreach ($caminhosExcluidos as $caminho) {
    $absoluto = $raiz . '/' . $caminho;
    if (file_exists($absoluto)) {
        $exclusoes[] = '--exclude=' . escapeshellarg($absoluto);
    }
}

// Dependência de subprojeto (um `suporte/` com composer próprio, por exemplo)
// não aparece na lista acima e apareceria na varredura no dia em que alguém
// rodasse o composer install dele. A poda é por descoberta para o gate não
// depender de ninguém lembrar de editar este arquivo.
foreach (['/*/vendor', '/*/node_modules', '/*/*/vendor', '/*/*/node_modules'] as $padrao) {
    $aninhados = glob($raiz . $padrao, GLOB_ONLYDIR);
    foreach (is_array($aninhados) ? $aninhados : [] as $aninhado) {
        $exclusoes[] = '--exclude=' . escapeshellarg($aninhado);
    }
}

// Todo pedaço que vem de fora passa por escapeshellarg; o resto é literal. O
// comando é montado numa variável e só então executado — mesma forma de
// run_tests.php, e a que a regra
// `comando-de-shell-com-variavel` de tools/quality/semgrep/project/php.yaml espera ver.
$comando = escapeshellarg(PHP_BINARY)
    . ' -d ' . escapeshellarg('memory_limit=1G')
    . ' ' . escapeshellarg($phpcpd)
    . ' --min-lines=10 --min-tokens=70 '
    . implode(' ', $exclusoes)
    . ' ' . escapeshellarg($raiz)
    . ' 2>&1';

$saida = [];
$status = 0;
exec($comando, $saida, $status);

$texto = implode("\n", $saida);
echo str_replace($raiz . '/', '', $texto), "\n";

// O phpcpd retorna 1 quando encontra clones; isso é resultado válido e os
// tetos abaixo decidem se passa. Qualquer outro status não zero é falha da
// ferramenta e não pode ser confundido com uma medição válida.
if ($status !== 0 && $status !== 1) {
    fwrite(STDERR, "phpcpd terminou com status {$status}.\n");
    exit(2);
}

if (preg_match('/Found (\d+) code clones?/', $texto, $encontrados) === 1) {
    $clones = (int) $encontrados[1];
    if (preg_match('/with (\d+) duplicated lines?/', $texto, $linhasEncontradas) !== 1) {
        fwrite(STDERR, "Não foi possível ler a quantidade de linhas duplicadas do phpcpd.\n");
        exit(2);
    }
    $linhasDuplicadas = (int) $linhasEncontradas[1];
} elseif (str_contains($texto, 'No code clones found')) {
    $clones = 0;
    $linhasDuplicadas = 0;
} else {
    fwrite(STDERR, "Não foi possível ler o resultado do phpcpd.\n");
    exit(2);
}

printf(
    "Clones: %d/%d; linhas duplicadas: %d/%d\n",
    $clones,
    $tetoDeClones,
    $linhasDuplicadas,
    $tetoDeLinhas
);

if (!$atualizarBaseline && ($clones > $tetoDeClones || $linhasDuplicadas > $tetoDeLinhas)) {
    fwrite(
        STDERR,
        "Duplicação acima do teto de clones ou linhas. Extraia a parte comum — não suba os tetos.\n"
    );
    exit(1);
}

if ($atualizarBaseline) {
    $baseline['duplicacao'] = [
        'clones' => $clones,
        'linhas' => $linhasDuplicadas,
        'medido_em' => date('Y-m-d'),
    ];
    ksort($baseline);
    $json = json_encode(
        $baseline,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
    if (file_put_contents($caminhoBaseline, $json . "\n") === false) {
        fwrite(STDERR, "Não foi possível gravar tools/quality/duplicacao-baseline.json.\n");
        exit(2);
    }
    echo "Baseline de duplicação atualizado.\n";
} elseif ($clones < $tetoDeClones || $linhasDuplicadas < $tetoDeLinhas) {
    printf(
        "Duplicação caiu. Rode `composer duplication:baseline` para registrar %d clone(s) e %d linha(s).\n",
        $clones,
        $linhasDuplicadas
    );
}

exit(0);
