<?php

declare(strict_types=1);

use App\Support\LoggerFactory;

require_once __DIR__ . '/funcoes_recusa_de_escrita.php';

/**
 * Endereço do Redis lido do ambiente, com os padrões usados em produção.
 *
 * Fica separada de conectarRedis() porque é a única parte da conexão que dá
 * para exercitar sem um Redis de verdade: conectarRedis() encerra o processo
 * com exit(1) em qualquer falha, então as bordas do ambiente (variável vazia,
 * porta não numérica, porta negativa) só são testáveis aqui.
 *
 * @return array{host: string, port: int} Host e porta já normalizados
 */
function enderecoDoRedis(): array
{
    $redisHost = getenv('REDIS_HOST');
    $redisPort = getenv('REDIS_PORT');
    $porta = is_string($redisPort) && ctype_digit($redisPort) ? (int) $redisPort : 0;

    // ctype_digit() (e não is_numeric()) de propósito: "6380.5", "-1" e " 6380"
    // não são portas válidas e precisam cair no padrão em vez de virar um
    // inteiro truncado que o connect() recusaria em produção.
    //
    // Nenhum trim é aplicado ao host: um espaço acidental no .env precisa
    // aparecer no erro de conexão em vez de ser corrigido em silêncio.
    return [
        'host' => is_string($redisHost) && $redisHost !== '' ? $redisHost : '127.0.0.1',
        'port' => $porta >= 1 && $porta <= 65535 ? $porta : 6380,
    ];
}

/**
 * Conecta ao Redis com verificação de erro.
 *
 * Em caso de falha, registra no app.log com o canal erros_redis,
 * imprime a mensagem de erro e encerra o processo com exit(1).
 *
 * @param bool $persistent Se deve usar conexão persistente
 * @return Redis Instância do Redis conectada
 */
function conectarRedis(bool $persistent = false, ?Redis $cliente = null): Redis
{
    // Indireção por string de propósito: mantém a suíte capaz de injetar um
    // stub puro de Redis num subprocesso `php -n` sem ext-redis carregada.
    $redisClass = 'Redis';
    $redis = $cliente ?? new $redisClass();
    $endereco = enderecoDoRedis();
    $host = $endereco['host'];
    $port = $endereco['port'];

    // Tenta conectar com limites explícitos de conexão e leitura. O phpredis
    // usa o timeout do php.ini quando estes valores ficam em zero; em uma
    // queda ou em um servidor que aceita o socket mas não responde, isso pode
    // prender o webhook até o timeout do FPM. O mesmo teto vale para as duas
    // fases, antes e depois de a conexão ser estabelecida.
    $timeout = 2.0;
    // A partir do phpredis 6 connect()/pconnect() LANÇAM RedisException em vez
    // de devolver false. Sem este catch, a queda do Redis virava exceção não
    // tratada, antes da mensagem de indisponibilidade.
    // O cliente também emite warnings de rede; a falha é registrada abaixo.
    $conectado = estabelecerConexaoRedis($redis, $host, $port, $timeout, $persistent);

    // Verifica se a conexão foi bem-sucedida
    if (!$conectado) {
        encerrarPorFalhaDoRedis("Erro: Serviço Redis não está disponível. Tente novamente mais tarde.");
    }

    // Testa se o Redis está realmente funcionando
    try {
        if ($redis->ping() === false) {
            throw new RuntimeException('Redis não respondeu ao ping.');
        }
    } catch (Exception $e) {
        $mensagemErro = "Erro: Redis não está respondendo: " . $e->getMessage() . " em " . date('Y-m-d H:i:s');

        escreverLog($mensagemErro, 'erros_redis', true, 'error', ['phase' => 'ping', 'exception' => $e]);

        encerrarPorFalhaDoRedis("Erro: Serviço Redis não está funcionando. Tente novamente mais tarde.");
    }

    return $redis;
}

function estabelecerConexaoRedis(Redis $redis, string $host, int $port, float $timeout, bool $persistent): bool
{
    $fase = 'connect';
    try {
        if ($persistent) {
            $conectado = @$redis->pconnect($host, $port, $timeout, '', 0, $timeout);
        } else {
            $conectado = @$redis->connect($host, $port, $timeout, '', 0, $timeout);
        }
        $senha = getenv('REDIS_PASSWORD');
        if ($conectado && is_string($senha) && $senha !== '') {
            $fase = 'auth';
            $conectado = $redis->auth($senha) === true;
        }
    } catch (Exception $e) {
        escreverLog('Falha de conexão Redis', 'erros_redis', true, 'error', ['phase' => $fase, 'exception' => $e]);
        return false;
    }
    if (!$conectado) {
        escreverLog('Redis recusou a operação', 'erros_redis', true, 'error', ['phase' => $fase]);
    }

    return $conectado;
}

function encerrarPorFalhaDoRedis(string $mensagem): never
{
    // O webhook ainda não persistiu o update. Recusar com 503 permite
    // reentrega; exit(1) sozinho mantém HTTP 200 e perde a mensagem.
    http_response_code(503);
    echo $mensagem;
    exit(1);
}

/**
 * Escreve mensagem no app.log JSON (Monolog), canal = 2º argumento.
 *
 * Substitui a gravação legada em logs/{canal}.log por uma linha JSON em
 * logs/app-YYYY-MM-DD.log, com o antigo nome de arquivo virando o NOME DO
 * CANAL Monolog.
 *
 * @param string $mensagem Mensagem a ser registrada
 * @param string $arquivo_log Nome do canal Monolog (basename; path traversal descartado)
 * @param bool $forcar Grava mesmo com LOGS_ATIVO=false (falhas operacionais)
 * @param array<string, mixed> $contexto Metadados operacionais, sem payload
 */
function escreverLog(string $mensagem, string $arquivo_log = 'geral', bool $forcar = false, string $nivel = 'info', array $contexto = []): void
{
    if (!class_exists(LoggerFactory::class)) {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    LoggerFactory::channel($arquivo_log, $forcar)->log($nivel, $mensagem, $contexto);
}

/**
 * Registra falha não tratada do roteador sem derrubar o resto do request.
 *
 * As guardas de `contexto_script.php` transformam contexto inesperado em
 * exceção, e esse é o desfecho certo: seguir com valor errado num fluxo de
 * dinheiro é pior do que parar. O problema é onde a exceção caía. O roteador é
 * incluído DEPOIS de o 200 já ter ido para o Telegram, então ela não vira erro
 * HTTP nem reentrega — apenas encerrava o processo em silêncio, sem resposta ao
 * usuário e sem uma linha de log.
 *
 * `forcar: true` porque exceção não tratada é exatamente o evento que ninguém
 * programou: ela precisa aparecer mesmo com LOGS_ATIVO desligado.
 *
 * Não recebe o comando de propósito — `getFile()` já aponta o arquivo exato do
 * comando que quebrou, e é mais preciso do que a variável do roteador.
 *
 * @param Throwable $erro Exceção que escapou do roteador
 */
function registrarFalhaDoRoteador(Throwable $erro): void
{
    escreverLog(
        sprintf(
            'Falha não tratada no roteador: %s: %s em %s:%d',
            $erro::class,
            $erro->getMessage(),
            $erro->getFile(),
            $erro->getLine()
        ),
        'roteador_erros',
        true,
        'error',
        ['exception' => $erro]
    );
}

/**
 * Ativa STDERR JSON nos processos/cron (independente de LOGS_ATIVO para o arquivo).
 *
 * O `request_id` da execução NÃO é aberto aqui, e não é esquecimento:
 * `ContextoDeExecucao` gera o id na primeira leitura, então a primeira linha
 * logada já o resolve e todas as seguintes herdam o mesmo valor. Amarrar a
 * geração a uma chamada explícita significaria que o script novo — o que
 * ninguém lembrou de instrumentar — logaria sem correlação.
 */
function iniciarLoggerCli(): void
{
    if (!class_exists(LoggerFactory::class)) {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    LoggerFactory::forCli(true);
}

/**
 * Ecoa mensagem legível e encerra — para erros já gravados no Monolog
 * (ex.: falha de conexão em bdTelegram).
 */
function ecoarEEncerrar(string $mensagem, int $codigo = 1): never
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        fwrite(STDOUT, $mensagem . "\n");
    } else {
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo $mensagem;
    }
    exit($codigo);
}
