<?php

declare(strict_types=1);

/*
 * Abertura de conexão Redis com os parâmetros comuns a todos os chamadores.
 *
 * Fica num arquivo próprio, e não junto da biblioteca grande de funções, porque
 * quem mais precisa dela são os processos que NÃO podem carregar o bot inteiro:
 * o smoke test, as sondas e os scripts de manutenção. A política de falha não
 * mora aqui — a função devolve bool e cada chamador decide se registra, degrada
 * ou encerra.
 */

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/**
 * Porta usada quando REDIS_PORT não vem do ambiente ou não é uma porta TCP
 * válida. É a mesma porta que enderecoDoRedis() aplica no caminho normal do
 * bot; as duas leem o mesmo ambiente.
 */
const PORTA_PADRAO_DO_REDIS = 6380;
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

/**
 * Host e porta do Redis lidos do ambiente, já normalizados.
 *
 * ctype_digit() (e não is_numeric()) de propósito: "6379.5", "-1" e " 6379" não
 * são portas válidas e precisam cair no padrão em vez de virar um inteiro
 * truncado que o connect() recusaria em produção. Nenhum trim é aplicado ao
 * host: um espaço acidental no .env precisa aparecer no erro de conexão em vez
 * de ser corrigido em silêncio.
 *
 * @return array{host: string, port: int}
 */
function enderecoDaConexaoRedis(): array
{
    $host = getenv('REDIS_HOST');
    $portaBruta = getenv('REDIS_PORT');
    $porta = is_string($portaBruta) && ctype_digit($portaBruta) ? (int) $portaBruta : 0;

    return [
        'host' => is_string($host) && $host !== '' ? $host : '127.0.0.1',
        'port' => $porta >= 1 && $porta <= 65535 ? $porta : PORTA_PADRAO_DO_REDIS,
    ];
}

/**
 * Senha do Redis, quando uma foi configurada no ambiente.
 *
 * A string é preservada exatamente como veio do ambiente: uma senha pode
 * conter espaços e não deve ser alterada por trim(). Uma variável ausente ou
 * vazia representa o Redis local sem autenticação.
 */
function senhaDaConexaoRedis(): ?string
{
    $senha = getenv('REDIS_PASSWORD');

    return is_string($senha) && $senha !== '' ? $senha : null;
}

/**
 * Abre a conexão e autentica quando o ambiente traz senha.
 *
 * O phpredis usa timeout 0 por padrão tanto para a conexão quanto para a
 * leitura da resposta. Isso deixa um webhook, um cron ou um smoke pendurado
 * indefinidamente quando o socket aceita a conexão mas o servidor não responde:
 * os 2 segundos das duas fases são deliberados.
 *
 * A função não captura RedisException: cada chamador tem uma política própria
 * (fail-closed no bot, degradação em rotina auxiliar) e precisa decidir como
 * registrar ou expor a falha.
 *
 * @param mixed $redis Instância de Redis (sem type-hint para manter testes que
 *                     exercitam a configuração sem ext-redis carregada)
 * @return bool true quando conexão e autenticação (se exigida) tiveram êxito
 */
function conectarRedisConfigurado($redis, bool $persistent = false): bool
{
    if (!is_object($redis)) {
        return false;
    }

    $endereco = enderecoDaConexaoRedis();
    $metodo = $persistent ? 'pconnect' : 'connect';
    if (!is_callable([$redis, $metodo])) {
        return false;
    }

    // connect()/pconnect() aceitam timeout de conexão, persistent_id,
    // retry_interval e read_timeout nessa ordem, e devolvem bool.
    $resultado = call_user_func_array([$redis, $metodo], [
        $endereco['host'],
        $endereco['port'],
        2.0,
        null,
        0,
        2.0,
    ]);
    if ($resultado !== true) {
        return false;
    }

    // Senha ausente significa Redis local sem autenticação e não envia um AUTH
    // vazio. Senha presente e recusada é falha: seguir sem AUTH aprovaria um
    // servidor que o bot não conseguiria usar.
    $senha = senhaDaConexaoRedis();
    if ($senha === null) {
        return true;
    }
    if (!is_callable([$redis, 'auth'])) {
        return false;
    }

    // phpredis documenta true em sucesso e false quando AUTH é rejeitado;
    // versões atuais também podem devolver a própria instância em sucesso.
    $resultadoAuth = call_user_func_array([$redis, 'auth'], [$senha]);

    return $resultadoAuth === true || $resultadoAuth === $redis;
}
