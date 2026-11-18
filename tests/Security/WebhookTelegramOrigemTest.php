<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../inclusos/funcoes_webhook.php';

/**
 * webhookTelegramAutorizado() — prova de origem do webhook do Telegram
 * (bot_web.php).
 *
 * bot_web.php é a URL pública que recebe TODOS os updates e o roteador atende
 * qualquer corpo que chegue nela: um POST forjado cai em comandos/chatgpt.php,
 * ver_imagem.php ou ver_audio.php e GASTA a chave da OpenAI do dono do bot. O
 * limite de inclusos/limites_requisicoes.php é por `from.id`, então o atacante
 * o contorna trocando o id a cada requisição. O único mecanismo que o Telegram
 * oferece para provar a origem é o secret_token registrado no setWebhook
 * (config/config_webhook.php), devolvido no header
 * X-Telegram-Bot-Api-Secret-Token.
 *
 * bot_web.php não pode ser incluído nos testes (instancia Telegram/banco/Redis
 * reais e despacha comandos), então a função mora em
 * inclusos/funcoes_webhook.php, que só declara e por isso é carregado aqui com
 * `require_once` de verdade — é o que faz a cobertura ser atribuída ao arquivo
 * de produção, em vez de se perder no `eval()` do tokenizer.
 */
final class WebhookTelegramOrigemTest extends TestCase
{
    private const CAMINHO_SCRIPT = __DIR__ . '/../../bot_web.php';

    public function testAceitaSomenteOSegredoExatoQuandoConfigurado(): void
    {
        $segredo = 'segredo-do-webhook-0123456789abcd';
        $this->assertTrue(webhookTelegramAutorizado($segredo, $segredo));
        $this->assertFalse(webhookTelegramAutorizado($segredo, substr($segredo, 0, -1)));
        $this->assertFalse(webhookTelegramAutorizado($segredo, $segredo . ' '));
        $this->assertFalse(webhookTelegramAutorizado($segredo, strtoupper($segredo)));
        $this->assertFalse(webhookTelegramAutorizado($segredo, str_repeat('x', 32)));
    }

    public function testRecusaRequisicaoSemOHeaderQuandoOSegredoEstaConfigurado(): void
    {
        // É este o ataque: POST direto na URL do webhook, sem header nenhum,
        // com um update forjado que dispara uma chamada paga à OpenAI.
        $segredo = 'segredo-do-webhook-0123456789abcd';
        $this->assertFalse(webhookTelegramAutorizado($segredo, null));
        $this->assertFalse(webhookTelegramAutorizado($segredo, ''));
        // Header não textual (mixed vindo de $_SERVER) não pode virar TypeError
        // — uma exceção aqui derrubaria o webhook inteiro.
        $this->assertFalse(webhookTelegramAutorizado($segredo, [$segredo]));
        $this->assertFalse(webhookTelegramAutorizado($segredo, 0));
        $this->assertFalse(webhookTelegramAutorizado($segredo, false));
    }

    public function testAcertarOPrefixoDoSegredoNaoAjudaOAtacante(): void
    {
        // hash_equals: comparação do valor inteiro e em tempo constante.
        $segredo = str_repeat('a', 32);
        $this->assertFalse(webhookTelegramAutorizado($segredo, substr($segredo, 0, 31)));
        $this->assertFalse(webhookTelegramAutorizado($segredo, $segredo . 'a'));
    }

    public function testSemSegredoConfiguradoOWebhookFalhaFechado(): void
    {
        $this->assertFalse(webhookTelegramAutorizado(null, null));
        $this->assertFalse(webhookTelegramAutorizado('', 'qualquer-coisa'));
        $this->assertFalse(webhookTelegramAutorizado(null, 'qualquer-coisa'));
    }

    public function testConstanteMalDefinidaNaoLiberaNemQuebraOWebhook(): void
    {
        // Tipo errado não pode virar TypeError nem desligar o gate.
        $this->assertFalse(webhookTelegramAutorizado(['segredo'], 'segredo'));
        $this->assertFalse(webhookTelegramAutorizado(12345, '12345'));
        $this->assertFalse(webhookTelegramAutorizado(true, 'segredo'));
        $this->assertFalse(webhookTelegramAutorizado('segredo com espaço', 'segredo com espaço'));
        $this->assertFalse(webhookTelegramAutorizado(str_repeat('a', 257), str_repeat('a', 257)));
    }

    /**
     * O caractere "0" é o clássico falso-negativo de `empty()`/`!$valor`: um
     * segredo literalmente igual a "0" precisa continuar CONFIGURADO, senão o
     * gate se desligaria sozinho e o webhook voltaria a aceitar tudo.
     */
    public function testSegredoCurtoEhRecusado(): void
    {
        $this->assertFalse(webhookTelegramAutorizado('0', '0'));
        $this->assertFalse(webhookTelegramAutorizado('0', ''));
        $this->assertFalse(webhookTelegramAutorizado('0', null));
        $this->assertFalse(webhookTelegramAutorizado('0', '1'));
    }

    public function testSegredoComCaracteresEspeciaisEComparadoLiteralmente(): void
    {
        // O Telegram aceita [A-Za-z0-9_-]; mesmo assim nada aqui pode interpretar
        // o valor (sem regex, sem trim, sem case-insensitive).
        $segredo = str_repeat('A-b_C-1_2', 4);
        $this->assertTrue(webhookTelegramAutorizado($segredo, $segredo));
        $this->assertFalse(webhookTelegramAutorizado($segredo, strtolower($segredo)));
        $this->assertFalse(webhookTelegramAutorizado($segredo, str_repeat('.', 32)));
    }

    // ---------------------------------------------------------------------
    // Precondições que os testes acima assumem sobre o topo do script
    // ---------------------------------------------------------------------

    private static function codigoSemComentarios(string $caminho): string
    {
        $conteudo = file_get_contents($caminho);
        self::assertIsString($conteudo, "Não foi possível ler {$caminho}.");

        $saida = '';
        foreach (token_get_all($conteudo) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $saida .= is_array($token) ? $token[1] : $token;
        }

        return $saida;
    }

    public function testConfigDoWebhookRegistraOSegredoQuandoEleEstaConfigurado(): void
    {
        // Contraparte obrigatória do gate: sem enviar secret_token no setWebhook,
        // o Telegram nunca manda o header e ligar TELEGRAM_WEBHOOK_SECRET
        // derrubaria 100% dos updates legítimos.
        //
        // config/config_webhook.php REGISTRA o webhook de verdade ao ser
        // incluído, então aqui só o código é inspecionado — jamais executado.
        $codigo = self::codigoSemComentarios(__DIR__ . '/../../config/config_webhook.php');

        $this->assertStringContainsString('setWebhook', $codigo);
        $this->assertStringContainsString('TELEGRAM_WEBHOOK_SECRET', $codigo);
        $this->assertStringContainsString("'secret_token'", $codigo);

        // O segredo é obrigatório: o registrador não pode montar uma chamada
        // sem ele, nem sequer como fallback condicional.
        $this->assertStringContainsString(
            "'secret_token' => constant('TELEGRAM_WEBHOOK_SECRET')",
            $codigo
        );
    }

    public function testTopoDoScriptAplicaOGateAntesDeQualquerEfeito(): void
    {
        // A função só protege alguma coisa se o topo a CHAMAR, e a chamada
        // precisa vir antes de instanciar Telegram/banco/Redis e antes do
        // require do roteador — senão o update forjado já teria custado
        // conexão, registro de usuário e, no pior caso, uma chamada paga.
        $fonte = file_get_contents(self::CAMINHO_SCRIPT);
        $this->assertIsString($fonte, 'bot_web.php precisa ser legível.');

        $posicaoGate = strpos($fonte, 'if (!webhookTelegramAutorizado(');
        $this->assertIsInt($posicaoGate, 'bot_web.php precisa aplicar o gate de origem no topo.');

        foreach ([
            "\$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null",
            "defined('TELEGRAM_WEBHOOK_SECRET') ? constant('TELEGRAM_WEBHOOK_SECRET') : null",
            'http_response_code(403);',
        ] as $trecho) {
            $this->assertStringContainsString($trecho, $fonte, "O topo deixou de conter: {$trecho}");
        }

        foreach ([
            '$telegram = new Telegram(TOKEN_BOT);',
            '$bd = new bdTelegram();',
            '$redis = conectarRedis();',
            'processarProximoUpdateWebhook(',
        ] as $efeito) {
            $posicaoEfeito = strpos($fonte, $efeito);
            $this->assertIsInt($posicaoEfeito, "bot_web.php precisa continuar contendo: {$efeito}");
            $this->assertGreaterThan(
                $posicaoGate,
                $posicaoEfeito,
                "O gate de origem precisa vir ANTES de: {$efeito}"
            );
        }
    }

    /**
     * Sob php-fpm, "Connection: close" + flush() NÃO encerram a requisição: o
     * Telegram só recebe o 200 quando o script termina e, até lá, reenvia o
     * update — o usuário recebe a mesma resposta da IA várias vezes e cada
     * reenvio é cobrado de novo na OpenAI. Só fastcgi_finish_request() devolve
     * a resposta de fato, e ela precisa vir DEPOIS do flush() e ANTES do
     * roteador (que é a parte demorada).
     */
    public function testTopoEncerraARequisicaoAntesDeRotearParaEvitarReenvio(): void
    {
        $fonte = file_get_contents(self::CAMINHO_SCRIPT);
        $this->assertIsString($fonte, 'bot_web.php precisa ser legível.');

        $posicaoFlush = strpos($fonte, 'flush(); // Forçar o PHP a enviar a saída para o navegador');
        $posicaoFinish = strpos($fonte, 'fastcgi_finish_request();');
        $posicaoGuarda = strpos($fonte, "if (function_exists('fastcgi_finish_request'))");
        $posicaoRoteador = strrpos($fonte, 'processarProximoUpdateWebhook(');

        $this->assertIsInt($posicaoFlush);
        $this->assertIsInt($posicaoFinish, 'bot_web.php precisa encerrar a requisição com fastcgi_finish_request().');
        // A guarda existe porque a função só existe sob php-fpm: no CLI
        // (bot.php em polling) a chamada direta seria fatal.
        $this->assertIsInt($posicaoGuarda, 'A chamada precisa continuar protegida por function_exists().');
        $this->assertIsInt($posicaoRoteador);

        $this->assertGreaterThan($posicaoFlush, $posicaoFinish);
        $this->assertLessThan($posicaoRoteador, $posicaoFinish);
        $this->assertLessThan($posicaoFinish, $posicaoGuarda);
    }

    /**
     * O gate é declarado em inclusos/funcoes_webhook.php e este arquivo de
     * teste o carrega com require_once próprio. Isso abre um ponto cego: se
     * bot_web.php deixar de incluir o declarador, a suíte segue verde e a
     * produção morre no primeiro update com "Call to undefined function
     * webhookTelegramAutorizado()" — e um fatal antes do gate é o webhook
     * aberto até o PHP desistir.
     */
    public function testBotWebCarregaODeclaradorDoGateAntesDeChamaLo(): void
    {
        $fonte = file_get_contents(__DIR__ . '/../../bot_web.php');
        $this->assertIsString($fonte, 'bot_web.php precisa ser legível.');

        $posicaoRequire = strpos($fonte, "require_once __DIR__ . '/inclusos/funcoes_webhook.php';");
        $posicaoChamada = strpos($fonte, 'if (!webhookTelegramAutorizado(');

        $this->assertIsInt($posicaoRequire, 'bot_web.php precisa incluir inclusos/funcoes_webhook.php.');
        $this->assertIsInt($posicaoChamada, 'bot_web.php deixou de chamar o gate de origem.');
        $this->assertLessThan(
            $posicaoChamada,
            $posicaoRequire,
            'O require_once do declarador precisa vir antes da chamada ao gate.'
        );
    }
}
