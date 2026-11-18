<?php

declare(strict_types=1);

namespace Tests;

use App\Support\LoggerFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PermissoesDoLoggerTest extends TestCase
{
    public function testLogNovoEPreexistenteFicamPrivadosComLockDeEscrita(): void
    {
        $anterior = getenv('DEALNI_LOGS_DIR');
        $umask = umask(0022);
        $base = sys_get_temp_dir() . '/logger-privado-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base, 0755));
        putenv('DEALNI_LOGS_DIR=' . $base);
        try {
            foreach ([false, true] as $legado) {
                LoggerFactory::reset();
                if ($legado) {
                    self::assertTrue(chmod($base, 0755));
                    self::assertTrue(chmod(LoggerFactory::caminhoAppLogDeHoje(), 0644));
                }
                $logger = LoggerFactory::channel('teste_permissao', true);
                self::assertInstanceOf(Logger::class, $logger);
                foreach ($logger->getHandlers() as $handler) {
                    self::assertInstanceOf(StreamHandler::class, $handler);
                    self::assertTrue((new ReflectionProperty(StreamHandler::class, 'useLocking'))->getValue($handler));
                }
                $logger->info('registro artificial');
                $arquivo = LoggerFactory::caminhoAppLogDeHoje();
                clearstatcache();
                $modoDiretorio = fileperms($base);
                $modoArquivo = fileperms($arquivo);
                self::assertIsInt($modoDiretorio);
                self::assertIsInt($modoArquivo);
                self::assertSame(0700, $modoDiretorio & 0777);
                self::assertSame(0600, $modoArquivo & 0777);
                $logger->close();
            }
        } finally {
            LoggerFactory::reset();
            putenv(is_string($anterior) ? 'DEALNI_LOGS_DIR=' . $anterior : 'DEALNI_LOGS_DIR');
            umask($umask);
            $arquivos = glob($base . '/*');
            foreach ($arquivos === false ? [] : $arquivos as $arquivo) {
                unlink($arquivo);
            }
            rmdir($base);
        }
    }
}
