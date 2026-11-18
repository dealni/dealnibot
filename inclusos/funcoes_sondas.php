<?php

declare(strict_types=1);

require_once __DIR__ . '/banco.php';

/**
 * As dependências reais deste bot, na forma que `App\Support\Sonda` consome.
 *
 * Mora em `inclusos/` e não em `src/` porque precisa de `conectarBancoDoBot()` —
 * e mora num `funcoes_*.php` porque `ready.php` é um entrypoint web: a lógica
 * tem de estar num arquivo que a suíte possa carregar com `require_once` de
 * verdade.
 *
 * Este é o arquivo da observabilidade que carrega valor por bot, e é de
 * propósito: a lista de dependências muda de bot para bot e mora no bloco de
 * dados abaixo. A Bot API e os provedores de terceiro ficam de fora: sondá-los
 * a cada probe gastaria cota alheia, e a queda de qualquer um deles NÃO impede
 * este processo de atender.
 *
 * NÃO carrega `inclusos/funcoes.php` nem `inclusos/dados_bot.php`, e isso é
 * decisão, não economia: `/ready` é rota SEM autenticação, e não há motivo para
 * o token do bot e as credenciais dos provedores entrarem na memória de um
 * processo que só precisa responder `ok`/`erro`.
 */

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/**
 * Porta usada quando REDIS_PORT não vem do ambiente ou não é uma porta TCP
 * válida: a mesma que `enderecoDoRedis()` aplica no caminho normal do bot.
 * Não vem de `inclusos/redis_conexao.php` porque este arquivo não pode
 * carregar nada além de `banco.php` (ver o cabeçalho).
 */
const PORTA_PADRAO_DO_REDIS_DA_SONDA = 6380;

/**
 * As dependências de hoje: o PostgreSQL, que guarda o estado inteiro, e o
 * Redis, de onde saem a cota e a idempotência (sem ele o webhook recusa antes
 * do ACK).
 *
 * @return array<string, callable(): bool>
 */
function sondasDeDependencia(): array
{
    return [
        'postgresql' => static fn (): bool => sondaRegistrandoReprovacao('postgresql', sondaPostgresResponde(...)),
        'redis' => static fn (): bool => sondaRegistrandoReprovacao('redis', sondaRedisResponde(...)),
    ];
}
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

/**
 * Só a REPROVAÇÃO vira linha de log, no canal `prontidao`.
 *
 * Probe é ruído por definição — uma linha por minuto de "tudo bem" enterraria o
 * arquivo —, mas a probe que reprova é o primeiro sinal automático deste bot, e
 * é dela que o alerta do runbook depende
 * (docs/OPERACAO.md#logs). `forcar: true` porque indisponibilidade tem de
 * aparecer mesmo com `LOGS_ATIVO` desligado.
 *
 * O registro mora AQUI, e não em `ready.php`, por uma razão de forma: o
 * entrypoint não carrega valor de bot nenhum, e não pode ganhar comportamento
 * de um só. Aqui, no arquivo que já guarda o valor por bot,
 * ele cabe — e ainda ganha granularidade, porque a linha diz QUAL dependência
 * caiu em vez de despejar o mapa inteiro.
 *
 * A mensagem da exceção entra no log de propósito: é ela que separa "banco
 * sumiu" de "banco ficou somente leitura". O que ela NÃO faz é chegar ao corpo
 * da resposta — essa é a divisão que `App\Support\Sonda` sustenta, e o log tem
 * controle de acesso que a rota anônima não tem.
 *
 * @param callable(): bool $sonda
 */
function sondaRegistrandoReprovacao(string $nome, callable $sonda): bool
{
    try {
        if ($sonda() === true) {
            return true;
        }

        $motivo = 'a dependência respondeu, mas não como esperado';
    } catch (Throwable $erro) {
        $motivo = $erro::class . ': ' . $erro->getMessage();
    }

    \App\Support\LoggerFactory::channel('prontidao', true)
        ->error('Prontidao reprovada', ['dependencia' => $nome, 'motivo' => $motivo]);

    return false;
}

/** Confere conexão e acesso à tabela central, com timeout curto. */
function sondaPostgresResponde(): bool
{
    $con = conectarBancoDoBot(null, 2);
    $con->exec("SET statement_timeout = '2s'; SET lock_timeout = '2s'");
    return $con->query('SELECT 1 FROM usuarios LIMIT 1') !== false;
}

/**
 * Lê o endpoint do Redis com a mesma normalização do cliente principal.
 *
 * `/ready` não pode testar um endereço diferente daquele que
 * `conectarRedis()` usará no webhook. Em particular, não corrige espaços nem
 * transforma a porta `0`: esses valores precisam continuar visíveis como
 * configuração inválida, exatamente como em `enderecoDoRedis()`.
 *
 * A DUPLICAÇÃO de `enderecoDoRedis()` (no arquivo de apoio do Redis do bot)
 * é deliberada: NÃO desduplique. Este arquivo é carregado por `ready.php`,
 * uma rota sem autenticação, e aquele apoio chega com `dados_bot.php` junto
 * — token do bot e
 * credenciais na memória de quem responde a qualquer um. A sonda copia as
 * três linhas em vez de importar o arquivo; o contrato do trio de variáveis é
 * guardado pelo `.env.example` e por `SondasDeDependenciaTest`.
 *
 * @return array{host: string, port: int}
 */
function enderecoDoRedisDaSonda(): array
{
    $host = getenv('REDIS_HOST');
    $portaBruta = getenv('REDIS_PORT');
    $porta = is_string($portaBruta) && ctype_digit($portaBruta) ? (int) $portaBruta : 0;

    return [
        'host' => is_string($host) && $host !== '' ? $host : '127.0.0.1',
        'port' => $porta >= 1 && $porta <= 65535 ? $porta : PORTA_PADRAO_DO_REDIS_DA_SONDA,
    ];
}

/**
 * Redis vivo e respondendo.
 *
 * Conexão NOVA e não persistente, com timeout curto: uma conexão persistente
 * reaproveitada mascara o momento em que o Redis caiu. Não usa
 * `conectarRedis()` de propósito — aquela função encerra o processo em falha, e
 * aqui a falha É a resposta.
 *
 * Host, porta e senha são lidos do ambiente aqui em vez de virem de
 * `enderecoDoRedis()`: aquela mora no arquivo de apoio do Redis do bot, que
 * chega com `dados_bot.php` junto — token do bot e credenciais na memória de uma rota SEM
 * autenticação. O contrato do trio de variáveis é o mesmo, e é o `.env.example`
 * que o guarda.
 */
function sondaRedisResponde(): bool
{
    // Indireção por string: é o idioma do repositório para o cliente do Redis, e
    // mantém a suíte capaz de injetar um dublê num subprocesso. Sem a extensão,
    // o `new` lança — e Sonda::checar() traduz Throwable em `erro`, que é o
    // desfecho certo.
    $redisClass = 'Redis';
    $redis = new $redisClass();

    $senha = getenv('REDIS_PASSWORD');
    $endereco = enderecoDoRedisDaSonda();

    // A partir do phpredis 6 connect() LANÇA em vez de devolver false; quem
    // trata é Sonda::checar(), no mesmo ramo do `erro`. O timeout de conexão e
    // o de leitura são explícitos: sem o sexto argumento, um Redis que aceita
    // o socket mas não responde ao PING poderia prender a rota além do teto de
    // 2 s prometido por Sonda::TIMEOUT_SEGUNDOS. O quinto argumento é o
    // intervalo entre tentativas de reconexão (ms): curto, porque a sonda não
    // pode esperar — e as tentativas em si são zeradas em sondaRedisConfirmada().
    try {
        $conectado = $redis->connect(
            $endereco['host'],
            $endereco['port'],
            \App\Support\Sonda::TIMEOUT_SEGUNDOS,
            '',
            100,
            \App\Support\Sonda::TIMEOUT_SEGUNDOS
        );
    } catch (Throwable $erro) {
        // A exceção continua sendo a resposta (Sonda::checar() a traduz em
        // `erro`), mas o cliente não fica aberto até o fim do processo.
        $redis->close();
        throw $erro;
    }

    return sondaRedisConfirmada($redis, $conectado === true, is_string($senha) ? $senha : null);
}

/**
 * O veredito depois que a conexão foi tentada — separado porque é a metade que
 * a suíte consegue exercitar.
 *
 * Numa máquina de desenvolvimento não há Redis, e a suíte aponta `REDIS_PORT`
 * para 1 de propósito: o `connect()` sempre reprova, e autenticação e `ping()`
 * nunca chegariam a rodar. Com o veredito numa função própria, um dublê de
 * `Redis` cobre os três desfechos que importam — senha recusada, `ping()` mudo
 * e o caminho feliz — sem servidor nenhum.
 */
function sondaRedisConfirmada(\Redis $cliente, bool $conectado, ?string $senha): bool
{
    try {
        if (!$conectado) {
            return false;
        }

        // A senha é do host, não do código: sem ela o `ping()` responderia
        // NOAUTH e a sonda acusaria queda de um Redis perfeitamente vivo.
        if ($senha !== null && $senha !== '' && $cliente->auth($senha) !== true) {
            return false;
        }

        // Sem serializador (o PONG tem de chegar cru para a comparação abaixo)
        // e sem retentativa (a sonda mede o Redis de agora, não o de daqui a
        // uma reconexão). Um setOption() recusado é um cliente que não está
        // no estado esperado — e aí a resposta honesta é `erro`.
        if ($cliente->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE) !== true
            || $cliente->setOption(\Redis::OPT_MAX_RETRIES, 0) !== true
        ) {
            return false;
        }

        $pong = $cliente->ping();

        // phpredis devolve `true`, `'PONG'` ou `'+PONG'` conforme a versão e o
        // modo da conexão; os três significam a mesma coisa.
        return $pong === true || $pong === 'PONG' || $pong === '+PONG';
    } finally {
        $cliente->close();
    }
}
