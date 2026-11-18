<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/** Escopo do update atual; polling e jobs fora da inbox não têm lease. */
final class LeaseWebhook
{
    public const int SEGUNDOS = 360;

    private static ?Closure $renovar = null;

    /**
     * @param (Closure(): void)|null $renovar
     * @return (Closure(): void)|null
     */
    public static function definir(?Closure $renovar): ?Closure
    {
        $anterior = self::$renovar;
        self::$renovar = $renovar;

        return $anterior;
    }

    /** Falha fechada antes da próxima chamada externa, inclusive reenvios. */
    public static function renovar(): void
    {
        if (self::$renovar !== null) {
            (self::$renovar)();
        }
    }
}
