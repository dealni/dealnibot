<?php

declare(strict_types=1);

/**
 * `/health` — liveness. O processo está vivo?
 *
 * NÃO toca em banco, Redis nem rede, e é por isso que ele é um arquivo próprio
 * em vez de um parâmetro do `/ready`: o que separa as duas sondas é justamente
 * o que cada uma tem permissão de fazer, e um único arquivo com um `if` acaba,
 * mais cedo ou mais tarde, com uma consulta compartilhada no topo.
 *
 * Não carrega `inclusos/`: `dados_bot.php` traz token e credencial, e uma rota
 * sem autenticação não precisa deles em memória.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Support\ContextoDeExecucao;
use App\Support\Sonda;

ContextoDeExecucao::iniciarCicloWeb();
Sonda::responder(Sonda::vivo());
