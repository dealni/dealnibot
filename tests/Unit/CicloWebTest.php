<?php

declare(strict_types=1);

namespace Tests;

use App\Support\ContextoDeExecucao;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * `inclusos/ciclo_web.php` — a primeira linha de todo entrypoint web.
 *
 * É um `require` com efeito, e não uma função, de propósito: função precisa ser
 * chamada, e "esqueci de chamar" é a falha que o arquivo evita. O que se prova
 * aqui é justamente o efeito — incluir fixa o `request_id` do ciclo — e as duas
 * garantias que o tornam seguro de pôr no topo de um webhook de pagamento:
 * incluir duas vezes não troca o id, e a ausência do `vendor/` não derruba nada.
 */
final class CicloWebTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIncluirFixaORequestIdDoCiclo(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'ciclo-de-entrada_123';
        ContextoDeExecucao::reset();

        require dirname(__DIR__, 2) . '/inclusos/ciclo_web.php';

        $this->assertSame('ciclo-de-entrada_123', ContextoDeExecucao::requestId());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIncluirDuasVezesNaoTrocaOIdDoCiclo(): void
    {
        // Os entrypoints se incluem uns aos outros; incluir de novo não pode
        // reabrir o ciclo, senão metade das linhas de uma requisição sairia com
        // um id e metade com outro.
        unset($_SERVER['HTTP_X_REQUEST_ID']);
        ContextoDeExecucao::reset();

        require dirname(__DIR__, 2) . '/inclusos/ciclo_web.php';
        $primeiro = ContextoDeExecucao::requestId();

        require dirname(__DIR__, 2) . '/inclusos/ciclo_web.php';

        $this->assertSame($primeiro, ContextoDeExecucao::requestId());
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{8,64}\z/', $primeiro);
    }

    public function testOArquivoNaoReclamaQuandoNaoHaAutoload(): void
    {
        // Sem `vendor/` (script solto, bot legado) o arquivo não faz nada e não
        // avisa: logar e correlacionar não podem derrubar um webhook.
        $fonte = file_get_contents(dirname(__DIR__, 2) . '/inclusos/ciclo_web.php');
        $this->assertIsString($fonte);
        $this->assertStringContainsString('if (is_file($autoloadDoCiclo))', $fonte);
        $this->assertStringContainsString(
            'if (class_exists(\App\Support\ContextoDeExecucao::class))',
            $fonte
        );
    }
}
