<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Telegram;

require_once __DIR__ . '/../../inclusos/Telegram.php';

/**
 * Testes de lacunas da classe Telegram: downloadFile().
 *
 * downloadFile() abre a URL https://api.telegram.org/file/bot<token>/<path>
 * com fopen() e copia o conteúdo para um arquivo local. Para testar sem rede,
 * o wrapper de stream "https" é substituído por FluxoHttpsFalso (definido no
 * fim deste arquivo) durante o teste e SEMPRE restaurado no tearDown — nenhuma
 * conexão real acontece. O contrato fixado:
 *   1. a URL montada é file/bot + token + / + caminho remoto (sem alterações);
 *   2. o conteúdo remoto é gravado byte a byte no arquivo local (inclusive
 *      quando ultrapassa o tamanho de um chunk de leitura de 8192 bytes, e
 *      inclusive quando o conteúdo é exatamente "0");
 *   3. o arquivo local preexistente é truncado antes da escrita (modo wb);
 *   4. falha na abertura de qualquer uma das duas pontas não escreve nada e
 *      fecha o handle que chegou a abrir.
 */
final class TelegramLacunasTest extends TestCase
{
    /** @var bool */
    private $httpsInterceptado = false;

    /** @var string|null */
    private $arquivoLocal = null;

    /** @var bool */
    private $destinoContadoRegistrado = false;

    protected function tearDown(): void
    {
        if ($this->httpsInterceptado) {
            stream_wrapper_restore('https');
            $this->httpsInterceptado = false;
        }
        if ($this->destinoContadoRegistrado) {
            stream_wrapper_unregister(FluxoDestinoContado::ESQUEMA);
            $this->destinoContadoRegistrado = false;
        }
        if ($this->arquivoLocal !== null && file_exists($this->arquivoLocal)) {
            unlink($this->arquivoLocal);
        }
        $this->arquivoLocal = null;
    }

    /**
     * Substitui o wrapper https pelo dublê e prepara o conteúdo servido.
     */
    private function interceptarHttps(string $conteudo, bool $falharAbertura = false): void
    {
        FluxoHttpsFalso::$conteudo = $conteudo;
        FluxoHttpsFalso::$urlAberta = null;
        FluxoHttpsFalso::$falharAbertura = $falharAbertura;
        FluxoHttpsFalso::$fechamentos = 0;

        $this->assertTrue(stream_wrapper_unregister('https'));
        $this->httpsInterceptado = true;
        $this->assertTrue(stream_wrapper_register('https', FluxoHttpsFalso::class));
    }

    /**
     * Cria (e agenda a limpeza de) um arquivo local temporário para o download.
     */
    private function caminhoLocalTemporario(): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'tg_lacunas_');
        if (!is_string($caminho)) {
            throw new \RuntimeException('tempnam falhou ao criar o arquivo temporário.');
        }
        $this->arquivoLocal = $caminho;

        return $caminho;
    }

    public function testDownloadFileMontaAUrlComTokenECaminhoRemoto(): void
    {
        $this->interceptarHttps('conteudo-remoto');
        $telegram = new Telegram('123456:FAKE_TOKEN', false);
        $local = $this->caminhoLocalTemporario();

        $telegram->downloadFile('documentos/relatorio.txt', $local);

        $this->assertSame(
            'https://api.telegram.org/file/bot123456:FAKE_TOKEN/documentos/relatorio.txt',
            FluxoHttpsFalso::$urlAberta
        );
        $this->assertSame('conteudo-remoto', file_get_contents($local));
        $socket = FluxoHttpsFalso::$opcoesContexto['socket'] ?? null;
        $this->assertIsArray($socket);
        $this->assertSame('0.0.0.0:0', $socket['bindto'] ?? null);
    }

    public function testDownloadFileCopiaConteudoMaiorQueUmChunkDeLeitura(): void
    {
        // 12000 bytes: força ao menos duas iterações do laço de fread(8192).
        $conteudo = str_repeat('abc', 4000);
        $this->interceptarHttps($conteudo);
        $telegram = new Telegram('123456:FAKE_TOKEN', false);
        $local = $this->caminhoLocalTemporario();

        $telegram->downloadFile('videos/grande.mp4', $local);

        $this->assertSame($conteudo, file_get_contents($local));
    }

    public function testDownloadFileGravaConteudoQueEApenasOCaractereZero(): void
    {
        // O laço para em $chunk !== '' e NÃO em falsy: um arquivo cujo conteúdo
        // seja exatamente "0" (chunk falsy) precisa ser gravado inteiro. Trocar
        // a comparação por while ($chunk) devolveria um arquivo vazio.
        $this->interceptarHttps('0');
        $telegram = new Telegram('123456:FAKE_TOKEN', false);
        $local = $this->caminhoLocalTemporario();

        $telegram->downloadFile('flags/zero.bin', $local);

        $this->assertSame('0', file_get_contents($local));
    }

    public function testDownloadFileComConteudoRemotoVazioGeraArquivoVazio(): void
    {
        $this->interceptarHttps('');
        $telegram = new Telegram('123456:FAKE_TOKEN', false);
        $local = $this->caminhoLocalTemporario();
        file_put_contents($local, 'conteudo-antigo');

        $telegram->downloadFile('vazio.bin', $local);

        $this->assertSame('', file_get_contents($local));
    }

    public function testDownloadFileTruncaOArquivoLocalPreexistente(): void
    {
        $this->interceptarHttps('novo');
        $telegram = new Telegram('123456:FAKE_TOKEN', false);
        $local = $this->caminhoLocalTemporario();
        file_put_contents($local, 'conteudo-antigo-que-deve-sumir');

        $telegram->downloadFile('fotos/avatar.jpg', $local);

        $this->assertSame('novo', file_get_contents($local));
    }

    public function testDownloadFileComFalhaNaAberturaRemotaPreservaArquivoPreexistente(): void
    {
        $this->interceptarHttps('nunca-servido', true);
        $telegram = new Telegram('123456:FAKE_TOKEN', false);
        $local = $this->caminhoLocalTemporario();
        $conteudoAnterior = 'arquivo local que nao pode ser perdido';
        $this->assertNotFalse(file_put_contents($local, $conteudoAnterior));

        // fopen() emite E_WARNING quando o stream_open do wrapper devolve
        // false; o handler abaixo engole o aviso para que o PHPUnit não o
        // converta em exceção — o contrato testado é o retorno silencioso.
        set_error_handler(static function (int $codigo, string $mensagem): bool {
            return true;
        });
        try {
            $telegram->downloadFile('inexistente.bin', $local);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(
            'https://api.telegram.org/file/bot123456:FAKE_TOKEN/inexistente.bin',
            FluxoHttpsFalso::$urlAberta
        );
        // A origem precisa abrir antes do destino em modo wb. Assim uma falha
        // de rede não apaga o arquivo local que o caller já possuía.
        $this->assertSame($conteudoAnterior, file_get_contents($local));
    }

    public function testDownloadFileComDestinoLocalInvalidoFechaOFluxoRemoto(): void
    {
        // Destino em diretório inexistente: fopen(local, 'wb') devolve false com
        // o fluxo remoto JÁ aberto. Sem o fclose($in) do guard, cada download
        // com destino inválido vazaria um descritor de arquivo — em um processo
        // de long polling isso derruba o bot por esgotamento de descritores.
        $this->interceptarHttps('conteudo-remoto');
        $telegram = new Telegram('123456:FAKE_TOKEN', false);
        $destinoInvalido = sys_get_temp_dir() . '/tg_dir_inexistente_' . bin2hex(random_bytes(6)) . '/arquivo.bin';

        set_error_handler(static function (int $codigo, string $mensagem): bool {
            return true;
        });
        try {
            $telegram->downloadFile('fotos/avatar.jpg', $destinoInvalido);
        } finally {
            restore_error_handler();
        }

        $this->assertFileDoesNotExist($destinoInvalido);
        $this->assertSame(
            'https://api.telegram.org/file/bot123456:FAKE_TOKEN/fotos/avatar.jpg',
            FluxoHttpsFalso::$urlAberta
        );
        $this->assertSame(1, FluxoHttpsFalso::$fechamentos, 'o fluxo remoto ficou aberto');
    }

    /**
     * A cópia é feita em blocos de 8192 bytes, e esse número é o TETO de
     * memória do download: um arquivo de 2 GB do Telegram passa por aqui sem
     * nunca ocupar mais que um bloco de RAM. Conferir só o conteúdo final não
     * enxerga o tamanho do bloco — um `fread()` sem limite copiaria o mesmo
     * arquivo carregando tudo de uma vez.
     *
     * O destino é um wrapper que anota cada `fwrite()`: o tamanho pedido ao
     * `fread()` não é observável do lado da leitura (o PHP normaliza o pedido
     * ao chunk size do stream), mas o tamanho ENTREGUE ao destino é.
     */
    public function testDownloadFileCopiaEmBlocosDeAte8192Bytes(): void
    {
        // 20000 bytes: dois blocos cheios e um resto, o que distingue o teto
        // real de um bloco maior (um só fwrite) ou menor (blocos de 8191).
        $conteudo = str_repeat('abcde', 4000);
        $this->interceptarHttps($conteudo);
        $telegram = new Telegram('123456:FAKE_TOKEN', false);
        $destino = $this->destinoContado();

        $telegram->downloadFile('videos/grande.mp4', $destino);

        $this->assertSame([8192, 8192, 3616], FluxoDestinoContado::$escritas);
        $this->assertSame($conteudo, FluxoDestinoContado::$recebido);
        $this->assertSame(1, FluxoHttpsFalso::$fechamentos, 'o fluxo remoto ficou aberto após o sucesso');
        $this->assertSame(1, FluxoDestinoContado::$fechamentos, 'o destino ficou aberto após o sucesso');
    }

    /**
     * Registra o wrapper do destino que conta as escritas e devolve a URL a
     * passar para downloadFile(). O wrapper é removido no tearDown.
     */
    private function destinoContado(): string
    {
        FluxoDestinoContado::$escritas = [];
        FluxoDestinoContado::$recebido = '';
        FluxoDestinoContado::$fechamentos = 0;
        $this->assertTrue(stream_wrapper_register(
            FluxoDestinoContado::ESQUEMA,
            FluxoDestinoContado::class
        ));
        $this->destinoContadoRegistrado = true;

        return FluxoDestinoContado::ESQUEMA . '://destino.bin';
    }
}

/**
 * Dublê de wrapper de stream https usado pelos testes de downloadFile().
 *
 * stream_wrapper_register() exige uma classe NOMEADA (não aceita classe
 * anônima), e o autoload-dev PSR-4 não precisa alcançá-la: ela é definida
 * junto com o único teste que a usa, ao carregar este arquivo. Métodos em
 * snake_case são o contrato fixo da API de stream wrappers do PHP.
 */
final class FluxoHttpsFalso
{
    /** @var string Conteúdo servido ao fread(). */
    public static $conteudo = '';

    /** @var string|null Última URL aberta via fopen(). */
    public static $urlAberta = null;

    /** @var array<mixed> */
    public static $opcoesContexto = [];

    /** @var bool Quando true, stream_open devolve false (falha de abertura). */
    public static $falharAbertura = false;

    /** @var int Quantas vezes o fluxo remoto foi fechado. */
    public static $fechamentos = 0;

    /** @var resource|null Preenchido pelo PHP; obrigatório na API de wrappers. */
    public $context;

    /** @var int */
    private $posicao = 0;

    /**
     * @param string $caminho
     * @param string $modo
     * @param int $opcoes
     * @param string|null $caminho_aberto
     */
    public function stream_open($caminho, $modo, $opcoes, &$caminho_aberto): bool
    {
        self::$urlAberta = $caminho;
        TestCase::assertIsResource($this->context);
        self::$opcoesContexto = stream_context_get_options($this->context);
        if (self::$falharAbertura) {
            return false;
        }
        $this->posicao = 0;

        return true;
    }

    /**
     * @param int $bytes
     */
    public function stream_read($bytes): string
    {
        $trecho = substr(self::$conteudo, $this->posicao, $bytes);
        $this->posicao += strlen($trecho);

        return $trecho;
    }

    public function stream_eof(): bool
    {
        return $this->posicao >= strlen(self::$conteudo);
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        self::$fechamentos++;
    }
}

/**
 * Destino de download que anota o tamanho de cada bloco escrito.
 *
 * Um arquivo real no disco esconde a granularidade da cópia: o conteúdo final
 * é o mesmo com um bloco de 8192 ou com um único fwrite gigante. O esquema
 * próprio evita colidir com wrappers do PHP (o teste registra e remove).
 */
final class FluxoDestinoContado
{
    public const ESQUEMA = 'tg-destino-contado';

    /** @var list<int> Tamanho de cada bloco entregue ao fwrite(). */
    public static $escritas = [];

    /** @var string Tudo o que foi escrito, na ordem. */
    public static $recebido = '';

    /** @var int Quantas vezes o fluxo de destino foi fechado. */
    public static $fechamentos = 0;

    /** @var resource|null Preenchido pelo PHP; obrigatório na API de wrappers. */
    public $context;

    /**
     * @param string $caminho
     * @param string $modo
     * @param int $opcoes
     * @param string|null $caminho_aberto
     */
    public function stream_open($caminho, $modo, $opcoes, &$caminho_aberto): bool
    {
        return true;
    }

    /**
     * @param string $dados
     */
    public function stream_write($dados): int
    {
        self::$escritas[] = strlen($dados);
        self::$recebido .= $dados;

        return strlen($dados);
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        self::$fechamentos++;
    }
}
