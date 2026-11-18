<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Dublê do transporte cURL usado pelos scripts de config/ (config_webhook.php,
 * config_remover.php) nos testes de fluxo: registra a URL e os campos do POST
 * (quando houver) e devolve a resposta preparada, sem tocar a rede.
 *
 * É injetado no lugar da sequência curl_init/curl_setopt/curl_exec desses
 * scripts, cujo caminho de produção continua sendo o cURL real (nenhum
 * parâmetro de transporte existe na fonte: só o espelho de teste o recebe).
 */
final class TransporteCurlEspiao
{
    /** @var int Quantas vezes o transporte foi chamado. */
    public $chamadas = 0;

    /** @var string|null URL recebida na última chamada. */
    public $url = null;

    /** @var array<string, string> Campos do POST recebidos na última chamada ([] quando o script não envia nenhum). */
    public $postFields = [];

    /** @var mixed Corpo devolvido; false simula timeout/conexão recusada. */
    private $resposta;

    /** @var string Erro de cURL devolvido junto com a resposta ('' significa sem erro). */
    private $erroCurl;

    /** @param mixed $resposta */
    public function __construct($resposta, string $erroCurl = '')
    {
        $this->resposta = $resposta;
        $this->erroCurl = $erroCurl;
    }

    /**
     * @param array<string, string> $postFields
     * @return array{response: mixed, erro_curl: string}
     */
    public function __invoke(string $url, array $postFields = []): array
    {
        $this->chamadas++;
        $this->url = $url;
        $this->postFields = $postFields;

        return ['response' => $this->resposta, 'erro_curl' => $this->erroCurl];
    }
}
