<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Wrapper de stream que simula um disco que ACEITA criar o arquivo e depois
 * falha ao gravar o conteúdo — o que acontece de verdade com disco cheio, cota
 * de usuário estourada ou pipe quebrado.
 *
 * É o jeito determinístico de exercitar o caminho em que file_put_contents()
 * devolve false DEPOIS de o arquivo já existir: exatamente o vazamento de
 * arquivo temporário que comandos/ver_audio.php precisa limpar. Nada é escrito
 * em disco — os arquivos "criados" ficam apenas nesta lista em memória.
 */
final class DiscoQueFalhaNaEscrita
{
    /** Protocolo registrado no PHP para este disco simulado. */
    public const PROTOCOLO = 'disco-cheio';

    /** @var array<string, string> Arquivos existentes no disco simulado. */
    public static array $arquivos = [];

    /** @var resource|null Contexto do stream (exigido pela API de wrappers). */
    public $context;

    /**
     * Registra o wrapper e devolve o "diretório" que o teste deve usar como
     * caminho temporário.
     *
     * @return string
     */
    public static function registrar(): string
    {
        self::remover();
        stream_wrapper_register(self::PROTOCOLO, self::class);

        return self::PROTOCOLO . '://disco';
    }

    /** Remove o wrapper e esvazia o disco simulado. */
    public static function remover(): void
    {
        if (in_array(self::PROTOCOLO, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::PROTOCOLO);
        }
        self::$arquivos = [];
    }

    /**
     * Arquivos que sobraram no disco simulado. Depois de uma falha de escrita
     * bem tratada esta lista precisa estar vazia.
     *
     * @return array<int, string>
     */
    public static function arquivosRestantes(): array
    {
        return array_keys(self::$arquivos);
    }

    /**
     * @param string $path
     * @param string $mode
     * @param int $options
     * @param string|null $opened_path
     * @return bool
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        // Abrir para escrita cria o arquivo, como em um disco real.
        self::$arquivos[$path] = '';

        return true;
    }

    /**
     * Zero bytes gravados: file_put_contents() devolve false com o arquivo já
     * criado.
     *
     * @param string $data
     * @return int
     */
    public function stream_write(string $data): int
    {
        return 0;
    }

    /**
     * @param int $count
     * @return string
     */
    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return self::estatisticas();
    }

    /**
     * @param string $path
     * @param int $flags
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags)
    {
        if (!isset(self::$arquivos[$path])) {
            return false;
        }

        return self::estatisticas();
    }

    /**
     * @param string $path
     * @return bool
     */
    public function unlink(string $path): bool
    {
        if (!isset(self::$arquivos[$path])) {
            return false;
        }
        unset(self::$arquivos[$path]);

        return true;
    }

    /**
     * Stat de arquivo comum (S_IFREG | 0644), com as chaves numéricas e
     * nomeadas que o PHP espera de um wrapper.
     *
     * @return array<int|string, int>
     */
    private static function estatisticas(): array
    {
        $valores = [
            'dev' => 0,
            'ino' => 0,
            'mode' => 0100644,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => 0,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => -1,
            'blocks' => -1,
        ];

        return array_merge(array_values($valores), $valores);
    }
}
