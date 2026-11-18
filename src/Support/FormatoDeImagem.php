<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reconhecimento do formato de uma imagem pelos BYTES do arquivo.
 *
 * Pela assinatura, e não pela extensão do `file_path` do Telegram nem por
 * `finfo`: a extensão é texto vindo de fora (e o data: URI mentiria sobre o
 * conteúdo para a OpenAI), e o `finfo` exigiria declarar ext-fileinfo, uma
 * extensão a mais na lista de pré-requisitos do bot por causa de quatro
 * comparações de prefixo.
 *
 * Saiu de `comandos/funcoes_ver_imagem.php` porque o parser de GIF é uma
 * biblioteca pura — não depende de Telegram, Redis nem de constante do bot — e
 * porque, num só corpo, ele passava do teto de complexidade do
 * `composer check:complexity`. Aqui cada estrutura do GIF (tabela de cores,
 * descritor de imagem, extensão, sub-blocos) é um método pequeno.
 */
final class FormatoDeImagem
{
    /** Assinatura (6) + Logical Screen Descriptor (7): o menor GIF legível. */
    private const TAMANHO_DO_CABECALHO_GIF = 13;

    /** Marcador que abre um Image Descriptor — um quadro. */
    private const MARCADOR_DE_IMAGEM = 0x2C;

    /** Marcador que abre um bloco de extensão. */
    private const MARCADOR_DE_EXTENSAO = 0x21;

    /** Rótulo da Graphic Control Extension, o único bloco de tamanho fixo. */
    private const ROTULO_DE_CONTROLE_GRAFICO = 0xF9;

    /**
     * Tipo MIME da imagem, deduzido dos bytes.
     *
     * A lista é exatamente a que a visão da OpenAI aceita (GIF apenas não
     * animado). Formato desconhecido ou incompatível devolve null e vira aviso
     * ao usuário: mandar bytes que o provedor vai recusar gasta a chamada PAGA
     * para receber um erro.
     *
     * @param string $bytes Conteúdo bruto do arquivo
     * @return string|null MIME reconhecido, ou null
     */
    public static function tipoMime(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return self::gifTemMaisDeUmQuadro($bytes) ? null : 'image/gif';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    /**
     * A API de visão aceita GIF não animado, não GIF animado. O cabeçalho GIF
     * por si só não informa isso; contamos os descritores de imagem do arquivo
     * e só recusamos a animação quando a estrutura GIF está bem-formada o
     * bastante para provar que há um segundo quadro. Arquivos GIF legados dos
     * testes e arquivos truncados continuam sendo tratados como GIF, como
     * antes.
     */
    public static function gifTemMaisDeUmQuadro(string $bytes): bool
    {
        $tamanho = strlen($bytes);
        if ($tamanho < self::TAMANHO_DO_CABECALHO_GIF || !str_starts_with($bytes, 'GIF')) {
            return false;
        }

        $offset = self::TAMANHO_DO_CABECALHO_GIF;
        // O byte 10 é o campo de flags do Logical Screen Descriptor; o bit
        // mais alto anuncia a tabela de cores global.
        if (!self::pularTabelaDeCores($bytes, $offset, ord($bytes[10]))) {
            return false;
        }

        $quadros = 0;
        while ($offset < $tamanho) {
            $marcador = ord($bytes[$offset]);
            $offset++;

            if ($marcador === self::MARCADOR_DE_IMAGEM) {
                $quadros++;
                if ($quadros > 1) {
                    return true;
                }
                if (!self::pularDescritorDeImagem($bytes, $offset)) {
                    return false;
                }
                continue;
            }

            // Qualquer outro marcador — inclusive o trailer 0x3B, que fecha o
            // arquivo — encerra a contagem com um quadro só.
            if ($marcador !== self::MARCADOR_DE_EXTENSAO || !self::pularExtensao($bytes, $offset)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Pula a tabela de cores (global ou local) que o byte de flags anuncia.
     * Sem tabela, não há nada a pular e a estrutura continua válida.
     *
     * @param int $flags Byte de flags do descritor que precede a tabela
     */
    private static function pularTabelaDeCores(string $bytes, int &$offset, int $flags): bool
    {
        if (($flags & 0x80) === 0) {
            return true;
        }

        $bytesDaTabela = 3 * (1 << (($flags & 0x07) + 1));
        if (strlen($bytes) - $offset < $bytesDaTabela) {
            return false;
        }
        $offset += $bytesDaTabela;

        return true;
    }

    /**
     * Pula um Image Descriptor já aberto pelo marcador 0x2C: os 9 bytes fixos,
     * a tabela de cores local, o LZW Minimum Code Size e os sub-blocos de
     * dados.
     */
    private static function pularDescritorDeImagem(string $bytes, int &$offset): bool
    {
        $tamanho = strlen($bytes);
        if ($tamanho - $offset < 9) {
            return false;
        }

        $flags = ord($bytes[$offset + 8]);
        $offset += 9;
        if (!self::pularTabelaDeCores($bytes, $offset, $flags)) {
            return false;
        }
        if ($offset >= $tamanho) {
            return false;
        }

        $offset++; // LZW Minimum Code Size

        return self::pularSubblocos($bytes, $offset);
    }

    /**
     * Pula um bloco de extensão já aberto pelo marcador 0x21. A Graphic
     * Control Extension tem tamanho fixo; as demais (comentário, texto,
     * aplicação) são um bloco de tamanho declarado seguido de sub-blocos.
     */
    private static function pularExtensao(string $bytes, int &$offset): bool
    {
        $tamanho = strlen($bytes);
        if ($offset >= $tamanho) {
            return false;
        }

        $rotulo = ord($bytes[$offset]);
        $offset++;
        if ($rotulo === self::ROTULO_DE_CONTROLE_GRAFICO) {
            return self::pularControleGrafico($bytes, $offset);
        }

        if ($offset >= $tamanho) {
            return false;
        }
        $tamanhoDoBloco = ord($bytes[$offset]);
        $offset++;
        if ($tamanho - $offset < $tamanhoDoBloco) {
            return false;
        }
        $offset += $tamanhoDoBloco;

        return self::pularSubblocos($bytes, $offset);
    }

    /**
     * Graphic Control Extension: bloco fixo de 4 bytes anunciado por um byte
     * de tamanho igual a 4 e fechado por um terminador zero.
     */
    private static function pularControleGrafico(string $bytes, int &$offset): bool
    {
        $tamanho = strlen($bytes);
        if ($offset >= $tamanho || ord($bytes[$offset]) !== 4) {
            return false;
        }
        $offset++;
        if ($tamanho - $offset < 5) {
            return false;
        }
        $offset += 4;
        if (ord($bytes[$offset]) !== 0) {
            return false;
        }
        $offset++;

        return true;
    }

    /**
     * Avança uma sequência GIF de sub-blocos, incluindo o terminador zero.
     * O limite explícito evita ler fora do corpo recebido.
     */
    private static function pularSubblocos(string $bytes, int &$offset): bool
    {
        $tamanho = strlen($bytes);
        while ($offset < $tamanho) {
            $tamanhoDoBloco = ord($bytes[$offset]);
            $offset++;
            if ($tamanhoDoBloco === 0) {
                return true;
            }
            if ($tamanho - $offset < $tamanhoDoBloco) {
                return false;
            }
            $offset += $tamanhoDoBloco;
        }

        return false;
    }
}
