<?php

declare(strict_types=1);

/**
 * Milissegundos inteiros entre duas leituras de hrtime(true).
 *
 * O relógio é o monotônico de propósito: time() e microtime() seguem o relógio
 * de parede, que o NTP pode adiantar ou atrasar no meio do atendimento e
 * produzir duração negativa ou inflada. O piso em zero só protege contra
 * leituras trocadas por quem chama.
 *
 * Aceita `int|float` porque é o tipo de hrtime(true): o contador chega como
 * float só em plataforma de 32 bits, onde não cabe em int. O corte para int
 * descarta a fração de milissegundo.
 */
function duracaoEmMilissegundos(int|float $inicioNs, int|float $fimNs): int
{
    return (int) (max(0, $fimNs - $inicioNs) / 1_000_000);
}
