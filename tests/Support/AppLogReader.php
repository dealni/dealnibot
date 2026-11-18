<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\LoggerFactory;

/**
 * Leitura segura do app.log JSON sem carregar o arquivo inteiro na memória.
 */
final class AppLogReader
{
    public static function caminhoDeHoje(): string
    {
        return LoggerFactory::caminhoAppLogDeHoje();
    }

    /**
     * Conteúdo acrescentado após um offset em bytes (tamanho anterior).
     */
    public static function conteudoDesde(int $offsetBytes): string
    {
        $caminho = self::caminhoDeHoje();
        clearstatcache(true, $caminho);
        if (!is_file($caminho)) {
            return '';
        }

        $tamanho = filesize($caminho);
        if ($tamanho === false || $tamanho <= $offsetBytes) {
            return '';
        }

        $handle = fopen($caminho, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            if ($offsetBytes > 0) {
                fseek($handle, $offsetBytes);
            }

            $lido = stream_get_contents($handle);

            return is_string($lido) ? $lido : '';
        } finally {
            fclose($handle);
        }
    }

    public static function tamanhoAtual(): int
    {
        $caminho = self::caminhoDeHoje();
        clearstatcache(true, $caminho);
        if (!is_file($caminho)) {
            return 0;
        }

        $tamanho = filesize($caminho);

        return $tamanho === false ? 0 : $tamanho;
    }

    /**
     * Decodifica cada linha JSON não vazia do trecho.
     *
     * @return list<array<string, mixed>>
     */
    public static function linhasJson(string $trecho): array
    {
        $linhas = [];
        // preg_split() devolve false só com padrão inválido; sem trecho ele já
        // devolve a lista de uma string vazia, que o guard abaixo descarta.
        $partes = preg_split('/\R/', $trecho);
        foreach ($partes === false ? [] : $partes as $linha) {
            if ($linha === '') {
                continue;
            }
            $decoded = json_decode($linha, true);
            if (!is_array($decoded)) {
                continue;
            }

            $registro = [];
            foreach ($decoded as $chave => $valor) {
                if (!is_string($chave)) {
                    continue 2;
                }
                $registro[$chave] = $valor;
            }
            $linhas[] = $registro;
        }

        return $linhas;
    }
}
