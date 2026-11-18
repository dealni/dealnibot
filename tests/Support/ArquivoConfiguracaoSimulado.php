<?php

declare(strict_types=1);

namespace Tests\Support;

/** Redireciona somente o include de config.php para credenciais sintéticas. */
final class ArquivoConfiguracaoSimulado
{
    public mixed $context;
    private static string $alvo;
    private static string $fixture;
    private static bool $restaurarAoFechar = false;
    private bool $substituido = false;
    public static int $leituras = 0;
    /** @var resource */
    private $arquivo;

    public static function ativar(string $alvo, string $fixture, bool $restaurarAoFechar = false): void
    {
        self::$alvo = $alvo;
        self::$restaurarAoFechar = $restaurarAoFechar;
        self::$fixture = $fixture;
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);
    }

    public static function restaurar(): void
    {
        stream_wrapper_restore('file');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $substituir = $path === self::$alvo;
        $this->substituido = $substituir;
        if ($substituir) {
            self::$leituras++;
        }
        stream_wrapper_restore('file');
        try {
            $arquivo = fopen($substituir ? self::$fixture : $path, $mode);
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
        if ($arquivo === false) {
            return false;
        }
        $this->arquivo = $arquivo;
        $openedPath = $path;
        return true;
    }

    public function stream_read(int $count): string|false
    {
        return $count <= 0 ? '' : fread($this->arquivo, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->arquivo);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->arquivo, $offset, $whence) === 0;
    }

    public function stream_tell(): int|false
    {
        return ftell($this->arquivo);
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return fstat($this->arquivo);
    }

    public function stream_close(): void
    {
        fclose($this->arquivo);
        if ($this->substituido && self::$restaurarAoFechar) {
            self::restaurar();
        }
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        stream_wrapper_restore('file');
        try {
            return @stat($path === self::$alvo ? self::$fixture : $path);
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
    }
}
