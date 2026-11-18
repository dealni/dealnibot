<?php

declare(strict_types=1);

namespace App\Support;

/** A resposta está persistida; o cron retomará somente a entrega. */
final class RespostaIaAdiada extends \RuntimeException
{
}
