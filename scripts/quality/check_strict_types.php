<?php

declare(strict_types=1);

namespace Quality;

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/**
 * Prefixos de caminho que ficam fora do portão, e não na baseline: código que
 * existe para estar errado. As fixtures do Semgrep são violações de propósito,
 * uma por regra; na baseline elas pareceriam dívida a pagar, e "pagá-la"
 * mexeria no autoteste das regras.
 */
const FORA_DO_PORTAO_DE_STRICT_TYPES = [
    'tools/quality/semgrep/project/fixtures/',
];
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

require_once __DIR__ . '/baseline_tamanho.php';

use JsonException;
use RuntimeException;
use UnexpectedValueException;

/*
 * Ratchet de `declare(strict_types=1)`.
 *
 * Sem a declaração, o PHP converte em silêncio o escalar do tipo errado — "12"
 * vira 12, 1.9 vira 1 — em toda chamada feita a partir daquele arquivo, e o
 * contrato de tipos que o src/ cumpre deixa de valer para quem o chama. O
 * php-cs-fixer não cobra a declaração (a regra é "risky" e o repositório roda
 * com `setRiskyAllowed(false)`), e o Rector a pula de propósito.
 *
 * A baseline guarda os arquivos que já não tinham a declaração quando o
 * portão nasceu, e SÓ ENCOLHE: arquivo novo, ou renomeado, nasce com ela;
 * arquivo que ganhou a declaração sai da lista sozinho. Com `CI=1` a baseline
 * é somente leitura, como a de tamanho: se o CI regravasse, o portão passaria
 * a aprovar o que ele mesmo acabou de medir.
 */

const BASELINE_DE_STRICT_TYPES = 'tools/quality/strict-types-baseline.json';

/**
 * Arquivos PHP versionados ou novos ainda não ignorados, relativos à raiz.
 * Só o `.gitignore` do repositório conta: um exclude global do usuário não
 * pode esconder arquivo do portão.
 *
 * @return list<string>
 */
function arquivosPhpDoRepositorio(string $raiz): array
{
    $resultado = executarProcesso(
        ['git', 'ls-files', '-z', '--cached', '--others', '--exclude-per-directory=.gitignore', '--', '*.php'],
        $raiz,
    );
    if ($resultado['status'] !== 0) {
        throw new RuntimeException('git ls-files falhou: ' . trim($resultado['stderr']));
    }

    $arquivos = [];
    foreach (explode("\0", $resultado['stdout']) as $arquivo) {
        if ($arquivo === '' || !is_file($raiz . '/' . $arquivo) || foraDoPortao($arquivo)) {
            continue;
        }
        $arquivos[$arquivo] = true;
    }
    $lista = array_keys($arquivos);
    sort($lista);

    return $lista;
}

function foraDoPortao(string $arquivo): bool
{
    foreach (FORA_DO_PORTAO_DE_STRICT_TYPES as $prefixo) {
        if (str_starts_with($arquivo, $prefixo)) {
            return true;
        }
    }

    return false;
}

/**
 * Se a primeira instrução do arquivo é `declare(strict_types=1)`.
 *
 * Pela tokenização, e não por busca de texto: a string num comentário, num
 * docblock ou numa asserção de teste não conta, e a declaração fora do topo
 * nem compila. Antes dela só podem vir a tag de abertura, comentários e,
 * na primeira linha, o shebang que o CLI do PHP pula.
 */
function declaraStrictTypes(string $codigo): bool
{
    $tokens = token_get_all($codigo);
    $indice = ehShebang($tokens[0] ?? null) ? 1 : 0;
    $abertura = $tokens[$indice] ?? null;
    if (!is_array($abertura) || $abertura[0] !== \T_OPEN_TAG) {
        return false;
    }

    $instrucao = '';
    foreach (array_slice($tokens, $indice + 1) as $token) {
        if (is_array($token) && in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
            continue;
        }
        $texto = is_array($token) ? $token[1] : $token;
        if ($texto === ';' || !str_starts_with(strtolower($instrucao . $texto), 'declare')) {
            break;
        }
        $instrucao .= $texto;
    }

    return strtolower($instrucao) === 'declare(strict_types=1)';
}

/**
 * O tokenizador devolve o shebang como HTML de fora do PHP; ele só é shebang
 * se for a primeira linha inteira e nada mais.
 *
 * @param array{0: int, 1: string, 2: int}|string|null $token
 */
function ehShebang(array|string|null $token): bool
{
    return is_array($token)
        && $token[0] === \T_INLINE_HTML
        && str_starts_with($token[1], '#!')
        && substr_count($token[1], "\n") === 1
        && str_ends_with($token[1], "\n");
}

/**
 * @param list<string> $arquivos
 * @return list<string>
 */
function arquivosSemStrictTypes(string $raiz, array $arquivos): array
{
    $sem = [];
    foreach ($arquivos as $arquivo) {
        $codigo = file_get_contents($raiz . '/' . $arquivo);
        if (!is_string($codigo)) {
            throw new RuntimeException("Não foi possível ler {$arquivo}.");
        }
        if (!declaraStrictTypes($codigo)) {
            $sem[] = $arquivo;
        }
    }

    return $sem;
}

/**
 * A baseline como lista ordenada, ou null quando o arquivo não existe.
 *
 * @return list<string>|null
 */
function lerBaselineDeStrictTypes(string $arquivo): ?array
{
    if (!is_file($arquivo)) {
        return null;
    }

    $conteudo = file_get_contents($arquivo);
    if (!is_string($conteudo)) {
        throw new UnexpectedValueException('Não foi possível ler ' . BASELINE_DE_STRICT_TYPES . '.');
    }

    try {
        $dados = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $erro) {
        throw new UnexpectedValueException(BASELINE_DE_STRICT_TYPES . ' não é JSON válido: ' . $erro->getMessage());
    }

    $lista = is_array($dados) ? ($dados['sem_strict_types'] ?? null) : null;
    if (!is_array($lista) || !array_is_list($lista)) {
        throw new UnexpectedValueException(BASELINE_DE_STRICT_TYPES . ": falta a lista 'sem_strict_types'.");
    }

    $arquivos = [];
    foreach ($lista as $item) {
        if (!is_string($item) || $item === '') {
            throw new UnexpectedValueException(BASELINE_DE_STRICT_TYPES . ': item que não é caminho de arquivo.');
        }
        $arquivos[$item] = true;
    }
    $ordenada = array_keys($arquivos);
    sort($ordenada);

    return $ordenada;
}

/** @param list<string> $arquivos */
function gravarBaselineDeStrictTypes(string $arquivo, array $arquivos): void
{
    $json = json_encode(
        ['sem_strict_types' => $arquivos],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
    if (file_put_contents($arquivo, $json . "\n") === false) {
        throw new UnexpectedValueException('Não foi possível gravar ' . BASELINE_DE_STRICT_TYPES . '.');
    }
}

/**
 * @param list<mixed> $argumentos
 * @param resource|null $erro
 */
function mainStrictTypes(array $argumentos, $erro = null): int
{
    $erro ??= STDERR;
    $raiz = dirname(__DIR__, 2);
    foreach (array_slice($argumentos, 1) as $opcao) {
        if (is_string($opcao) && str_starts_with($opcao, '--raiz=')) {
            $raiz = rtrim(substr($opcao, 7), '/');
            continue;
        }
        fwrite($erro, "Uso: php scripts/quality/check_strict_types.php [--raiz=DIR]\n");

        return 2;
    }

    $arquivos = arquivosPhpDoRepositorio($raiz);
    if ($arquivos === []) {
        fwrite($erro, "Nenhum arquivo PHP foi encontrado; confira a raiz e o bloco de dados do topo.\n");

        return 2;
    }
    $sem = arquivosSemStrictTypes($raiz, $arquivos);
    printf("strict_types: %d arquivo(s) PHP, %d sem a declaração.\n", count($arquivos), count($sem));

    $caminho = $raiz . '/' . BASELINE_DE_STRICT_TYPES;
    $baseline = lerBaselineDeStrictTypes($caminho);
    $somenteLeitura = baselineEhSomenteLeitura();

    if ($baseline === null) {
        if ($somenteLeitura) {
            fwrite($erro, "Com CI=1 a baseline de strict_types é obrigatória e não pode ser criada.\n");

            return 2;
        }
        gravarBaselineDeStrictTypes($caminho, $sem);
        printf("Baseline de strict_types criada com %d arquivo(s). Commite %s.\n", count($sem), BASELINE_DE_STRICT_TYPES);

        return 0;
    }

    $novos = array_values(array_diff($sem, $baseline));
    if ($novos !== []) {
        fwrite($erro, "Arquivo PHP sem declare(strict_types=1) fora da baseline:\n\n");
        foreach ($novos as $arquivo) {
            fwrite($erro, "  - {$arquivo}\n");
        }
        fwrite(
            $erro,
            "\nDeclare `declare(strict_types=1);` logo depois de `<?php`. A baseline só encolhe:\n"
            . "arquivo novo ou renomeado não entra nela.\n",
        );

        return 1;
    }

    if (count($sem) === count($baseline)) {
        return 0;
    }

    if ($somenteLeitura) {
        echo "Dívida de strict_types menor que a registrada; com CI=1 a baseline não é regravada.\n";

        return 0;
    }

    gravarBaselineDeStrictTypes($caminho, $sem);
    printf(
        "Dívida de strict_types caiu de %d para %d: baseline apertada. Commite %s.\n",
        count($baseline),
        count($sem),
        BASELINE_DE_STRICT_TYPES,
    );

    return 0;
}

if (executadoDiretamente(__FILE__)) {
    $argumentos = $_SERVER['argv'] ?? [];
    try {
        exit(mainStrictTypes(is_array($argumentos) ? array_values($argumentos) : []));
    } catch (RuntimeException $falha) {
        // Como no portão de tamanho: baseline ou git quebrado sai 2, reprovação sai 1.
        fwrite(STDERR, mensagemDeThrowable($falha) . "\n");
        exit(2);
    }
}
