<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Transporte HTTP de teste para os comandos de IA: registra o que seria
 * enviado à OpenAI e devolve um corpo preparado, sem tocar a rede.
 *
 * É passado no parâmetro opcional de chamarChatCompletionsOpenAi(), cujo
 * padrão em produção continua sendo o cURL real.
 */
final class TransporteHttpEspiao
{
    /** @var int Quantas vezes a API foi chamada. */
    public $chamadas = 0;

    /** @var string|null URL recebida na última chamada. */
    public $url = null;

    /** @var string|null Corpo JSON recebido na última chamada. */
    public $corpo = null;

    /** @var array<int, string> Cabeçalhos recebidos na última chamada. */
    public $cabecalhos = [];

    /** @var mixed Corpo devolvido; false simula timeout/conexão recusada. */
    private $resposta;

    /** @param mixed $resposta */
    public function __construct($resposta)
    {
        $this->resposta = $resposta;
    }

    /**
     * @param string $url
     * @param string $corpo
     * @param array<int, string> $cabecalhos
     * @return mixed
     */
    public function __invoke(string $url, string $corpo, array $cabecalhos)
    {
        $this->chamadas++;
        $this->url = $url;
        $this->corpo = $corpo;
        $this->cabecalhos = $cabecalhos;

        return $this->resposta;
    }
}
