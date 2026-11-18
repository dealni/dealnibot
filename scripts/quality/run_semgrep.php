<?php

declare(strict_types=1);

/*
 * Roda o Semgrep com as regras de tools/quality/semgrep/project/php.yaml, em três etapas:
 *
 *   1. REGRESSÕES — confirma ruleid/ok das formas globais e mistas com --test.
 *
 *   2. AUTOTESTE — varre tools/quality/semgrep/project/fixtures/, onde cada regra tem um caso
 *      que ela PRECISA pegar. Se alguma regra não disparar, o gate reprova aqui
 *      mesmo. Sem isto, um pattern quebrado numa atualização do Semgrep viraria
 *      um "0 achados" indistinguível de código limpo.
 *
 *   3. VARREDURA — o repositório inteiro, esperando zero achado.
 *
 * Sem o semgrep instalado o gate reprova. Um agregador de qualidade que passa
 * sem executar uma de suas ferramentas produziria um falso verde local.
 */

$raiz = dirname(__DIR__, 2);
$regras = $raiz . '/tools/quality/semgrep/project/php.yaml';
$fixtures = $raiz . '/tools/quality/semgrep/project/fixtures';

if (!is_file($regras)) {
    fwrite(STDERR, "Regras não encontradas: {$regras}\n");
    exit(2);
}

$semgrep = trim((string) shell_exec('command -v semgrep 2>/dev/null'));
if ($semgrep === '') {
    fwrite(STDERR, "semgrep não instalado — a análise de fluxo NÃO rodou.\n");
    fwrite(STDERR, "    brew install semgrep (macOS), pipx install semgrep, ou veja\n");
    fwrite(STDERR, "    o job \"semgrep\" de .github/workflows/security.yml.\n");
    exit(2);
}

// Semgrep (desde 1.174) inicializa o cliente HTTP do `semgrep-core` mesmo com
// `--metrics=off`. Em macOS, alguns runners não exportam `SSL_CERT_FILE` e a
// biblioteca OCaml não encontra os anchors do sistema por conta própria,
// abortando a validação de TODAS as regras antes de analisá-las. O mesmo
// runner pode ainda ter HOME somente leitura, o que impede o log auxiliar do
// Semgrep em `~/.semgrep`. O gate usa apenas stdout/stderr estruturados, então
// um log descartável é suficiente e não esconde nenhum achado.
$semgrepLog = getenv('SEMGREP_LOG_FILE');
if (!is_string($semgrepLog) || $semgrepLog === '') {
    putenv('SEMGREP_LOG_FILE=' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
}

$caCandidatos = [];
$caConfigurado = getenv('SSL_CERT_FILE');
if (is_string($caConfigurado) && $caConfigurado !== '') {
    $caCandidatos[] = $caConfigurado;
}
$caCandidatos = array_merge(
    $caCandidatos,
    PHP_OS_FAMILY === 'Darwin'
        ? ['/etc/ssl/cert.pem', '/opt/homebrew/etc/openssl@3/cert.pem']
        : ['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt']
);
foreach ($caCandidatos as $caCandidato) {
    if (!is_file($caCandidato) || !is_readable($caCandidato)) {
        continue;
    }
    $tamanhoCa = @filesize($caCandidato);
    if (is_int($tamanhoCa) && $tamanhoCa > 0) {
        putenv('SSL_CERT_FILE=' . $caCandidato);
        break;
    }
}

$conteudoDasRegras = file_get_contents($regras);
if (!is_string($conteudoDasRegras)) {
    fwrite(STDERR, "Não foi possível ler as regras: {$regras}\n");
    exit(2);
}
preg_match_all('/^\s*-\s*id:\s*(\S+)\s*$/m', $conteudoDasRegras, $encontradas);
$regrasDeclaradas = $encontradas[1];

if ($regrasDeclaradas === []) {
    fwrite(STDERR, "Nenhuma regra declarada em {$regras}.\n");
    exit(2);
}

/**
 * @param list<string> $regrasDeclaradas
 * @return array{achados: list<array{regra: string, local: string}>, erros: list<string>}
 */
function executarSemgrep(
    string $semgrep,
    string $regras,
    string $alvo,
    bool $comExclusoes,
    array $regrasDeclaradas,
): array {
    $comando = [
        $semgrep,
        'scan',
        '--config',
        $regras,
        '--metrics=off',
        '--json',
        '--quiet',
        '--disable-version-check',
        // O contrato do repositório não permite que um comentário esconda um
        // achado. A CLI habilita `nosemgrep` por padrão; desligá-lo aqui faz a
        // política escrita em docs/SEGURANCA.md valer tanto localmente quanto no CI.
        '--disable-nosem',
        '--strict',
    ];

    if ($comExclusoes) {
        foreach (['vendor', 'logs', 'notas_emitidas', 'tools/quality/semgrep/project/fixtures', '.artifacts'] as $excluido) {
            $comando[] = '--exclude=' . $excluido;
        }
    }

    $comando[] = $alvo;

    $stdout = tmpfile();
    $stderr = tmpfile();
    if ($stdout === false || $stderr === false) {
        return ['achados' => [], 'erros' => ['não foi possível criar os arquivos temporários do Semgrep']];
    }

    $pipes = [];
    $processo = proc_open($comando, [1 => $stdout, 2 => $stderr], $pipes);
    if (!is_resource($processo)) {
        fclose($stdout);
        fclose($stderr);

        return ['achados' => [], 'erros' => ['não foi possível iniciar o Semgrep']];
    }

    $status = proc_close($processo);
    rewind($stdout);
    rewind($stderr);
    $saida = stream_get_contents($stdout);
    $saidaDeErro = stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);

    if (!is_string($saida) || !is_string($saidaDeErro)) {
        return ['achados' => [], 'erros' => ['não foi possível ler a saída do Semgrep']];
    }

    try {
        $decodificado = json_decode($saida, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        $decodificado = null;
    }
    if (!is_array($decodificado)) {
        $detalhe = trim($saidaDeErro);

        return [
            'achados' => [],
            'erros' => [
                'Semgrep não produziu JSON válido'
                    . ($detalhe === '' ? '.' : ': ' . $detalhe),
            ],
        ];
    }

    if (!isset($decodificado['results'], $decodificado['errors'])
        || !is_array($decodificado['results'])
        || !is_array($decodificado['errors'])
    ) {
        return [
            'achados' => [],
            'erros' => ['Semgrep produziu JSON sem `results`/`errors` como listas.'],
        ];
    }

    $achados = [];
    $erros = [];
    foreach ($decodificado['results'] as $achado) {
        if (!is_array($achado)) {
            $erros[] = 'Semgrep produziu um resultado que não é objeto.';
            continue;
        }

        // check_id vem qualificado pelo caminho do arquivo de regras
        // ("tools.semgrep.sql-montado-por-interpolacao"). Não basta pegar o
        // último segmento: IDs válidos podem conter pontos. O sufixo exato
        // contra a lista declarada preserva o ID inteiro e falha fechado se a
        // saída trouxer uma regra que não existe no YAML.
        $identificador = $achado['check_id'] ?? '?';
        if (!is_string($identificador)) {
            $identificador = '?';
        }
        $regra = $identificador;
        foreach ($regrasDeclaradas as $declarada) {
            if ($identificador === $declarada || str_ends_with($identificador, '.' . $declarada)) {
                $regra = $declarada;
                break;
            }
        }
        if ($regra === $identificador && !in_array($identificador, $regrasDeclaradas, true)) {
            $erros[] = "Semgrep produziu achado de regra não declarada: {$identificador}";
            continue;
        }

        $caminho = $achado['path'] ?? '?';
        if (!is_string($caminho)) {
            $caminho = '?';
        }
        $inicio = $achado['start'] ?? null;
        $linha = is_array($inicio) && is_int($inicio['line'] ?? null)
            ? $inicio['line']
            : 0;

        $achados[] = [
            'regra' => $regra,
            'local' => $caminho . ':' . $linha,
        ];
    }

    foreach ($decodificado['errors'] as $erro) {
        if (!is_array($erro)) {
            $erros[] = 'Semgrep produziu um erro que não é objeto.';
            continue;
        }

        $mensagem = $erro['message'] ?? null;
        if (!is_string($mensagem)) {
            $codificado = json_encode($erro, JSON_INVALID_UTF8_SUBSTITUTE);
            $mensagem = $codificado === false ? 'erro não legível do Semgrep' : $codificado;
        }

        $erros[] = $mensagem;
    }

    if ($status !== 0) {
        $detalhe = trim($saidaDeErro);
        $erros[] = 'Semgrep terminou com status ' . $status
            . ($detalhe === '' ? '.' : ': ' . $detalhe);
    }

    return ['achados' => $achados, 'erros' => $erros];
}

// --- 1. Regressões -----------------------------------------------------------
// Confere cada detecção e cada ausência esperadas, inclusive as formas globais.
// A presença de um achado por regra, sozinha, não percebe uma variante muda
// nem um sanitizador que passou a gerar falso positivo no parser PHP.
$regressoes = $fixtures . '/globais.php';
if (!is_file($regressoes)) {
    fwrite(STDERR, "Fixtures de regressão não encontradas: {$regressoes}\n");
    exit(2);
}

$comandoDeRegressao = [
    $semgrep,
    'scan',
    '--test',
    '--config',
    $regras,
    '--metrics=off',
    '--disable-version-check',
    '--strict',
    $regressoes,
];
$pipesDeRegressao = [];
$processoDeRegressao = proc_open($comandoDeRegressao, [1 => STDOUT, 2 => STDERR], $pipesDeRegressao);
if (!is_resource($processoDeRegressao)) {
    fwrite(STDERR, "Não foi possível iniciar as regressões do Semgrep.\n");
    exit(1);
}
if (proc_close($processoDeRegressao) !== 0) {
    fwrite(STDERR, "Semgrep: uma regressão de detecção ou sanitização falhou.\n");
    exit(1);
}

// --- 2. Autoteste ------------------------------------------------------------
$autoteste = executarSemgrep($semgrep, $regras, $fixtures, false, $regrasDeclaradas);
if ($autoteste['erros'] !== []) {
    fwrite(STDERR, "Semgrep não conseguiu carregar as regras:\n");
    foreach ($autoteste['erros'] as $erro) {
        fwrite(STDERR, "    {$erro}\n");
    }
    exit(1);
}

$dispararam = [];
foreach ($autoteste['achados'] as $achado) {
    $dispararam[$achado['regra']] = true;
}

$mudas = array_values(array_filter(
    $regrasDeclaradas,
    static fn (string $regra): bool => !isset($dispararam[$regra])
));

if ($mudas !== []) {
    fwrite(STDERR, "Regra do Semgrep que não pegou o próprio exemplo em tools/quality/semgrep/project/fixtures/:\n");
    foreach ($mudas as $regra) {
        fwrite(STDERR, "    {$regra}\n");
    }
    fwrite(STDERR, "Regra que não dispara não protege nada. Conserte o pattern ou o exemplo.\n");
    exit(1);
}

printf("Semgrep: %d regras, todas disparando no autoteste.\n", count($regrasDeclaradas));

// --- 3. Varredura ------------------------------------------------------------
$varredura = executarSemgrep($semgrep, $regras, $raiz, true, $regrasDeclaradas);

if ($varredura['erros'] !== []) {
    fwrite(STDERR, "Semgrep terminou com erro:\n");
    foreach ($varredura['erros'] as $erro) {
        fwrite(STDERR, "    {$erro}\n");
    }
    exit(1);
}

if ($varredura['achados'] !== []) {
    fwrite(STDERR, "Semgrep encontrou " . count($varredura['achados']) . " problema(s):\n");
    foreach ($varredura['achados'] as $achado) {
        fwrite(STDERR, "    {$achado['regra']} — {$achado['local']}\n");
    }
    fwrite(STDERR, "Conserte no código. `nosemgrep` não é opção: some da vista.\n");
    exit(1);
}

echo "Semgrep: nenhum achado.\n";
exit(0);
