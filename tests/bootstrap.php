<?php

/**
 * Bootstrap dos testes PHPUnit.
 * Carrega o autoload do Composer e os helpers do bot de forma isolada.
 *
 * Cada execução ganha um id aleatório, herdado pelos subprocessos; cada
 * processo ganha diretórios temporários próprios (0700), removidos no
 * encerramento. Duas execuções simultâneas (dois agentes ou processos locais) nunca compartilham arquivo de log nem de update.
 */

declare(strict_types=1);

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
// Prefixo das variáveis de ambiente da suíte: <PREFIXO>_TEST_RUN_ID,
// <PREFIXO>_TEST_PRESERVE_DIRS, <PREFIXO>_LOGS_DIR e <PREFIXO>_UPDATES_DIR.
$prefixoDoAmbiente = 'DEALNI';
// Prefixo dos diretórios temporários por processo em sys_get_temp_dir().
$prefixoDosDiretoriosTemporarios = 'dealnibot-testes-';
// Este bootstrap NÃO define constante nenhuma — nem de credencial, nem de
// regra de negócio: o stub que existia era herança sem contraparte em
// inclusos/dados_bot.php deste bot, e saiu para que ninguém escreva teste novo
// em cima dele.
/** @var array<string, mixed> $constantesDoBot */
$constantesDoBot = [];
/** @var array<string, array{0: string, 1: string}> $constantesDoBotVindasDoAmbiente */
$constantesDoBotVindasDoAmbiente = [];
/** @var array<string, string> $caminhosDoVendorComoConstante */
$caminhosDoVendorComoConstante = [];
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

require_once __DIR__ . '/../vendor/autoload.php';

if (!extension_loaded('pdo')) {
    throw new RuntimeException('A extensão nativa pdo é obrigatória para a suíte.');
}

// Composer considera symfony/polyfill-ctype um provedor válido para
// `ext-ctype` durante a instalação das ferramentas de desenvolvimento. A
// aplicação, porém, usa as funções nativas e precisa da extensão real para
// preservar a semântica/locale do PHP; não deixe o polyfill mascarar um
// runtime de CI incompleto.
if (!extension_loaded('ctype')) {
    throw new RuntimeException('A extensão nativa ctype é obrigatória; o polyfill não substitui o runtime.');
}

// Composer pode resolver symfony/polyfill-mbstring para as ferramentas de
// desenvolvimento, mas a aplicação chama mb_* nativas. A suíte precisa
// falhar cedo se o runtime não tiver a extensão real, em vez de mascarar uma
// instalação PHP incompleta.
if (!extension_loaded('mbstring')) {
    throw new RuntimeException('A extensão nativa mbstring é obrigatória; o polyfill não substitui o runtime.');
}

if (!App\Support\RuntimeEnvironment::isTesting()) {
    throw new RuntimeException('A suíte só pode ser executada com o ambiente de teste habilitado.');
}

if (!App\Support\RuntimeEnvironment::hasNetworkIsolation()) {
    throw new RuntimeException('Rede externa habilitada. Execute os testes pelos scripts do Composer.');
}

$diretorioLogs = dirname(__DIR__) . '/logs';
if (!is_dir($diretorioLogs) && !mkdir($diretorioLogs, 0777, true) && !is_dir($diretorioLogs)) {
    throw new RuntimeException('Nao foi possivel criar o diretorio de logs dos testes.');
}

$variavelRunId = $prefixoDoAmbiente . '_TEST_RUN_ID';
$variavelPreservarDiretorios = $prefixoDoAmbiente . '_TEST_PRESERVE_DIRS';
$variavelLogs = $prefixoDoAmbiente . '_LOGS_DIR';
$variavelUpdates = $prefixoDoAmbiente . '_UPDATES_DIR';

$runId = getenv($variavelRunId);
if (!is_string($runId) || preg_match('/\A[a-f0-9]{32}\z/', $runId) !== 1) {
    $runId = bin2hex(random_bytes(16));
    putenv($variavelRunId . '=' . $runId);
}
$_ENV[$variavelRunId] = $runId;

$pid = (string) getmypid();
$nonceProcesso = bin2hex(random_bytes(8));
/** @var list<string> $diretoriosTemporariosDoProcesso */
$diretoriosTemporariosDoProcesso = [];

// Só os diretórios registrados abaixo são removidos. Em particular, um
// subprocesso que herda um diretório explicitamente não pode apagar o
// diretório do pai ou de um irmão no encerramento.
$apagarDiretorioTemporario = static function (string $caminho) use (&$apagarDiretorioTemporario): void {
    if (is_link($caminho) || is_file($caminho)) {
        @unlink($caminho);

        return;
    }
    if (!is_dir($caminho)) {
        return;
    }

    $entradas = @scandir($caminho);
    if ($entradas === false) {
        return;
    }
    foreach ($entradas as $entrada) {
        if ($entrada !== '.' && $entrada !== '..') {
            $apagarDiretorioTemporario($caminho . '/' . $entrada);
        }
    }
    @rmdir($caminho);
};

register_shutdown_function(static function () use (&$diretoriosTemporariosDoProcesso, $apagarDiretorioTemporario): void {
    foreach ($diretoriosTemporariosDoProcesso as $diretorio) {
        $apagarDiretorioTemporario($diretorio);
    }
});

$criarDiretorioTemporario = static function (string $sufixo) use (
    $prefixoDosDiretoriosTemporarios,
    $runId,
    $pid,
    $nonceProcesso,
    &$diretoriosTemporariosDoProcesso,
): string {
    $caminho = sys_get_temp_dir() . '/' . $prefixoDosDiretoriosTemporarios . $sufixo . '-'
        . $runId . '-' . $pid . '-' . $nonceProcesso;
    if (file_exists($caminho) || is_link($caminho) || !mkdir($caminho, 0700)) {
        throw new RuntimeException('Nao foi possivel criar o diretorio temporario dos testes.');
    }

    $diretoriosTemporariosDoProcesso[] = $caminho;

    return $caminho;
};

// O padrão é um diretório por processo: o run id comum agrupa uma execução,
// enquanto PID + nonce impedem colisões entre processos do mesmo run. Um filho
// que precise compartilhar os diretórios pode pedir isso explicitamente antes
// de carregar o bootstrap, mantendo os valores já herdados.
$preservarDiretoriosHerdados = getenv($variavelPreservarDiretorios) === '1';

// A suíte grava o app.log num diretório temporário: sem isto ela sujaria o
// logs/ de produção deste checkout.
$logsHerdados = getenv($variavelLogs);
$diretorioLogsTestes = $preservarDiretoriosHerdados && is_string($logsHerdados) && $logsHerdados !== ''
    ? $logsHerdados
    : $criarDiretorioTemporario('logs');
putenv($variavelLogs . '=' . $diretorioLogsTestes);
$_ENV[$variavelLogs] = $diretorioLogsTestes;
App\Support\LoggerFactory::reset();

// Quem grava updates/*.json por update lê este diretório; sem o redirect, a
// suíte poluiria o updates/ de produção.
$updatesHerdados = getenv($variavelUpdates);
$diretorioUpdatesTestes = $preservarDiretoriosHerdados
    && is_string($updatesHerdados)
    && $updatesHerdados !== ''
    ? $updatesHerdados
    : $criarDiretorioTemporario('updates');
putenv($variavelUpdates . '=' . $diretorioUpdatesTestes);
$_ENV[$variavelUpdates] = $diretorioUpdatesTestes;

// Caminhos do vendor que uma biblioteca espera como constante (relativos a
// vendor/), resolvidos aqui porque o vendor é o do projeto raiz.
foreach ($caminhosDoVendorComoConstante as $nomeConstante => $relativoAoVendor) {
    if (defined($nomeConstante)) {
        continue;
    }
    $caminhoNoVendor = realpath(__DIR__ . '/../vendor/' . $relativoAoVendor);
    if (is_string($caminhoNoVendor)) {
        define($nomeConstante, $caminhoNoVendor . DIRECTORY_SEPARATOR);
    }
}

// `?:` cairia em qualquer falsy; o que interessa aqui é "não veio ou veio em
// branco" — chave vazia não instancia classe nenhuma.
foreach ($constantesDoBotVindasDoAmbiente as $nomeConstante => [$variavel, $padrao]) {
    $valor = getenv($variavel);
    $constantesDoBot[$nomeConstante] = is_string($valor) && $valor !== '' ? $valor : $padrao;
}

// As constantes entram por um laço, e não por `define('NOME', valor)` escrito
// à mão: inclusos/dados_bot.php também as define, e o PHPStan — que analisa
// tests/ junto com o resto — passaria a ver duas definições da mesma constante.
foreach ($constantesDoBot as $nomeConstante => $valorConstante) {
    if (!defined($nomeConstante)) {
        define($nomeConstante, $valorConstante);
    }
}
unset($constantesDoBot, $constantesDoBotVindasDoAmbiente, $caminhosDoVendorComoConstante, $nomeConstante, $valorConstante);

require_once __DIR__ . '/../inclusos/funcoes.php';
require_once __DIR__ . '/../inclusos/roteamento.php';

require_once __DIR__ . '/Support/BancoTeste.php';
putenv('TEST_DATABASE_DSN=' . BancoTeste::dsn());
