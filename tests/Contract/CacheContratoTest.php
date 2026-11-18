<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contrato do cache separado do PHPStan. O hash da configuração vem antes do
 * SHA do commit para que o primeiro restore-key realmente filtre a mesma
 * configuração, enquanto PRs continuam sem permissão de gravação.
 */
final class CacheContratoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';
    private const SHA_V610 = '55cc8345863c7cc4c66a329aec7e433d2d1c52a9';

    public function testCacheDoPhpstanPriorizaMesmoLockEConfiguracao(): void
    {
        $workflow = file_get_contents(self::RAIZ . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);

        $hash = 'hashFiles(\'composer.lock\', \'phpstan*.neon\', \'tools/phpstan/bootstrap.php\')';
        $chave = 'phpstan-${{ runner.os }}-php-8.5-${{ ' . $hash . ' }}-${{ github.sha }}';
        $prefixo = 'phpstan-${{ runner.os }}-php-8.5-${{ ' . $hash . ' }}-';

        self::assertStringContainsString(
            "          key: {$chave}\n",
            $workflow,
        );
        self::assertStringContainsString(
            "            {$prefixo}\n",
            $workflow,
        );
        self::assertStringNotContainsString(
            'phpstan-${{ runner.os }}-php-8.5-${{ github.sha }}-${{ ' . $hash . ' }}',
            $workflow,
        );
        self::assertStringContainsString(
            '          key: ${{ steps.phpstan-cache.outputs.cache-primary-key }}',
            $workflow,
        );
    }

    public function testRestoreESaveUsamMesmoPinEGravacaoSoEmEventosConfiaveis(): void
    {
        $workflow = file_get_contents(self::RAIZ . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);

        self::assertSame(
            1,
            substr_count(
                $workflow,
                'uses: actions/cache/restore@' . self::SHA_V610 . ' # v6.1.0',
            ),
        );
        self::assertSame(
            1,
            substr_count(
                $workflow,
                'uses: actions/cache/save@' . self::SHA_V610 . ' # v6.1.0',
            ),
        );
        self::assertStringContainsString(
            "        if: \${{ success() && (github.event_name == 'push' || github.event_name == 'workflow_dispatch') }}\n",
            $workflow,
        );
        self::assertStringContainsString(
            "          fail-on-cache-miss: false\n",
            $workflow,
        );
        self::assertStringContainsString(
            "          lookup-only: false\n",
            $workflow,
        );
        self::assertStringContainsString(
            "          enableCrossOsArchive: false\n",
            $workflow,
        );
    }
}
