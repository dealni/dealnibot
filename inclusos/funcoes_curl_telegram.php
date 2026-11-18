<?php

declare(strict_types=1);

/**
 * Montagem das opções de cURL de uma chamada à Bot API, como dado.
 *
 * Saiu de `Telegram::opcoesDaRequisicao()` porque a validação do proxy — tipo,
 * porta, auth e URL, cada um provado antes de entrar — passava, num corpo só,
 * do teto de complexidade do `composer check:complexity`. Aqui cada campo da
 * configuração tem a sua função, e o método da classe só junta as partes.
 *
 * `inclusos/Telegram.php` carrega estas funções em todos os entrypoints,
 * inclusive onde o autoload do Composer ainda não foi carregado.
 *
 * Só declara funções — nenhum efeito no include.
 */

/**
 * Tipos de proxy que o cURL conhece. Um inteiro fora desta lista faz
 * `curl_setopt_array()` parar no meio do array; se entrasse, as travas TLS e
 * os timeouts que vêm depois poderiam nunca ser aplicados.
 *
 * @return list<int>
 */
function tiposDeProxyAceitosPeloCurl(): array
{
    return [
        CURLPROXY_HTTP,
        CURLPROXY_HTTP_1_0,
        CURLPROXY_HTTPS,
        CURLPROXY_SOCKS4,
        CURLPROXY_SOCKS5,
        CURLPROXY_SOCKS4A,
        CURLPROXY_SOCKS5_HOSTNAME,
    ];
}

/**
 * Inteiro não negativo de um valor de configuração.
 *
 * A configuração de proxy chega de fora (env, arquivo de config) com o tipo
 * que o parser deu, não com o que o cURL exige: `8080` e `'8080'` são o mesmo
 * valor; `'oitomil'`, `8080.5` e `-1` não são porta nem tipo de nada. Só
 * dígitos são aceitos como texto — `is_numeric()` deixaria passar `'1e3'`.
 */
function inteiroNaoNegativoDeConfiguracao(mixed $valor): ?int
{
    if (is_int($valor)) {
        return $valor >= 0 ? $valor : null;
    }
    if (is_string($valor) && preg_match('/\A\d+\z/D', $valor) === 1) {
        return (int) $valor;
    }

    return null;
}

/**
 * Valor escalar de uma chave da configuração, como texto — o que o cURL
 * espera em CURLOPT_PROXY e CURLOPT_PROXYUSERPWD. Ausente ou não escalar
 * (array, objeto, nulo) devolve null, e a opção não é aplicada.
 *
 * @param array<string, mixed> $configuracao
 */
function textoDeConfiguracao(array $configuracao, string $chave): ?string
{
    if (!array_key_exists($chave, $configuracao) || !is_scalar($configuracao[$chave])) {
        return null;
    }

    return (string) $configuracao[$chave];
}

/**
 * Opções de proxy do cURL a partir da configuração `array<string, mixed>` do
 * bot. Cada opção só é aplicada depois de provado que o valor tem o tipo E o
 * domínio que o cURL espera; o que não bate some, em vez de virar 0 ou string
 * vazia.
 *
 * @param array<string, mixed> $proxy Configuração (url, port, type, auth)
 * @return array<int, mixed>
 */
function opcoesCurlDeProxy(array $proxy): array
{
    if ($proxy === []) {
        return [];
    }

    $opcoes = [];

    $tipo = inteiroNaoNegativoDeConfiguracao($proxy['type'] ?? null);
    if ($tipo !== null && in_array($tipo, tiposDeProxyAceitosPeloCurl(), true)) {
        $opcoes[CURLOPT_PROXYTYPE] = $tipo;
    }

    // Auth vazia não vira PROXYUSERPWD: mandar ':' faria o cURL tentar
    // autenticar com usuário e senha vazios em vez de não autenticar.
    $auth = textoDeConfiguracao($proxy, 'auth');
    if ($auth !== null && $auth !== '') {
        $opcoes[CURLOPT_PROXYUSERPWD] = $auth;
    }

    $url = textoDeConfiguracao($proxy, 'url');
    if ($url !== null) {
        $opcoes[CURLOPT_PROXY] = $url;
    }

    // CURLOPT_PROXYPORT com 0 mandaria a chamada para a porta errada em
    // silêncio; acima de 65535 não é porta.
    $porta = inteiroNaoNegativoDeConfiguracao($proxy['port'] ?? null);
    if ($porta !== null && $porta <= 65535) {
        $opcoes[CURLOPT_PROXYPORT] = $porta;
    }

    return $opcoes;
}

/**
 * Todas as opções de cURL de uma chamada à Bot API.
 *
 * A ordem das chaves não importa para o cURL, mas importa para a leitura:
 * transporte, corpo, proxy e, por último, as travas de segurança.
 *
 * @param non-empty-string $url
 * @param array<string, mixed> $content
 * @param array<string, mixed> $proxy Configuração de proxy; vazio desliga
 * @param int $segundosDeConexao Teto do handshake
 * @param int $segundosTotais Teto da requisição inteira
 * @return array<int, mixed>
 */
function opcoesCurlDaBotApi(
    string $url,
    array $content,
    bool $post,
    array $proxy,
    int $segundosDeConexao,
    int $segundosTotais
): array {
    $opcoes = [
        CURLOPT_URL => $url,
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => true,
    ];

    if ($post) {
        $opcoes[CURLOPT_POST] = true;
        $opcoes[CURLOPT_POSTFIELDS] = $content;
    }

    foreach (opcoesCurlDeProxy($proxy) as $opcao => $valor) {
        $opcoes[$opcao] = $valor;
    }

    // Por estas conexões passa o TOKEN_BOT. VERIFYHOST vale 2, e não true:
    // o cURL trata 1 como "verifique que existe um nome" sem compará-lo com
    // o host pedido, que é o mesmo que não verificar.
    $opcoes[CURLOPT_SSL_VERIFYPEER] = true;
    $opcoes[CURLOPT_SSL_VERIFYHOST] = 2;
    // Evita o timeout TLS da rota IPv6 ao Telegram observado na VPS.
    $opcoes[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    $opcoes[CURLOPT_CONNECTTIMEOUT] = $segundosDeConexao;
    $opcoes[CURLOPT_TIMEOUT] = $segundosTotais;

    return $opcoes;
}
