<?php

declare(strict_types=1);

namespace Tests;

use App\Support\FormatoDeImagem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reconhecimento de formato pelos BYTES (App\Support\FormatoDeImagem).
 *
 * Os casos vieram de ComandosVerImagemFluxoTest quando o parser de GIF saiu de
 * comandos/funcoes_ver_imagem.php: o contrato é o mesmo — a lista de MIMEs é a
 * que a visão da OpenAI aceita, GIF animado é recusado antes da chamada paga,
 * e GIF truncado ou malformado continua passando como GIF, como antes.
 */
final class FormatoDeImagemTest extends TestCase
{
    /** GIF mínimo válido com dois descritores de imagem (dois quadros). */
    private const GIF_ANIMADO =
        "GIF89a\x01\x00\x01\x00\x00\x00\x00"
        . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x4C\x01\x00"
        . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x4C\x01\x00"
        . "\x3B";

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function assinaturasDeImagemProvider(): array
    {
        return [
            'jpeg' => ["\xFF\xD8\xFF\xE0qualquer", 'image/jpeg'],
            'png' => ["\x89PNG\r\n\x1A\nqualquer", 'image/png'],
            'gif87' => ['GIF87aqualquer', 'image/gif'],
            'gif89' => ['GIF89aqualquer', 'image/gif'],
            'gif animado' => [self::GIF_ANIMADO, null],
            'webp' => ['RIFF????WEBPVP8 ', 'image/webp'],
            // RIFF sem WEBP é WAV/AVI: não é imagem e não pode virar data: URI.
            'riff que nao e webp' => ['RIFF????WAVEfmt ', null],
            'texto' => ['<html>404 not found</html>', null],
            'json de erro da bot api' => ['{"ok":false,"description":"file not found"}', null],
            'svg (a visao da openai nao aceita)' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', null],
            'vazio' => ['', null],
        ];
    }

    /**
     * O tipo sai dos BYTES, não da extensão do `file_path` do Telegram: a
     * extensão é texto vindo de fora, e um data: URI que mente sobre o conteúdo
     * gasta a chamada PAGA para receber uma recusa do provedor.
     */
    #[DataProvider('assinaturasDeImagemProvider')]
    public function testTipoMimeSaiDaAssinaturaDoArquivo(string $bytes, ?string $esperado): void
    {
        $this->assertSame($esperado, FormatoDeImagem::tipoMime($bytes));
    }

    /**
     * Avanço do Graphic Control Extension, medido logo no começo do arquivo.
     *
     * Este caso é curto e roda ANTES do provider grande de propósito. O bloco
     * de controle gráfico avança o cursor em quatro bytes; um avanço que ande
     * para TRÁS devolve o cursor ao mesmo marcador e a varredura passa a se
     * repetir para sempre quando o byte anterior por acaso é zero — foi assim
     * que um mutante virou travamento em vez de defeito detectado. Com o bloco
     * colado no cabeçalho, o cursor recuado cai no byte 12 (a proporção de
     * pixel, aqui 0x01), a varredura desiste na hora e o caminho errado aparece
     * como resposta errada, não como espera.
     */
    public function testControleGraficoNoTopoAvancaQuatroBytesParaFrente(): void
    {
        $cabecalhoComProporcao = "GIF89a\x01\x00\x01\x00\x00\x00\x01";
        $controleGrafico = "\x21\xF9\x04\x00\x00\x00\x00\x00";
        $imagem = "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x01\x4C\x00";

        $this->assertTrue(
            FormatoDeImagem::gifTemMaisDeUmQuadro(
                $cabecalhoComProporcao . $controleGrafico . $imagem . $imagem . "\x3B"
            ),
            'O cursor precisa sair do controle gráfico apontando para o primeiro quadro.'
        );
    }

    /** @return array<string, array{string, bool}> */
    public static function estruturasGifProvider(): array
    {
        $cabecalho = "GIF89a\x01\x00\x01\x00\x00\x00\x00";
        $descritor = "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00";
        $imagem = $descritor . "\x02\x01\x4C\x00";
        // Descritor de imagem cuja tabela de cores LOCAL é anunciada com os
        // bits de tamanho no máximo (0x87 = 3 * (1 << 8) = 768 bytes): o
        // arquivo acaba muito antes, e é o que torna visível quem ignora a
        // recusa da tabela e segue varrendo.
        $descritorTabelaLocalGrande = "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x87";
        $descritorTabelaLocal = "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x81";
        $imagemComTabelaLocal = $descritorTabelaLocal . str_repeat("\x00", 12) . "\x02\x01\x4C\x00";

        return [
            'menor que o cabecalho' => ['GIF89a', false],
            'nao e gif' => [str_repeat("\x00", 20), false],
            'tabela global truncada' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00\x00", false],
            'tabela global completa' => [
                "GIF89a\x01\x00\x01\x00\x80\x00\x00" . str_repeat("\x00", 6) . "\x3B",
                false,
            ],
            'so o trailer' => [$cabecalho . "\x3B", false],
            'descritor truncado' => [$cabecalho . "\x2C\x00", false],
            'tabela local truncada' => [$cabecalho . substr($descritor, 0, -1) . "\x80\x00", false],
            'tabela local completa' => [
                $cabecalho . substr($descritor, 0, -1) . "\x80" . str_repeat("\x00", 6)
                    . "\x02\x01\x4C\x00\x3B",
                false,
            ],
            'sem codigo lzw' => [$cabecalho . $descritor, false],
            // Um quadro completo e o arquivo acabando sem o trailer 0x3B: a
            // contagem termina por falta de bytes, ainda com um quadro só.
            'um quadro sem trailer' => [$cabecalho . $imagem, false],
            'subbloco da imagem truncado' => [$cabecalho . $descritor . "\x02\x03\x4C", false],
            'subbloco da imagem sem terminador' => [$cabecalho . $descritor . "\x02\x01\x4C", false],
            'marcador desconhecido' => [$cabecalho . "\x00", false],
            'extensao sem rotulo' => [$cabecalho . "\x21", false],
            'controle grafico com tamanho invalido' => [$cabecalho . "\x21\xF9\x03", false],
            'controle grafico truncado' => [$cabecalho . "\x21\xF9\x04\x00", false],
            'controle grafico sem terminador' => [$cabecalho . "\x21\xF9\x04\x00\x00\x00\x00\x01", false],
            'controle grafico valido' => [$cabecalho . "\x21\xF9\x04\x00\x00\x00\x00\x00\x3B", false],
            'extensao sem tamanho' => [$cabecalho . "\x21\xFE", false],
            'extensao com bloco truncado' => [$cabecalho . "\x21\xFE\x03\x41", false],
            'extensao sem terminador' => [$cabecalho . "\x21\xFE\x01\x41", false],
            'extensao valida antes da imagem' => [$cabecalho . "\x21\xFE\x01\x41\x00" . $imagem . "\x3B", false],
            'dois quadros' => [self::GIF_ANIMADO, true],
            'dois quadros separados por controle grafico' => [
                $cabecalho . $imagem . "\x21\xF9\x04\x00\x00\x00\x00\x00" . $imagem . "\x3B",
                true,
            ],
            'segundo quadro depois de tabela local' => [
                $cabecalho . $imagem
                    . substr($descritor, 0, -1) . "\x80" . str_repeat("\x00", 6) . "\x02\x01\x4C\x00"
                    . "\x3B",
                true,
            ],

            // ----------------------------------------------------------------
            // Animação PROVADA depois de cada estrutura intermediária.
            //
            // Os casos acima terminam quase todos em `false`, e por isso não
            // distinguem "a varredura recusou porque a estrutura acabou" de "a
            // varredura recusou porque errou a conta e perdeu o alinhamento".
            // Os casos abaixo colocam DOIS quadros depois da estrutura em
            // exame: qualquer byte a mais ou a menos no avanço cai no meio da
            // tabela ou do quadro, o segundo marcador some e o resultado vira
            // `false`. É o único jeito de o teste falar sobre o tamanho do
            // passo, e não só sobre o desfecho.
            // ----------------------------------------------------------------

            // Tabela global de 6 bytes: flags 0x80 tem o bit de tabela ligado e
            // os bits de tamanho zerados — o menor salto possível.
            'tabela global de seis bytes antes de dois quadros' => [
                "GIF89a\x01\x00\x01\x00\x80\x00\x00" . str_repeat("\x00", 6)
                    . $imagem . $imagem . "\x3B",
                true,
            ],
            // Flags 0x81: mesmo bit de tabela, bits de tamanho em 1 — 12 bytes.
            // Duas larguras diferentes fixam a fórmula inteira, e não um número.
            'tabela global de doze bytes antes de dois quadros' => [
                "GIF89a\x01\x00\x01\x00\x81\x00\x00" . str_repeat("\x00", 12)
                    . $imagem . $imagem . "\x3B",
                true,
            ],
            // Flags 0x07: bits de TAMANHO ligados sem o bit de tabela. Não há
            // tabela para pular, e quem olhar o byte errado some com os quadros.
            'flags globais sem o bit de tabela antes de dois quadros' => [
                "GIF89a\x01\x00\x01\x00\x07\x00\x00" . $imagem . $imagem . "\x3B",
                true,
            ],
            // Tabela global anunciada com 768 bytes num arquivo de algumas
            // dezenas: a recusa precisa ENCERRAR a contagem, não só interromper
            // o salto — senão os dois quadros logo adiante seriam contados.
            'tabela global anunciada maior que o arquivo' => [
                "GIF89a\x01\x00\x01\x00\x87\x00\x00" . $imagem . $imagem . "\x3B",
                false,
            ],
            // Mesma ideia na tabela LOCAL: o descritor foi aceito, a tabela não
            // cabe, e o quadro seguinte não pode virar o segundo quadro.
            'tabela local anunciada maior que o arquivo' => [
                $cabecalho . $descritorTabelaLocalGrande . $imagem . "\x3B",
                false,
            ],
            // Idem, com sub-blocos bem-formados logo depois do descritor: quem
            // ignora a tabela recusada encontra o terminador e segue contando.
            'tabela local anunciada maior que o arquivo com subblocos validos' => [
                $cabecalho . $descritorTabelaLocalGrande . "\x02\x01\x4C\x00" . $imagem . "\x3B",
                false,
            ],
            // Primeiro quadro COM tabela local e um segundo quadro depois: é o
            // único arranjo em que a tabela local chega a ser percorrida, já
            // que o segundo marcador encerra a contagem antes do descritor.
            'primeiro quadro com tabela local e segundo quadro' => [
                $cabecalho . $imagemComTabelaLocal . $imagem . "\x3B",
                true,
            ],
            // Marcador desconhecido seguido de dois quadros: o desconhecido
            // fecha a contagem: retomar a varredura acharia a animação.
            'marcador desconhecido antes de dois quadros' => [
                $cabecalho . "\x00" . $imagem . $imagem . "\x3B",
                false,
            ],
            // Descritor sem os 9 bytes fixos, com outro marcador de imagem
            // colado: recusar o descritor tem de valer mais que o marcador.
            'descritor truncado seguido de outro marcador de imagem' => [
                $cabecalho . "\x2C\x2C",
                false,
            ],
            // Controle gráfico com tamanho declarado diferente de 4: é bloco
            // inválido, e tratá-lo como extensão comum acharia a animação.
            'controle grafico com tamanho tres antes de dois quadros' => [
                $cabecalho . "\x21\xF9\x03\x00\x00\x00\x00" . $imagem . $imagem . "\x3B",
                false,
            ],
            // Tamanho 5 pelo mesmo motivo, mas com os cinco bytes presentes:
            // aceitar o bloco levaria a varredura até os dois quadros.
            'controle grafico com tamanho cinco antes de dois quadros' => [
                $cabecalho . "\x21\xF9\x05\x00\x00\x00\x00\x00" . $imagem . $imagem . "\x3B",
                false,
            ],
            // Rótulo de controle gráfico no último byte do arquivo: não há
            // sequer o byte de tamanho para ler.
            'controle grafico sem tamanho no fim do arquivo' => [
                $cabecalho . "\x21\xF9",
                false,
            ],
            // Rótulo de controle gráfico seguido direto do primeiro quadro: o
            // bloco é inválido, e aceitá-lo entregaria os dois quadros.
            'controle grafico seguido de marcador de imagem' => [
                $cabecalho . "\x21\xF9" . $imagem . $imagem . "\x3B",
                false,
            ],
            // Controle gráfico cujo terminador caiu em cima do quadro: o byte
            // não é zero, o bloco não fecha e a contagem para.
            'controle grafico com terminador ocupado pelo quadro' => [
                $cabecalho . "\x21\xF9\x04\x00\x00\x00\x00" . $imagem . $imagem . "\x3B",
                false,
            ],
            // Terminador presente, mas diferente de zero: o bloco continua
            // inválido, e pular o byte seguinte acharia a animação.
            'controle grafico com terminador diferente de zero' => [
                $cabecalho . "\x21\xF9\x04\x00\x00\x00\x00\x01" . $imagem . $imagem . "\x3B",
                false,
            ],
            // Um quadro, depois um controle gráfico sem os 5 bytes e com um
            // marcador de imagem no lugar: o bloco truncado não pode liberar
            // o segundo quadro.
            'controle grafico truncado com marcador de imagem colado' => [
                $cabecalho . $imagem . "\x21\xF9\x04\x2C",
                false,
            ],
            // Sub-bloco da imagem anunciando 255 bytes que não existem: o
            // quadro seguinte não pode virar o segundo quadro.
            'subbloco da imagem anunciado maior que o arquivo' => [
                $cabecalho . $descritor . "\x02\xFF" . $imagem . "\x3B",
                false,
            ],
            // Extensão comum de três bytes seguida de dois quadros: aqui o
            // salto do bloco declarado é medido, e não só tolerado.
            'extensao com bloco de tres bytes antes de dois quadros' => [
                $cabecalho . "\x21\xFE\x03\x41\x42\x43\x00" . $imagem . $imagem . "\x3B",
                true,
            ],
            // Extensão comum anunciando 255 bytes ausentes: recusar precisa
            // encerrar a contagem, com dois quadros logo adiante.
            'extensao com bloco anunciado maior que o arquivo' => [
                $cabecalho . "\x21\xFE\xFF" . $imagem . $imagem . "\x3B",
                false,
            ],
        ];
    }

    /**
     * Só uma estrutura bem-formada o bastante para PROVAR um segundo quadro é
     * recusada; qualquer truncamento antes disso continua contando como GIF
     * de um quadro, que é o que o provedor aceita.
     */
    #[DataProvider('estruturasGifProvider')]
    public function testParserGifToleraEstruturasValidasETruncadas(string $gif, bool $animado): void
    {
        $this->assertSame($animado, FormatoDeImagem::gifTemMaisDeUmQuadro($gif));
    }

    public function testGifDeUmQuadroContinuaSendoGif(): void
    {
        $umQuadro = "GIF89a\x01\x00\x01\x00\x00\x00\x00"
            . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x4C\x01\x00"
            . "\x3B";

        $this->assertFalse(FormatoDeImagem::gifTemMaisDeUmQuadro($umQuadro));
        $this->assertSame('image/gif', FormatoDeImagem::tipoMime($umQuadro));
    }
}
