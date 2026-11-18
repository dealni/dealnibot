<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Trava os TERMINADORES dos arquivos que bot.php executa dentro do laço de
 * polling: roteador.php e os comandos que ele inclui.
 *
 * Motivo: getUpdates() confirma o lote INTEIRO no Telegram antes da primeira
 * volta do laço (offset = último update_id + 1). Um `exit` em qualquer desses
 * arquivos encerra o processo no meio do lote e as mensagens restantes — já
 * confirmadas, logo nunca reentregues — somem sem resposta e sem log.
 * `return` devolve o controle ao roteador e ao laço de bot.php; no bot_web.php
 * o efeito é idêntico ao de antes, porque o require do roteador é a última
 * linha do arquivo.
 *
 * O efeito é verificado de verdade (subprocesso, laço real) em
 * InfraRoteadorPollingTest para o roteador. Para comandos/chatgpt.php isso é
 * impossível: incluí-lo dispara sendMessage e cURL reais. Então aqui o
 * arquivo é TOKENIZADO — nenhuma linha é executada — e a ausência do
 * terminador destrutivo é verificada na própria fonte.
 */
final class ChatgptTerminadoresTest extends TestCase
{
    /**
     * Arquivos executados no laço de polling que precisam devolver o controle
     * ao chamador. Os comandos de áudio e imagem ficam de fora de propósito:
     * ainda usam `exit` (registrado no relatório desta rodada) e o teste falso-
     * negativo seria pior do que a ausência dele.
     *
     * @return array<string, array{string}>
     */
    public static function arquivosDoLacoProvider(): array
    {
        return [
            'roteador.php' => [__DIR__ . '/../../roteador.php'],
            'comandos/chatgpt.php' => [__DIR__ . '/../../comandos/chatgpt.php'],
        ];
    }

    #[DataProvider('arquivosDoLacoProvider')]
    public function testNaoEncerraOProcessoDeQuemInclui(string $caminho): void
    {
        $ocorrencias = $this->linhasComTerminadorDestrutivo($caminho);

        $this->assertSame(
            [],
            $ocorrencias,
            'exit/die encerram o processo e descartam o resto do lote de updates. Linhas: '
                . implode(', ', $ocorrencias)
        );
    }

    public function testOComandoDeTextoTerminaCadaRamoDevolvendoOControle(): void
    {
        // Contraprova do teste acima: a ausência de exit não pode ter virado
        // ausência de terminador (o fluxo cairia no envio da resposta mesmo
        // depois de avisar o usuário sobre cota estourada ou erro da API).
        $fonte = Support\FonteDoComando::ler(__DIR__ . '/../../comandos/chatgpt.php');

        $ramos = [
            // texto ausente ou vazio
            "if (\$texto_recebido === null || \$texto_recebido === '') {\n        return;",
            // cota diária estourada
            "if (\$info_limite['atingiu_limite']) {",
            // corpo da requisição impossível de montar
            'if ($post_fields === false) {',
            // falha de transporte ou erro devolvido pela API
            "if (\$resposta_api['tipo'] !== 'resposta') {",
        ];
        foreach ($ramos as $ramo) {
            $this->assertStringContainsString($ramo, $fonte, "O comando deixou de conter o ramo: {$ramo}");
        }

        // Um `return` para cada um dos quatro ramos acima, mais o do UserID
        // ausente, escrita proibida e entrega recusada: sete terminadores.
        //
        // A contagem é ESCOPADA a executarComandoChatgpt(). Antes de o corpo
        // sair do script ela varria o arquivo inteiro, o que só funcionava
        // porque o script não tinha mais nada; no irmão há outras funções, e
        // as bare `return;` delas entrariam na conta.
        $corpo = Support\EstruturaDeScript::corpoDaFuncao(
            __DIR__ . '/../../comandos/funcoes_chatgpt.php',
            'executarComandoChatgpt'
        );
        $this->assertSame(
            7,
            substr_count($corpo, "\n        return;\n") + substr_count($corpo, "\n            return;\n"),
            'Cada ramo de saída precisa devolver o controle ao roteador.'
        );
    }

    /**
     * Números das linhas com `exit`/`die` no arquivo. A leitura é por tokens:
     * `exit` dentro de string ou comentário não conta, e nenhuma linha do
     * script é executada.
     *
     * @return array<int, int>
     */
    private function linhasComTerminadorDestrutivo(string $caminho): array
    {
        $codigo = file_get_contents($caminho);
        $this->assertIsString($codigo, "{$caminho} precisa ser legível.");

        $linhas = [];
        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && $token[0] === T_EXIT) {
                $linhas[] = $token[2];
            }
        }

        return $linhas;
    }
}
