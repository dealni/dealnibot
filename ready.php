<?php

declare(strict_types=1);

/**
 * `/ready` — readiness. O processo consegue ATENDER?
 *
 * Checa cada dependência real com timeout curto. QUAIS são elas é a única coisa
 * que muda de bot para bot, e por isso a lista mora em
 * `inclusos/funcoes_sondas.php` — aqui e em `src/Support/Sonda` só existe a
 * mecânica, sem valor de produto. O que cada sonda faz, e por que faz assim
 * (banco em SOMENTE LEITURA, conexão de cache nova e não persistente, nada de
 * `conectarRedis()` — que encerra o processo com `exit(1)`, quando aqui a falha
 * é a RESPOSTA e não o fim), está documentado lá.
 *
 * O corpo nunca revela caminho, host ou mensagem de erro: `checks` é `ok`/`erro`
 * e nada mais, porque a rota não tem autenticação.
 */
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/inclusos/caminhos.php';
require_once __DIR__ . '/inclusos/funcoes_sondas.php';

use App\Support\ContextoDeExecucao;
use App\Support\Sonda;

ContextoDeExecucao::iniciarCicloWeb();
Sonda::responder(Sonda::pronto(sondasDeDependencia()));
