<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Redige JSON completo ou embutido em uma mensagem sem depender de o chamador
 * ter produzido um documento JSON completo.
 *
 * A classe conhece apenas a mecânica do JSON. A política de chaves proibidas e
 * a redação de valores complexos ficam no processador de observabilidade e
 * entram por callbacks para que não haja uma segunda lista de segredos.
 */
final class RedatorJson
{
    private const PADRAO_DE_ESCALAR =
        '/((?:\\\\)?"((?:[^"\\\\]|\\\\.)+)(?:\\\\)?"\s*:\s*)' .
        '("(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?|true|false|null)/';

    /** @var \Closure(mixed): mixed */
    private readonly \Closure $redigirValor;

    /** @var \Closure(string): bool */
    private readonly \Closure $chaveProibida;

    /**
     * @param callable(mixed): mixed $redigirValor
     * @param callable(string): bool $chaveProibida
     */
    public function __construct(
        private readonly string $substituto,
        callable $redigirValor,
        callable $chaveProibida,
    ) {
        $this->redigirValor = \Closure::fromCallable($redigirValor);
        $this->chaveProibida = \Closure::fromCallable($chaveProibida);
    }

    public function redigir(string $texto): string
    {
        $texto = $this->redigirBlocosJson($texto);
        $texto = $this->redigirFragmentosJson($texto);

        return $this->redigirEscalaresJson($texto);
    }

    /**
     * Trata fragmentos `"chave": [...]`/`"chave": {...}` sem objeto externo.
     */
    private function redigirFragmentosJson(string $texto): string
    {
        $resultado = '';
        $cursor = 0;
        $comprimento = strlen($texto);
        $padrao = '/(?:\\\\)?"((?:[^"\\\\]|\\\\.)+)(?:\\\\)?"\s*:\s*([\\[{])/';

        while (preg_match($padrao, $texto, $casamento, PREG_OFFSET_CAPTURE, $cursor) === 1) {
            $inicioValor = $casamento[2][1];
            $fim = $this->fimDoBlocoJson($texto, $inicioValor);
            $fimEfetivo = $fim ?? ($comprimento - 1);
            $bloco = substr($texto, $inicioValor, $fimEfetivo - $inicioValor + 1);
            $chave = $this->decodificarChaveJson($casamento[1][0]);
            [$blocoValido, $decodificado] = $this->decodificarBloco($bloco);
            $proibida = $chave === null || ($this->chaveProibida)($chave);

            if ($proibida || $blocoValido) {
                $resultado .= substr($texto, $cursor, $inicioValor - $cursor);
                $resultado .= $this->redigirFragmento($proibida, $bloco, $decodificado);
                $cursor = $fimEfetivo + 1;
                continue;
            }

            // Valor inválido não proibido: conserva a abertura e permite que
            // um bloco interno válido ainda seja encontrado no próximo loop.
            $resultado .= substr($texto, $cursor, $inicioValor - $cursor + 1);
            $cursor = $inicioValor + 1;
        }

        return $resultado . substr($texto, $cursor);
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function decodificarBloco(string $bloco): array
    {
        try {
            $decodificado = json_decode($bloco, false, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [false, null];
        }

        return [is_array($decodificado) || $decodificado instanceof \stdClass, $decodificado];
    }

    private function redigirFragmento(bool $proibida, string $bloco, mixed $decodificado): string
    {
        if ($proibida) {
            return '"' . $this->substituto . '"';
        }

        try {
            $redigido = ($this->redigirValor)($decodificado);
            $originalCanonico = json_encode(
                $decodificado,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $redigidoCanonico = json_encode(
                $redigido,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );

            return $originalCanonico === $redigidoCanonico ? $bloco : $redigidoCanonico;
        } catch (\Throwable) {
            return '"' . $this->substituto . '"';
        }
    }

    /**
     * Redige objetos e listas JSON embutidos numa mensagem.
     */
    private function redigirBlocosJson(string $texto): string
    {
        $resultado = '';
        $comprimento = strlen($texto);

        for ($indice = 0; $indice < $comprimento; $indice++) {
            $abertura = $texto[$indice] ?? '';
            if ($abertura !== '{' && $abertura !== '[') {
                $resultado .= $abertura;
                continue;
            }

            $fim = $this->fimDoBlocoJson($texto, $indice);
            $fimEfetivo = $fim ?? ($comprimento - 1);
            $bloco = substr($texto, $indice, $fimEfetivo - $indice + 1);
            [$blocoValido, $decodificado] = $this->decodificarBloco($bloco);

            if ($blocoValido) {
                $resultado .= $this->redigirBlocoValido($bloco, $decodificado);
                $indice = $fimEfetivo;
                continue;
            }

            if ($this->blocoContemChaveProibida($bloco)) {
                $resultado .= '"' . $this->substituto . '"';
                $indice = $fimEfetivo;
                continue;
            }

            // Bloco inválido sem chave reconhecível: conserva o texto para não
            // destruir mensagens operacionais legítimas.
            $resultado .= $abertura;
        }

        return $resultado;
    }

    private function redigirBlocoValido(string $bloco, mixed $decodificado): string
    {
        try {
            $redigido = ($this->redigirValor)($decodificado);
            $originalCanonico = json_encode(
                $decodificado,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $redigidoCanonico = json_encode(
                $redigido,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );

            // Sem mudança, conserva espaços e a forma original da mensagem.
            return $originalCanonico === $redigidoCanonico ? $bloco : $redigidoCanonico;
        } catch (\Throwable) {
            return '"' . $this->substituto . '"';
        }
    }

    private function redigirEscalaresJson(string $texto): string
    {
        $redigido = preg_replace_callback(
            self::PADRAO_DE_ESCALAR,
            function (array $casamento): string {
                $chave = $this->decodificarChaveJson($casamento[2]);
                if ($chave === null || ($this->chaveProibida)($chave)) {
                    return $casamento[1] . '"' . $this->substituto . '"';
                }

                return $casamento[0];
            },
            $texto
        );

        return is_string($redigido) ? $redigido : $texto;
    }

    /**
     * Retorna o fechamento correspondente, ignorando delimitadores em strings.
     */
    private function fimDoBlocoJson(string $texto, int $inicio): ?int
    {
        $pilha = [($texto[$inicio] ?? '') === '{' ? '}' : ']'];
        $emString = false;
        $escapado = false;
        $comprimento = strlen($texto);

        for ($indice = $inicio + 1; $indice < $comprimento; $indice++) {
            $caractere = $texto[$indice];
            if ($emString) {
                $emString = $this->atualizarEstadoDaString($caractere, $escapado);
                continue;
            }
            if ($caractere === '"') {
                $emString = true;
                continue;
            }
            if ($this->empilharAbertura($caractere, $pilha)) {
                continue;
            }
            if (!in_array($caractere, ['}', ']'], true)) {
                continue;
            }
            if (end($pilha) !== $caractere || count($pilha) === 1) {
                return $indice;
            }
            array_pop($pilha);
        }

        return null;
    }

    private function atualizarEstadoDaString(string $caractere, bool &$escapado): bool
    {
        if ($escapado) {
            $escapado = false;
            return true;
        }
        if ($caractere === '\\') {
            $escapado = true;
            return true;
        }

        return $caractere !== '"';
    }

    /** @param list<string> $pilha */
    private function empilharAbertura(string $caractere, array &$pilha): bool
    {
        $fechamento = ['{' => '}', '[' => ']'][$caractere] ?? null;
        if ($fechamento === null) {
            return false;
        }

        $pilha[] = $fechamento;
        return true;
    }

    private function blocoContemChaveProibida(string $bloco): bool
    {
        $encontrado = preg_match_all(
            '/(?:\\\\)?"((?:[^"\\\\]|\\\\.)+)(?:\\\\)?"\s*:/',
            $bloco,
            $casamentos
        );
        if (!is_int($encontrado) || $encontrado === 0) {
            return false;
        }

        foreach ($casamentos[1] as $chaveCodificada) {
            $chave = $this->decodificarChaveJson($chaveCodificada);
            if ($chave === null || ($this->chaveProibida)($chave)) {
                return true;
            }
        }

        return false;
    }

    private function decodificarChaveJson(string $chaveCodificada): ?string
    {
        try {
            $chave = json_decode('"' . $chaveCodificada . '"', true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_string($chave) ? $chave : null;
    }
}
