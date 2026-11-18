<?php

declare(strict_types=1);

namespace Tests\Support;

use CURLFile;

/**
 * Transporte de teste para a transcrição do Whisper em comandos/ver_audio.php.
 *
 * A chamada do Whisper é um upload multipart (CURLFile + campos), diferente da
 * chamada JSON coberta por TransporteHttpEspiao; por isso este dublê registra
 * os CAMPOS enviados e devolve corpo e código HTTP, sem tocar a rede e sem ler
 * o arquivo do disco.
 *
 * É passado no parâmetro opcional de chamarTranscricaoWhisper(), cujo padrão em
 * produção continua sendo o cURL real.
 */
final class TransporteWhisperEspiao
{
    /** @var int Quantas vezes o Whisper foi chamado. */
    public int $chamadas = 0;

    /** @var string|null URL recebida na última chamada. */
    public ?string $url = null;

    /** @var array<string, mixed> Campos multipart recebidos na última chamada. */
    public array $campos = [];

    /** @var array<int, string> Cabeçalhos recebidos na última chamada. */
    public array $cabecalhos = [];

    /** @var array{corpo: mixed, http: int} Resposta devolvida ao comando. */
    private array $resposta;

    /**
     * @param mixed $corpo Corpo devolvido; false simula timeout/conexão recusada
     * @param int $http Código HTTP devolvido pela API
     */
    public function __construct($corpo, int $http = 200)
    {
        $this->resposta = ['corpo' => $corpo, 'http' => $http];
    }

    /**
     * @param string $url
     * @param array<string, mixed> $campos
     * @param array<int, string> $cabecalhos
     * @return array{corpo: mixed, http: int}
     */
    public function __invoke(string $url, array $campos, array $cabecalhos): array
    {
        $this->chamadas++;
        $this->url = $url;
        $this->campos = $campos;
        $this->cabecalhos = $cabecalhos;

        return $this->resposta;
    }

    /**
     * Caminho do arquivo enviado no campo 'file' (o CURLFile do upload).
     *
     * @return string|null
     */
    public function arquivoEnviado(): ?string
    {
        $arquivo = $this->campos['file'] ?? null;

        return $arquivo instanceof CURLFile ? $arquivo->getFilename() : null;
    }

    /**
     * Campos do upload sem o CURLFile, para comparação direta nos testes.
     *
     * @return array<string, mixed>
     */
    public function camposSemArquivo(): array
    {
        $campos = $this->campos;
        unset($campos['file']);

        return $campos;
    }
}
