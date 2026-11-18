<?php

declare(strict_types=1);

namespace App\Support;

/** Estado compartilhado pelo lote e pelo callback de encerramento. */
final class EstadoPolling
{
    public ?int $offsetProcessado = null;
    public bool $vivo = true;
}
