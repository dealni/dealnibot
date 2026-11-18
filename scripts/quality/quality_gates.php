<?php

declare(strict_types=1);

namespace Quality;

use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * @param list<string> $comando
 * @return array{status: int, stdout: string, stderr: string}
 */
function executarProcesso(array $comando, ?string $diretorio = null): array
{
    $stdout = tmpfile();
    $stderr = tmpfile();
    if (!is_resource($stdout) || !is_resource($stderr)) {
        throw new RuntimeException('Não foi possível criar arquivos temporários para o processo.');
    }

    $canos = [];
    $processo = proc_open(
        $comando,
        [0 => ['file', 'php://stdin', 'r'], 1 => $stdout, 2 => $stderr],
        $canos,
        $diretorio,
    );
    if (!is_resource($processo)) {
        fclose($stdout);
        fclose($stderr);
        throw new RuntimeException('Não foi possível iniciar o processo.');
    }

    $status = proc_close($processo);
    rewind($stdout);
    rewind($stderr);
    $saida = stream_get_contents($stdout);
    $saidaDeErro = stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);
    if (!is_string($saida) || !is_string($saidaDeErro)) {
        throw new RuntimeException('Não foi possível ler a saída do processo.');
    }

    return ['status' => $status, 'stdout' => $saida, 'stderr' => $saidaDeErro];
}

function executadoDiretamente(string $arquivo): bool
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? null;

    return is_string($script) && realpath($script) === realpath($arquivo);
}

function falhaTransitoriaDoAudit(string $saida): bool
{
    return preg_match(
        '/curl error|timed? out|network disabled|request canceled|could not resolve|connection (?:refused|reset)|failed to (?:download|open stream)|api\/security-advisories/i',
        $saida,
    ) === 1;
}

/**
 * @param array<mixed> $relatorio
 * @return list<string>
 */
function dependenciasComLicencaReprovada(array $relatorio): array
{
    $dependencias = $relatorio['dependencies'] ?? null;
    if (!is_array($dependencias)) {
        throw new UnexpectedValueException('composer licenses retornou uma estrutura inválida.');
    }

    $permissivas = [
        '0BSD',
        'Apache-2.0',
        'BSD-2-Clause',
        'BSD-3-Clause',
        'ISC',
        'MIT',
        'MIT-0',
        'MPL-2.0',
        'Unlicense',
    ];
    $reprovadas = [];
    foreach ($dependencias as $nome => $pacote) {
        if (!is_string($nome) || !is_array($pacote)) {
            throw new UnexpectedValueException('composer licenses retornou uma dependência inválida.');
        }
        $versaoBruta = $pacote['version'] ?? null;
        $versao = is_string($versaoBruta) ? $versaoBruta : '?';
        $licencasBrutas = $pacote['license'] ?? null;
        $licencas = [];
        if (is_array($licencasBrutas)) {
            foreach ($licencasBrutas as $licenca) {
                if (is_string($licenca)) {
                    $licencas[] = $licenca;
                }
            }
        }
        if (array_intersect($licencas, $permissivas) !== []) {
            continue;
        }
        if (str_starts_with($nome, 'tecnickcom/')
            && in_array('LGPL-3.0-or-later', $licencas, true)
        ) {
            continue;
        }
        $reprovadas[] = sprintf(
            '%s@%s (%s)',
            $nome,
            $versao,
            $licencas === [] ? 'sem licença declarada' : implode(' OR ', $licencas),
        );
    }

    return $reprovadas;
}

/** @param array<mixed> $relatorio */
function quantidadeDeDependencias(array $relatorio): int
{
    $dependencias = $relatorio['dependencies'] ?? null;
    if (!is_array($dependencias)) {
        throw new UnexpectedValueException('composer licenses retornou uma estrutura inválida.');
    }

    return count($dependencias);
}

function validarCarregamentoDeProducao(callable $carregar): void
{
    $carregar();
}

/**
 * Ratchet de tamanho: retirar arquivos do escopo não equivale a pagar dívida.
 * Uma redução legítima exige medição explícita e revisão em commit próprio.
 * $folga permanece parametrizada para a validação dos contratos existentes.
 */
function escopoEncolheu(int $medido, int $referencia, float $folga = 0.0): bool
{
    if ($medido < 0 || $referencia < 0 || $folga < 0 || $folga > 1) {
        throw new UnexpectedValueException('Escopo de baseline inválido.');
    }

    return $medido < $referencia * (1 - $folga);
}

/** @return array<string, array{nome: string, gatilhos: list<string>, jobs: list<string>, exclusivos: bool}> */
function contratoDosWorkflows(): array
{
    return [
        'ci.yml' => [
            'nome' => 'ci',
            'gatilhos' => ['push', 'pull_request', 'workflow_dispatch'],
            'jobs' => ['commit-messages', 'tests', 'quality', 'workflows', 'reports'],
            'exclusivos' => false,
        ],
        'security.yml' => ['nome' => 'security', 'gatilhos' => ['push', 'pull_request', 'schedule', 'workflow_dispatch'], 'jobs' => ['dependencies', 'gitleaks', 'semgrep'], 'exclusivos' => false],
    ];
}

/**
 * @param array<string, string> $workflows
 * @return list<string>
 */
function problemasNaEstruturaDosWorkflows(array $workflows): array
{
    $contrato = contratoDosWorkflows();
    $problemas = [];

    foreach ($contrato as $arquivo => $esperado) {
        if (!array_key_exists($arquivo, $workflows)) {
            $problemas[] = $arquivo . ' não existe.';
            continue;
        }
        $linhas = preg_split('/\\R/u', $workflows[$arquivo]);
        if ($linhas === false) {
            $linhas = [];
        }
        $nome = null;
        $secao = null;
        $gatilhos = [];
        $jobs = [];
        foreach ($linhas as $linha) {
            if (preg_match('/^name:\\s*(.+?)\\s*$/u', $linha, $partes) === 1) {
                $nome = trim($partes[1], " \\t\\\"'");
                continue;
            }
            if (preg_match('/^(on|jobs):\\s*$/u', $linha, $partes) === 1) {
                $secao = $partes[1];
                continue;
            }
            if (preg_match('/^[^ #]/u', $linha) === 1) {
                $secao = null;
                continue;
            }
            if ($secao === 'on' && preg_match('/^  ([a-z_]+):/u', $linha, $partes) === 1) {
                $gatilhos[] = $partes[1];
            }
            if ($secao === 'jobs' && preg_match('/^  ([a-zA-Z0-9_-]+):/u', $linha, $partes) === 1) {
                $jobs[] = $partes[1];
            }
        }

        if ($nome !== $esperado['nome']) {
            $problemas[] = sprintf('%s: `name:` é `%s`, esperado `%s`.', $arquivo, $nome ?? 'ausente', $esperado['nome']);
        }
        foreach ($esperado['gatilhos'] as $gatilho) {
            if (!in_array($gatilho, $gatilhos, true)) {
                $problemas[] = sprintf('%s: não dispara em %s.', $arquivo, $gatilho);
            }
        }
        if ($esperado['exclusivos']) {
            foreach (array_diff($gatilhos, $esperado['gatilhos']) as $gatilho) {
                $problemas[] = sprintf('%s: dispara também em %s.', $arquivo, $gatilho);
            }
        }
        foreach ($esperado['jobs'] as $job) {
            if (!in_array($job, $jobs, true)) {
                $problemas[] = sprintf('%s: falta o job %s.', $arquivo, $job);
            }
        }
    }

    return $problemas;
}

/**
 * @param callable(list<string>): array{status: int, stdout: string, stderr: string} $executor
 * @param array<string, string>|null $workflows
 */
function validarWorkflows(callable $executor, bool $diretorioExiste, ?array $workflows = null): string
{
    if (!$diretorioExiste) {
        throw new RuntimeException('.github/workflows não existe; não há workflow para validar.');
    }
    foreach ([['shellcheck', '--version'], ['actionlint', '-version']] as $versao) {
        $resultado = $executor($versao);
        if ($resultado['status'] !== 0) {
            throw new RuntimeException($versao[0] . ' ausente ou falhou ao executar a versão.');
        }
    }
    // `-no-color` é a opção explícita documentada pelo actionlint. A opção
    // booleana antiga `-color=false` ainda é aceita pela CLI, mas não aparece
    // na ajuda como forma portátil de desligar a cor.
    $resultado = $executor(['actionlint', '-no-color', '-shellcheck=shellcheck --norc']);
    if ($resultado['status'] !== 0) {
        $detalhe = trim($resultado['stderr'] . "\n" . $resultado['stdout']);
        throw new RuntimeException(
            'actionlint reprovou os workflows' . ($detalhe === '' ? '.' : ":\n{$detalhe}"),
        );
    }

    if ($workflows !== null) {
        $problemas = problemasNaEstruturaDosWorkflows($workflows);
        if ($problemas !== []) {
            throw new RuntimeException("Estrutura de workflows fora do contrato:\n- " . implode("\n- ", $problemas));
        }
    }

    return 'Workflows válidos com actionlint, shellcheck e estrutura no contrato.';
}

/**
 * @param list<string> $arquivos
 * @return list<string>
 */
/**
 * Slug de título como o GitHub gera: remove HTML e marcação, passa a
 * minúsculas, descarta pontuação e troca espaço por hífen. Título repetido
 * ganha sufixo numérico, na ordem em que aparece.
 *
 * Acento e underscore são PRESERVADOS: `#portões` e `#next_public` são as
 * âncoras reais de "Portões" e "NEXT_PUBLIC". Por isso o underscore não entra
 * na lista de marcadores de ênfase removidos.
 */
function ancoraDeTitulo(string $titulo): string
{
    $limpo = (string) preg_replace('/<[^>]+>/u', '', $titulo);
    $limpo = (string) preg_replace('/[`*~]/u', '', $limpo);
    $limpo = mb_strtolower(trim($limpo), 'UTF-8');
    $limpo = (string) preg_replace('/[^\p{L}\p{M}\p{N}\s_-]/u', '', $limpo);

    // Espaço vira hífen UM A UM, sem colapsar: o GitHub remove a pontuação e
    // mantém os espaços que sobram, então `x — y` vira `x--y`, com dois hífens.
    return (string) preg_replace('/\s/u', '-', $limpo);
}

/**
 * Âncoras que o GitHub cria a partir dos títulos do documento.
 *
 * Bloco de código fica de fora: `# comentário` dentro de ``` não vira título,
 * e tratá-lo como um inventaria âncora que não existe na página renderizada.
 *
 * @return array<string, true>
 */
function ancorasDoMarkdown(string $conteudo): array
{
    $ancoras = [];
    $repeticoes = [];
    $emBloco = false;
    $linhas = preg_split('/\R/u', $conteudo);
    if ($linhas === false) {
        return [];
    }

    foreach ($linhas as $linha) {
        if (preg_match('/^\s*(```|~~~)/u', $linha) === 1) {
            $emBloco = !$emBloco;
            continue;
        }
        if ($emBloco || preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/u', $linha, $titulo) !== 1) {
            continue;
        }
        $base = ancoraDeTitulo($titulo[1]);
        $repeticao = $repeticoes[$base] ?? 0;
        $repeticoes[$base] = $repeticao + 1;
        $ancoras[$repeticao === 0 ? $base : $base . '-' . $repeticao] = true;
    }

    return $ancoras;
}

/**
 * Alvos de link de uma linha: os inline `[x](destino)` e os de referência
 * `[x]: destino`.
 *
 * @return list<string>
 */
function alvosDaLinha(string $linha): array
{
    preg_match_all(
        '/!?\[[^\]]*\]\((?<alvo><[^>]+>|[^)\s]+)(?:\s+(?:"[^"]*"|\'[^\']*\'))?\)/u',
        $linha,
        $encontrados,
    );
    $alvos = $encontrados['alvo'];

    if (preg_match('/^\s*\[[^\]]+\]:\s*(\S+)/u', $linha, $referencia) === 1) {
        $alvos[] = $referencia[1];
    }

    return $alvos;
}

/**
 * Confere que todo link local aponta para arquivo existente e, quando traz
 * fragmento para um Markdown, que a âncora existe lá.
 *
 * Conferir só o arquivo deixa passar o erro mais comum de uma reorganização:
 * o documento continua existindo e a seção citada mudou de nome.
 *
 * @param  list<string> $arquivos
 * @return list<string>
 */
function problemasNosLinks(string $raiz, array $arquivos): array
{
    $problemas = [];
    $cacheDeAncoras = [];

    foreach ($arquivos as $arquivo) {
        $caminho = $raiz . '/' . $arquivo;
        $conteudo = file_get_contents($caminho);
        if (!is_string($conteudo)) {
            $problemas[] = "{$arquivo}: não foi possível ler o arquivo";
            continue;
        }

        $linhas = preg_split('/\R/u', $conteudo);
        if ($linhas === false) {
            $problemas[] = "{$arquivo}: não foi possível separar as linhas";
            continue;
        }

        $emBloco = false;
        foreach ($linhas as $linha) {
            if (preg_match('/^\s*(```|~~~)/u', $linha) === 1) {
                $emBloco = !$emBloco;
                continue;
            }
            if ($emBloco) {
                continue;
            }

            foreach (alvosDaLinha($linha) as $alvoBruto) {
                $alvo = trim($alvoBruto, '<>');
                if ($alvo === '' || str_starts_with($alvo, '//')
                    || preg_match('/\A[a-z][a-z0-9+.-]*:/i', $alvo) === 1
                ) {
                    continue;
                }

                $partes = explode('#', $alvo, 2);
                $fragmento = $partes[1] ?? '';
                $semConsulta = explode('?', $partes[0], 2)[0];
                $relativo = rawurldecode($semConsulta);

                // Fragmento sem caminho aponta para o próprio documento.
                $destino = $relativo === ''
                    ? $caminho
                    : (str_starts_with($relativo, '/')
                        ? $raiz . $relativo
                        : dirname($caminho) . '/' . $relativo);

                if (!file_exists($destino)) {
                    $problemas[] = "{$arquivo}: link local inexistente: {$alvo}";
                    continue;
                }
                if ($fragmento === '' || is_dir($destino)
                    || preg_match('/\.(md|markdown)$/i', $destino) !== 1
                ) {
                    continue;
                }

                $real = realpath($destino);
                $chave = $real === false ? $destino : $real;
                if (!isset($cacheDeAncoras[$chave])) {
                    $textoAlvo = file_get_contents($destino);
                    $cacheDeAncoras[$chave] = is_string($textoAlvo)
                        ? ancorasDoMarkdown($textoAlvo)
                        : [];
                }

                $ancora = mb_strtolower(rawurldecode($fragmento), 'UTF-8');
                if (!isset($cacheDeAncoras[$chave][$ancora])) {
                    $problemas[] = "{$arquivo}: âncora inexistente: {$alvo}";
                }
            }
        }
    }

    return $problemas;
}

function mensagemDeThrowable(Throwable $erro): string
{
    return $erro->getMessage() === '' ? $erro::class : $erro->getMessage();
}
