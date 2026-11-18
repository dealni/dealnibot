<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../inclusos/SqlPostgres.php';

final class SqlPostgresLimitePcreTest extends TestCase
{
    public function testEsgotamentoDoPcreRecusaSqlSemDevolverConversaoParcial(): void
    {
        $anterior = ini_get('pcre.backtrack_limit');
        self::assertIsString($anterior);
        try {
            ini_set('pcre.backtrack_limit', '1');
            try {
                \SqlPostgres::converter("SELECT 'texto longo sem fecho");
                self::fail('O limite real do PCRE precisa impedir a conversão.');
            } catch (RuntimeException $erro) {
                self::assertSame('SQL inválido.', $erro->getMessage());
                self::assertSame(PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
            }
        } finally {
            ini_set('pcre.backtrack_limit', $anterior);
        }
    }
}
