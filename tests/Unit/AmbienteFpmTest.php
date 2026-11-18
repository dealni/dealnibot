<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

use function Quality\mainAmbienteFpm;

use const Quality\FPM_BOT;
use const Quality\FPM_TIMEOUT;

require_once __DIR__ . '/../../scripts/quality/check_fpm_environment.php';

/**
 * O nome do bot e o teto de execução vêm do bloco de dados do próprio portão:
 * é o que mantém este arquivo sem dado local e impede que o caso do timeout
 * vire inerte num bot cujo pool não usa o valor escrito aqui à mão.
 */
final class AmbienteFpmTest extends TestCase
{
    public function testContratoRealEOverridesInsegurosSaoConferidos(): void
    {
        $root = dirname(__DIR__, 2);
        $base = sys_get_temp_dir() . '/fpm-contrato-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base . '/deploy/php-fpm', 0700, true));
        self::assertTrue(mkdir($base . '/deploy/systemd', 0700));
        $poolPath = '/deploy/php-fpm/' . FPM_BOT . '.conf';
        $dropPath = '/deploy/systemd/php8.5-fpm-' . FPM_BOT . '-environment.conf';
        $pool = file_get_contents($root . $poolPath);
        $drop = file_get_contents($root . $dropPath);
        self::assertIsString($pool);
        self::assertIsString($drop);

        // Sem esta guarda, um pool que deixasse de declarar o teto faria o caso
        // seguinte trocar nada e passar a aprovar o próprio arquivo original.
        $timeoutEncurtado = str_replace(
            'request_terminate_timeout = ' . FPM_TIMEOUT,
            'request_terminate_timeout = 1s',
            $pool,
        );
        self::assertNotSame($pool, $timeoutEncurtado, 'O pool precisa declarar o teto do bloco de dados.');

        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        try {
            self::assertSame(1, mainAmbienteFpm([], $base, $output));
            $cases = [
                [$pool, $drop, 0],
                [$pool . "\nenv[REDIS_PORT] = 9999\n", $drop, 1],
                [str_replace('clear_env = yes', 'clear_env = no', $pool), $drop, 1],
                [$pool, $drop . "EnvironmentFile=/etc/outro/environment\n", 1],
                ['[outro]', $drop, 1],
                ['[malformado', $drop, 1],
                [$timeoutEncurtado, $drop, 1],
                [str_replace('request_terminate_timeout_track_finished = no', 'request_terminate_timeout_track_finished = yes', $pool), $drop, 1],
                [str_replace('security.limit_extensions = .php', 'security.limit_extensions = .php .phar', $pool), $drop, 1],
            ];
            foreach ($cases as [$poolValue, $dropValue, $status]) {
                self::assertNotFalse(file_put_contents($base . $poolPath, $poolValue));
                self::assertNotFalse(file_put_contents($base . $dropPath, $dropValue));
                self::assertSame($status, mainAmbienteFpm([], $base, $output));
            }
        } finally {
            fclose($output);
            foreach ([$poolPath, $dropPath] as $file) {
                if (is_file($base . $file)) {
                    unlink($base . $file);
                }
            }
            rmdir($base . '/deploy/php-fpm');
            rmdir($base . '/deploy/systemd');
            rmdir($base . '/deploy');
            rmdir($base);
        }
    }
}
