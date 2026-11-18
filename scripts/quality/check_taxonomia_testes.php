<?php

declare(strict_types=1);

namespace Quality;

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/**
 * Categorias da taxonomia da frota que ESTE repositório expõe, com o comando
 * que as seleciona e o diretório que as contém.
 *
 * `diretorio` nulo é categoria que não é suíte do PHPUnit: `smoke` roda contra
 * um alvo já publicado. Essas categorias continuam sendo
 * comando, e o comando continua sendo conferido.
 */
const CATEGORIAS_DE_TESTE = [
    'unit' => ['comando' => 'test:unit', 'diretorio' => 'tests/Unit'],
    'integration' => ['comando' => 'test:integration', 'diretorio' => 'tests/Integration'],
    'contract' => ['comando' => 'test:contract', 'diretorio' => 'tests/Contract'],
    'security' => ['comando' => 'test:security', 'diretorio' => 'tests/Security'],
    'performance' => ['comando' => 'test:performance', 'diretorio' => 'tests/Performance'],
    'smoke' => ['comando' => 'test:smoke', 'diretorio' => null],
];

/**
 * Categorias da taxonomia que este produto NÃO tem. Cada uma precisa de linha
 * própria na tabela de não aplicabilidades do docs/TESTES.md — ausência
 * escrita é diferença assumida; ausência silenciosa é pendência.
 */
const CATEGORIAS_NAO_APLICAVEIS = ['e2e'];
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

require_once __DIR__ . '/quality_gates.php';

use JsonException;

/**
 * Portão da taxonomia e da política de testes instáveis.
 *
 * Três coisas costumam apodrecer sem ninguém ver, e é por elas que este script
 * existe:
 *
 * 1. **Arquivo de teste sem categoria.** `tests/Coisas/XTest.php` não é
 *    selecionado por comando nenhum: ele roda no agregado, some do comando da
 *    categoria e ninguém percebe, porque o agregado continua verde.
 * 2. **Comando que não seleciona nada.** `test:security` sobre um diretório
 *    vazio passa em 0,2 s e dá a impressão de que existe teste de segurança.
 *    Aqui isso reprova — e a categoria que de fato não se aplica não ganha
 *    comando: ganha linha na documentação, que este portão confere.
 * 3. **Teste pulado que virou paisagem.** `markTestSkipped` sem registro é
 *    cobertura que evaporou em silêncio. Todo pulo precisa estar em
 *    `tools/quality/testes-em-quarentena.json`, com motivo, data e
 *    responsável — e quarentena tem prazo.
 */

/** Inventário versionado de pulos; a lista muda por repositório, o formato não. */
const INVENTARIO_DE_PULOS = 'tools/quality/testes-em-quarentena.json';

/**
 * Marcas de foco e de laço escondido que não podem ser commitadas.
 *
 * `--filter`/`--group` fixos no manifesto são o `.only` do mundo PHPUnit: a
 * suíte passa a rodar um pedaço e continua se chamando suíte. `sleep`/`usleep`
 * são o retry escondido: teste que espera o relógio é teste que falha sozinho
 * na máquina lenta e passa a ser ignorado.
 */
const MARCAS_DE_FOCO = ['fdescribe(', 'fit(', '->only(', '.only('];
const MARCAS_DE_ESPERA = ['sleep(', 'usleep('];

/** Chamadas que pulam um teste; toda ocorrência precisa de registro. */
const MARCAS_DE_PULO = ['markTestSkipped', 'markTestIncomplete'];

/**
 * @param list<mixed> $argumentos
 * @param resource|null $erro
 */
function mainTaxonomia(array $argumentos, $erro = null): int
{
    $erro ??= STDERR;
    $raiz = dirname(__DIR__, 2);
    foreach (array_slice($argumentos, 1) as $opcao) {
        if (is_string($opcao) && str_starts_with($opcao, '--raiz=')) {
            $raiz = rtrim(substr($opcao, 7), '/');
            continue;
        }
        fwrite($erro, "Uso: php scripts/quality/check_taxonomia_testes.php [--raiz=DIR]\n");

        return 2;
    }

    $manifesto = lerJson($raiz . '/composer.json');
    if ($manifesto === null) {
        fwrite($erro, "composer.json ausente ou inválido em {$raiz}.\n");

        return 2;
    }
    $scripts = $manifesto['scripts'] ?? null;
    $scripts = is_array($scripts) ? $scripts : [];

    $problemas = array_merge(
        problemasDeCategoria($raiz, $scripts),
        problemasDeNaoAplicabilidade($raiz, $scripts),
        problemasDeEstabilidade($raiz, $scripts),
    );

    if ($problemas !== []) {
        fwrite($erro, "Taxonomia ou política de testes violada:\n\n");
        foreach ($problemas as $problema) {
            fwrite($erro, "  - {$problema}\n");
        }
        fwrite($erro, "\nA taxonomia e a política estão em docs/TESTES.md.\n");

        return 1;
    }

    printf(
        "Taxonomia de testes íntegra: %d categoria(s) aplicável(is), %d declarada(s) não aplicável(is).%s",
        count(CATEGORIAS_DE_TESTE),
        count(CATEGORIAS_NAO_APLICAVEIS),
        PHP_EOL,
    );

    return 0;
}

/**
 * @return array<string, mixed>|null
 */
function lerJson(string $caminho): ?array
{
    $conteudo = @file_get_contents($caminho);
    if (!is_string($conteudo)) {
        return null;
    }

    try {
        $dados = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    /** @var array<string, mixed>|null */
    return is_array($dados) ? $dados : null;
}

/**
 * Arquivos `*Test.php` sob um diretório, relativos à raiz.
 *
 * @return list<string>
 */
function arquivosDeTeste(string $raiz, string $diretorio): array
{
    $base = $raiz . '/' . $diretorio;
    if (!is_dir($base)) {
        return [];
    }

    $encontrados = [];
    $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterador as $arquivo) {
        if (!$arquivo instanceof \SplFileInfo || !$arquivo->isFile()) {
            continue;
        }
        if (!str_ends_with($arquivo->getFilename(), 'Test.php')) {
            continue;
        }
        $encontrados[] = $diretorio . '/' . substr($arquivo->getPathname(), strlen($base) + 1);
    }
    sort($encontrados);

    return $encontrados;
}

/**
 * @param array<mixed> $scripts
 * @return list<string>
 */
function problemasDeCategoria(string $raiz, array $scripts): array
{
    $problemas = [];
    $classificados = [];

    foreach (CATEGORIAS_DE_TESTE as $categoria => $dados) {
        $comando = $dados['comando'];
        if (!array_key_exists($comando, $scripts)) {
            $problemas[] = "categoria `{$categoria}` é aplicável mas `composer {$comando}` não existe.";
        }

        $diretorio = $dados['diretorio'];
        if ($diretorio === null) {
            continue;
        }
        if (!is_dir($raiz . '/' . $diretorio)) {
            $problemas[] = "categoria `{$categoria}`: {$diretorio}/ não existe.";
            continue;
        }
        $arquivos = arquivosDeTeste($raiz, $diretorio);
        if ($arquivos === []) {
            $problemas[] = "`composer {$dados['comando']}` não seleciona nada: {$diretorio}/ está vazio. "
                . 'Comando que não seleciona nada é uniformidade simulada.';
        }
        foreach ($arquivos as $arquivo) {
            $classificados[$arquivo] = $categoria;
        }
    }

    foreach (arquivosDeTeste($raiz, 'tests') as $arquivo) {
        if (!isset($classificados[$arquivo])) {
            $problemas[] = "{$arquivo} não pertence a nenhuma categoria da taxonomia.";
        }
    }

    return $problemas;
}

/**
 * @param array<mixed> $scripts
 * @return list<string>
 */
function problemasDeNaoAplicabilidade(string $raiz, array $scripts): array
{
    $convencoes = @file_get_contents($raiz . '/docs/TESTES.md');
    $problemas = [];

    foreach (CATEGORIAS_NAO_APLICAVEIS as $categoria) {
        $comando = 'test:' . $categoria;
        if (array_key_exists($comando, $scripts)) {
            $problemas[] = "categoria `{$categoria}` está declarada não aplicável e mesmo assim "
                . "`composer {$comando}` existe.";
        }
        $diretorio = 'tests/' . ucfirst($categoria);
        if (arquivosDeTeste($raiz, $diretorio) !== []) {
            $problemas[] = "categoria `{$categoria}` está declarada não aplicável e {$diretorio}/ tem testes.";
        }
        if (!is_string($convencoes)) {
            $problemas[] = 'docs/TESTES.md não pôde ser lido para conferir as não aplicabilidades.';
            continue;
        }
        if (preg_match('/^\|\s*`' . preg_quote($comando, '/') . '`\s*\|/m', $convencoes) !== 1) {
            $problemas[] = "docs/TESTES.md não tem a linha que declara `{$comando}` não aplicável.";
        }
    }

    return $problemas;
}

/**
 * Ocorrências de pulo em arquivos de teste, com o método que as contém.
 *
 * @param list<string> $arquivos
 * @return array<string, string> chave `arquivo::metodo`, valor a marca usada
 */
function pulosDeclarados(string $raiz, array $arquivos): array
{
    $pulos = [];
    foreach ($arquivos as $relativo) {
        $conteudo = @file_get_contents($raiz . '/' . $relativo);
        if (!is_string($conteudo)) {
            continue;
        }
        $metodo = '(fora de método)';
        foreach (explode("\n", $conteudo) as $linha) {
            if (preg_match('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $linha, $achado) === 1) {
                $metodo = $achado[1];
            }
            foreach (MARCAS_DE_PULO as $marca) {
                if (str_contains($linha, $marca . '(')) {
                    $pulos[$relativo . '::' . $metodo] = $marca;
                }
            }
        }
    }

    return $pulos;
}

/**
 * @param array<mixed> $scripts
 * @return list<string>
 */
function problemasDeEstabilidade(string $raiz, array $scripts): array
{
    $problemas = [];
    $arquivos = [];
    foreach (CATEGORIAS_DE_TESTE as $dados) {
        if (is_string($dados['diretorio'])) {
            $arquivos = array_merge($arquivos, arquivosDeTeste($raiz, $dados['diretorio']));
        }
    }

    foreach ($arquivos as $relativo) {
        $conteudo = @file_get_contents($raiz . '/' . $relativo);
        if (!is_string($conteudo)) {
            continue;
        }
        foreach (MARCAS_DE_FOCO as $marca) {
            if (str_contains($conteudo, $marca)) {
                $problemas[] = "{$relativo} traz foco commitado (`{$marca}`).";
            }
        }
        foreach (MARCAS_DE_ESPERA as $marca) {
            if (preg_match('/(?<![A-Za-z_>$])' . preg_quote($marca, '/') . '/', $conteudo) === 1) {
                $problemas[] = "{$relativo} espera pelo relógio (`{$marca}`): é retry escondido.";
            }
        }
    }

    foreach ($scripts as $nome => $definicao) {
        foreach (is_array($definicao) ? $definicao : [$definicao] as $linha) {
            if (is_string($linha) && preg_match('/--(filter|group)[= ]/', $linha) === 1) {
                $problemas[] = "composer.json: o script `{$nome}` fixa --filter/--group.";
            }
        }
    }

    return array_merge($problemas, problemasDoInventario($raiz, pulosDeclarados($raiz, $arquivos)));
}

/**
 * @param array<string, string> $pulos
 * @return list<string>
 */
function problemasDoInventario(string $raiz, array $pulos): array
{
    $problemas = [];
    $inventario = lerJson($raiz . '/' . INVENTARIO_DE_PULOS);
    if ($inventario === null) {
        if ($pulos === []) {
            return [];
        }

        return [INVENTARIO_DE_PULOS . ' ausente ou inválido, e a suíte tem teste pulado.'];
    }

    $registrados = [];
    foreach (['guardas_de_ambiente', 'quarentena'] as $secao) {
        $entradas = $inventario[$secao] ?? null;
        if (!is_array($entradas)) {
            $problemas[] = INVENTARIO_DE_PULOS . ": falta a seção `{$secao}`.";
            continue;
        }
        foreach ($entradas as $entrada) {
            if (!is_array($entrada)) {
                $problemas[] = INVENTARIO_DE_PULOS . ": entrada não estruturada em `{$secao}`.";
                continue;
            }
            $obrigatorios = ['arquivo', 'teste', 'motivo', 'registrado_em', 'responsavel'];
            if ($secao === 'quarentena') {
                $obrigatorios[] = 'prazo';
            }
            $faltando = [];
            foreach ($obrigatorios as $campo) {
                if (!is_string($entrada[$campo] ?? null) || $entrada[$campo] === '') {
                    $faltando[] = $campo;
                }
            }
            if ($faltando !== []) {
                $problemas[] = INVENTARIO_DE_PULOS . ': entrada sem ' . implode(', ', $faltando) . '.';
                continue;
            }
            /** @var array<string, string> $entrada */
            $chave = $entrada['arquivo'] . '::' . $entrada['teste'];
            $registrados[$chave] = true;
            if ($secao === 'quarentena' && $entrada['prazo'] < date('Y-m-d')) {
                $problemas[] = "quarentena vencida em {$chave} (prazo {$entrada['prazo']}). "
                    . 'Quarentena tem prazo: ou o teste volta, ou é apagado.';
            }
            if (!isset($pulos[$chave])) {
                $problemas[] = INVENTARIO_DE_PULOS . ": {$chave} está registrado e não pula mais nada. "
                    . 'Registro velho vira permissão silenciosa.';
            }
        }
    }

    foreach (array_keys($pulos) as $chave) {
        if (!isset($registrados[$chave])) {
            $problemas[] = "teste pulado sem registro em " . INVENTARIO_DE_PULOS . ": {$chave}.";
        }
    }

    return $problemas;
}

if (executadoDiretamente(__FILE__)) {
    $argumentos = $_SERVER['argv'] ?? [];
    exit(mainTaxonomia(is_array($argumentos) ? array_values($argumentos) : []));
}
