<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Trava os TERMINADORES dos comandos de MÍDIA (comandos/ver_audio.php e
 * comandos/ver_imagem.php), que bot.php executa dentro do laço de polling
 * através do roteador.
 *
 * Motivo: getUpdates() confirma o lote INTEIRO no Telegram antes da primeira
 * volta do laço (offset = último update_id + 1, ver Telegram::getUpdates), e
 * bot.php inclui o roteador — que inclui o comando — uma vez por update. Um
 * `exit` dentro do comando encerra o PROCESSO no meio do lote: as mensagens
 * restantes já estão confirmadas, nunca são reentregues e somem sem resposta e
 * sem log. Quem manda um áudio grande demais (ou estoura a cota) derruba, sem
 * saber, o atendimento de todo mundo que estava no mesmo lote.
 *
 * `return` no topo de um arquivo incluído devolve o controle a quem incluiu: o
 * roteador segue para o seu próprio `return` e o laço de bot.php trata o
 * próximo update. No bot_web.php nada muda, porque o require do roteador é a
 * última linha do arquivo.
 *
 * comandos/chatgpt.php já foi corrigido e é travado por ChatgptTerminadoresTest;
 * os dois comandos de mídia ficavam de fora — este teste fecha a lacuna.
 *
 * Nenhuma linha dos scripts é executada: a leitura é por tokens (`exit` dentro
 * de string ou comentário não conta) e por comparação de texto da fonte.
 */
final class ComandosMidiaTerminadoresTest extends TestCase
{
    private const VER_AUDIO = __DIR__ . '/../../comandos/ver_audio.php';

    private const VER_IMAGEM = __DIR__ . '/../../comandos/ver_imagem.php';

    /**
     * @return array<string, array{string}>
     */
    public static function comandosDeMidiaProvider(): array
    {
        return [
            'comandos/ver_audio.php' => [self::VER_AUDIO],
            'comandos/ver_imagem.php' => [self::VER_IMAGEM],
        ];
    }

    #[DataProvider('comandosDeMidiaProvider')]
    public function testComandoDeMidiaNaoEncerraOProcessoDeQuemInclui(string $caminho): void
    {
        $ocorrencias = $this->linhasComTerminadorDestrutivo($caminho);

        $this->assertSame(
            [],
            $ocorrencias,
            'exit/die encerram o processo e descartam o resto do lote de updates já confirmado. Linhas: '
                . implode(', ', $ocorrencias)
        );
    }

    /**
     * Contraprova do teste acima: a ausência de `exit` não pode ter virado
     * ausência de terminador. Sem o `return`, o fluxo continuaria depois de já
     * ter avisado o usuário — pedindo o arquivo ao Telegram com a cota
     * estourada, mandando um áudio que não baixou para a chamada PAGA do
     * Whisper e enviando a resposta de erro duas vezes.
     */
    public function testCadaRamoDeErroDoAudioDevolveOControleAoRoteador(): void
    {
        $fonte = $this->fonte(self::VER_AUDIO);
        $fonteParaRamos = $fonte;

        $ramos = [
            // sem áudio na mensagem
            "if (!\$temAudio) {\n"
                . "    avisarUsuarioAudio(\$telegram, \"Por favor, envie um áudio para que eu possa transcrevê-lo! 🎤\");\n"
                . "    return false;\n"
                . '}',
            // áudio longo demais (> 3 minutos)
            "if (\$duracao !== null && \$duracao > limiteDeSegundosDoAudio()) {\n"
                . "    avisarUsuarioAudio(\$telegram, \"Esse áudio é longo demais. Envie áudios de até 3 minutos. 🎤\");\n"
                . "    return false;\n"
                . '}',
            // cota diária estourada (o aviso é que fica dentro do if de privado)
            "if (\$info_limite['atingiu_limite']) {\n",
            // áudio sem file_id utilizável
            "if (\$audio_file_id === null) {\n"
                . "    avisarUsuarioAudio(\$telegram, \"Erro ao obter informações do áudio. Tente novamente! ❌\");\n"
                . "    return;\n"
                . '}',
            // getFile sem file_path (arquivo grande demais, expirado, inexistente)
            "if (\$file_path === null) {\n"
                . "    avisarUsuarioAudio(\$telegram, \"Erro ao obter informações do áudio. Tente novamente! ❌\");\n"
                . "    return;\n"
                . '}',
            // download do áudio falhou
            "if (\$audio_temporario['caminho'] === null) {\n"
                . "    avisarUsuarioAudio(\$telegram, \$audio_temporario['erro']);\n"
                . "    return;\n"
                . '}',
            // transcrição falhou
            "if (\$resposta_whisper['tipo'] !== 'transcricao') {\n"
                . "    avisarUsuarioAudio(\$telegram, \$resposta_whisper['texto']);\n"
                . "    return;\n"
                . '}',
            // corpo da requisição impossível de montar
            "if (\$post_fields === false) {\n"
                . "    avisarUsuarioAudio(\$telegram, \"Erro ao gerar resposta. Tente novamente! ❌\");\n"
                . "    return;\n"
                . '}',
            // erro de transporte, erro da API ou conteúdo ausente
            "if (\$resposta_api['tipo'] !== 'resposta') {\n"
                . "    avisarUsuarioAudio(\$telegram, \$resposta_api['texto']);\n"
                . "    return;\n"
                . '}',
        ];
        foreach ($ramos as $indice => $ramo) {
            $fonteDoRamo = $indice === 0 ? $fonte : $fonteParaRamos;
            $this->assertStringContainsString($ramo, $fonteDoRamo, "O comando de áudio deixou de conter o ramo: {$ramo}");
        }

        $this->assertStringContainsString("if (!admitirMidiaDoComando(", $fonteParaRamos);
        // Validação compartilhada encerra dois erros em uma guarda; os demais continuam independentes.
        // no topo do arquivo (as funções do comando devolvem valor com
        // `return <expr>`, nunca com `return;`).
        $this->assertStringContainsString("if (!validarAudioDoComando(\$telegram, \$mensagem_recebida, \$audio_recebido['tem_audio'])) {\n    return;\n}", $fonte);
        $this->assertSame(12, $this->terminadoresDeTopo($fonte));
    }

    public function testCadaRamoDeErroDaImagemDevolveOControleAoRoteador(): void
    {
        $fonte = $this->fonte(self::VER_IMAGEM);
        $fonteParaRamos = $fonte;

        $ramos = [
            // sem foto na mensagem
            "if (!\$imagem_recebida['tem_imagem']) {\n"
                . "    avisarUsuarioImagem(\$telegram, \"Por favor, envie uma imagem para que eu possa analisá-la! 📸\");\n"
                . "    return;\n"
                . '}',
            // cota diária estourada (o aviso é que fica dentro do if de privado)
            "if (\$info_limite['atingiu_limite']) {\n",
            // foto sem file_id utilizável
            "if (\$photo_file_id === null) {\n"
                . "    avisarUsuarioImagem(\$telegram, \"Erro ao obter informações da imagem. Tente novamente! ❌\");\n"
                . "    return;\n"
                . '}',
            // getFile sem file_path (arquivo grande demais, expirado, inexistente)
            "if (\$file_path === null) {\n"
                . "    avisarUsuarioImagem(\$telegram, \"Erro ao obter informações da imagem. Tente novamente! ❌\");\n"
                . "    return;\n"
                . '}',
            // corpo da requisição impossível de montar
            "if (\$post_fields === false) {\n"
                . "    avisarUsuarioImagem(\$telegram, \"Erro ao analisar a imagem. Tente novamente! ❌\");\n"
                . "    return;\n"
                . '}',
            // download da imagem falhou, veio grande demais ou em formato
            // que a visão da OpenAI não aceita
            "if (\$imagem_baixada['data_uri'] === null) {\n"
                . "    avisarUsuarioImagem(\$telegram, \$imagem_baixada['erro']);\n"
                . "    return;\n"
                . '}',
            // erro de transporte, erro da API ou conteúdo ausente
            "if (\$resposta_api['tipo'] !== 'resposta') {\n"
                . "    avisarUsuarioImagem(\$telegram, \$resposta_api['texto']);\n"
                . "    return;\n"
                . '}',
        ];
        foreach ($ramos as $indice => $ramo) {
            $fonteDoRamo = $indice === 0 ? $fonte : $fonteParaRamos;
            $this->assertStringContainsString($ramo, $fonteDoRamo, "O comando de imagem deixou de conter o ramo: {$ramo}");
        }

        $this->assertStringContainsString("if (!admitirMidiaDoComando(", $fonteParaRamos);
        // Dez ramos de erro (incluindo escrita proibida e entrega recusada) + o UserID ausente.
        // O sétimo ramo nasceu com o download da imagem: a URL do Telegram
        // carrega o TOKEN_BOT e deixou de ir para a OpenAI.
        $this->assertSame(11, $this->terminadoresDeTopo($fonte));
    }

    /**
     * O ramo da cota é o mais caro de perder: sem o terminador, quem já estourou
     * as 100 requisições do dia teria a chamada paga executada mesmo assim.
     *
     * O `return` fica FORA do `if` do aviso de propósito — em grupo a cota é
     * consumida igual e o fluxo tem de parar do mesmo jeito, só sem avisar (o
     * bot repetindo "você atingiu o limite" a cada mídia viraria ruído no
     * grupo). É justamente por isso que o terminador não pode morar dentro do
     * ramo que envia a mensagem.
     */
    #[DataProvider('comandosDeMidiaProvider')]
    public function testRamoDaCotaDiariaTerminaComOuSemOAviso(string $caminho): void
    {
        $fonte = $this->fonte($caminho);
        $inicio = strpos($fonte, "if (\$info_limite['atingiu_limite']) {");
        $this->assertIsInt($inicio);
        $ramo = substr($fonte, $inicio, 700);

        $this->assertStringContainsString("if (\$chatType === 'private') {", $ramo);
        $this->assertStringContainsString('requisições diárias. Tente novamente amanhã!', $ramo);
        $this->assertStringContainsString("\n    return;\n}", $ramo);
        if (str_ends_with($caminho, '/ver_audio.php')) {
            $this->assertStringContainsString('removerAudioTemporario($temp_audio_path);', $ramo);
        }
    }

    /**
     * Números das linhas com `exit`/`die` no arquivo. A leitura é por tokens:
     * `exit` dentro de string ou comentário não conta, e nenhuma linha do
     * script é executada.
     *
     * @param string $caminho
     * @return array<int, int>
     */
    private function linhasComTerminadorDestrutivo(string $caminho): array
    {
        $linhas = [];
        foreach (token_get_all($this->fonte($caminho)) as $token) {
            if (is_array($token) && $token[0] === T_EXIT) {
                $linhas[] = $token[2];
            }
        }

        return $linhas;
    }

    /**
     * Quantidade de `return;` sem valor no topo do script (indentados em um ou
     * dois níveis). As funções do comando devolvem valor, então nenhuma delas
     * entra nesta conta.
     *
     * @param string $fonte
     * @return int
     */
    private function terminadoresDeTopo(string $fonte): int
    {
        return substr_count($fonte, "\n    return;\n") + substr_count($fonte, "\n        return;\n");
    }

    /**
     * Fluxo do comando, pronto para comparação de texto.
     *
     * O corpo saiu do topo do script para uma função do funcoes_*.php irmão em
     * 2026-08-24 (script de topo executa no include e nenhum teste podia
     * incluí-lo, então tudo que morasse lá era código sem cobertura possível).
     * A única diferença de FORMA é um nível de indentação a mais, então o corpo
     * volta desindentado: as agulhas descrevem a estrutura do fluxo, não a
     * profundidade em que ele está aninhado.
     */
    private function fonte(string $caminho): string
    {
        $irmao = dirname($caminho) . '/funcoes_' . basename($caminho);
        $funcao = basename($caminho) === 'ver_audio.php'
            ? 'executarComandoVerAudio'
            : 'executarComandoVerImagem';

        $corpo = Support\EstruturaDeScript::corpoDaFuncao($irmao, $funcao);
        $modalidade = basename($caminho) === 'ver_audio.php' ? 'Audio' : 'Imagem';
        $corpo .= "\n" . Support\EstruturaDeScript::corpoDaFuncao($irmao, 'responderMidia' . $modalidade);
        if ($modalidade === 'Audio') {
            $corpo .= "\n" . Support\EstruturaDeScript::corpoDaFuncao(dirname($caminho) . '/funcoes_arquivo_audio.php', 'validarAudioDoComando');
        }

        return implode("\n", array_map(
            static fn (string $linha): string => str_starts_with($linha, '    ')
                ? substr($linha, 4)
                : $linha,
            explode("\n", $corpo)
        ));
    }
}
