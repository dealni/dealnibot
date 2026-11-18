<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCli;

final class AmbienteSeguroTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    /**
     * Sobrescritas do monitorar_saude.sh que o parser aceita além do schema
     * (o bloco de dados de scripts/operacao/carregar_ambiente_seguro.sh).
     *
     * @var list<string>
     */
    private const SOBRESCRITAS_DO_MONITOR = ['DEALNI_MONITOR_STATE_DIR', 'DEALNI_MONITOR_ALERT_INTERVAL_SECONDS'];
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    private const RAIZ = __DIR__ . '/../..';

    public function testParserPreservaValoresSystemdSemExecutarShell(): void
    {
        $diretorio = sys_get_temp_dir() . '/chatbots-ambiente-' . bin2hex(random_bytes(6));
        $sentinela = $diretorio . '/nao-deve-existir';
        $arquivo = $diretorio . '/environment';
        try {
            self::assertTrue(mkdir($diretorio, 0700, true));
            $conteudo = "# comentário\n"
                . "PHP85_BIN='/var/lib/bot/banco com espaço.db'\n"
                . "REDIS_HOST=/var/backups/Meu\\ Drive\n"
                . "REDIS_PASSWORD='\$(touch {$sentinela})'\n";
            self::assertSame(strlen($conteudo), file_put_contents($arquivo, $conteudo));

            $resultado = $this->executarParser($arquivo);

            self::assertSame(0, $resultado['codigo'], $resultado['stderr']);
            self::assertSame(
                "/var/lib/bot/banco com espaço.db\n/var/backups/Meu Drive\n\$(touch {$sentinela})\n",
                $resultado['stdout'],
            );
            self::assertFileDoesNotExist($sentinela);
        } finally {
            self::removerTemporario($diretorio);
        }
    }

    public function testParserRejeitaLinhaQueNaoEEnvironmentAssignment(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'chatbots-ambiente-');
        try {
            if ($arquivo === false) {
                self::fail('Não foi possível criar o arquivo temporário.');
            }
            $conteudo = "PHP85_BIN=/tmp/bot.db\nnao shell\n";
            self::assertSame(strlen($conteudo), file_put_contents($arquivo, $conteudo));

            $resultado = $this->executarParser($arquivo);

            self::assertSame(1, $resultado['codigo']);
            self::assertStringContainsString('linha 2', $resultado['stderr']);
        } finally {
            if (is_string($arquivo)) {
                self::removerTemporario($arquivo);
            }
        }
    }

    /**
     * Variável fora do contrato não entra no ambiente — BASH_ENV continua sem
     * virar injeção —, mas também não derruba o job: uma chave extra no
     * arquivo era falha fatal de TODOS os jobs do cron; agora é aviso.
     */
    public function testParserIgnoraVariavelForaDoContratoComAvisoSemDerrubarOJob(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'chatbots-ambiente-');
        try {
            if ($arquivo === false) {
                self::fail('Não foi possível criar o arquivo temporário.');
            }
            $conteudo = "BASH_ENV=/tmp/injetar\nPHP85_BIN=/tmp/bot.db\n";
            self::assertSame(strlen($conteudo), file_put_contents($arquivo, $conteudo));

            $resultado = $this->executarParser($arquivo, 'printf \'%s\n\' "$PHP85_BIN" "${BASH_ENV-nao-exportada}"');

            self::assertSame(0, $resultado['codigo'], $resultado['stderr']);
            self::assertStringContainsString('variável não prevista (BASH_ENV)', $resultado['stderr']);
            self::assertSame("/tmp/bot.db\nnao-exportada\n", $resultado['stdout']);
        } finally {
            if (is_string($arquivo)) {
                self::removerTemporario($arquivo);
            }
        }
    }

    /**
     * A allowlist do parser é DERIVADA de config/env.schema.php (mais as duas
     * sobrescritas do monitorar_saude.sh, que só o cron lê). Este teste é o
     * que impede a derivação em shell de divergir do PHP: toda chave do schema
     * precisa ser aceita e exportada, e nada além delas.
     */
    public function testAllowlistDoParserEhExatamenteOSchemaMaisSobrescritasDoMonitor(): void
    {
        // O schema é avaliado pelo PHP num subprocesso (e não incluído aqui):
        // a suíte não inclui arquivos de config/ no próprio processo, e o que
        // importa é a lista de chaves que o PHP de verdade enxerga.
        $script = (string) tempnam(sys_get_temp_dir(), 'chatbots-schema-');
        try {
            file_put_contents($script, "<?php\necho json_encode(array_map('strval', array_keys(require \$argv[1])), JSON_THROW_ON_ERROR);\n");
            $chaves = ExecutorCli::executar($script, [self::RAIZ . '/config/env.schema.php']);
        } finally {
            unlink($script);
        }
        self::assertSame(0, $chaves['codigo'], $chaves['stderr']);
        $chavesDoSchema = json_decode($chaves['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($chavesDoSchema);
        $esperadas = array_merge($chavesDoSchema, self::SOBRESCRITAS_DO_MONITOR);
        sort($esperadas);

        $listagem = $this->executarTrechoComParser(
            'variaveis_permitidas_do_ambiente "$2"',
            self::RAIZ . '/config/env.schema.php',
        );
        self::assertSame(0, $listagem['codigo'], $listagem['stderr']);
        $permitidas = preg_split('/\n/', trim($listagem['stdout']), -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($permitidas);
        sort($permitidas);
        self::assertSame($esperadas, $permitidas, 'A extração em shell precisa enxergar exatamente as chaves do schema.');

        // E cada uma delas passa pelo parser de ponta a ponta.
        $diretorio = sys_get_temp_dir() . '/chatbots-ambiente-schema-' . bin2hex(random_bytes(6));
        $arquivo = $diretorio . '/environment';
        try {
            self::assertTrue(mkdir($diretorio, 0700, true));
            $conteudo = '';
            $impressao = '';
            foreach ($esperadas as $indice => $nome) {
                $conteudo .= "{$nome}=valor-{$indice}\n";
                $impressao .= 'printf \'%s\n\' "$' . $nome . '"; ';
            }
            self::assertSame(strlen($conteudo), file_put_contents($arquivo, $conteudo));

            $resultado = $this->executarParser($arquivo, $impressao);

            self::assertSame(0, $resultado['codigo'], $resultado['stderr']);
            self::assertSame('', $resultado['stderr'], 'Nenhuma chave do schema pode gerar aviso.');
            $valoresEsperados = '';
            foreach (array_keys($esperadas) as $indice) {
                $valoresEsperados .= "valor-{$indice}\n";
            }
            self::assertSame($valoresEsperados, $resultado['stdout']);
        } finally {
            self::removerTemporario($diretorio);
        }
    }

    public function testParserDecodificaBarraInvertidaAspasEDadosLiteraisEmValorDuplo(): void
    {
        $diretorio = sys_get_temp_dir() . '/chatbots-ambiente-escape-' . bin2hex(random_bytes(6));
        $arquivo = $diretorio . '/environment';

        try {
            self::assertTrue(mkdir($diretorio, 0700, true));
            $conteudo = <<<'ENV'
PHP85_BIN="/tmp/back\\slash\"quote\$dollar\`tick"
ENV;
            $conteudo .= "\n";
            self::assertSame(strlen($conteudo), file_put_contents($arquivo, $conteudo));

            $resultado = $this->executarParser($arquivo);

            self::assertSame(0, $resultado['codigo'], $resultado['stderr']);
            self::assertSame("/tmp/back\\slash\"quote\$dollar`tick\n\n\n", $resultado['stdout']);
        } finally {
            self::removerTemporario($diretorio);
        }
    }

    private static function removerTemporario(string $caminho): void
    {
        if (is_file($caminho) || is_link($caminho)) {
            @unlink($caminho);

            return;
        }
        if (!is_dir($caminho)) {
            return;
        }

        $itens = scandir($caminho);
        if ($itens === false) {
            return;
        }
        foreach ($itens as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            self::removerTemporario($caminho . '/' . $item);
        }
        @rmdir($caminho);
    }

    /** @return array{codigo: int, stdout: string, stderr: string} */
    private function executarParser(string $arquivo, ?string $impressao = null): array
    {
        $impressao ??= 'printf \'%s\n\' "$PHP85_BIN" "${REDIS_HOST-}" "${REDIS_PASSWORD-}"';

        return $this->executarTrechoComParser('carregar_ambiente_seguro "$2"' . "\n" . $impressao, $arquivo);
    }

    /**
     * Roda um trecho de shell com o parser já carregado; `$2` é o arquivo.
     *
     * @return array{codigo: int, stdout: string, stderr: string}
     */
    private function executarTrechoComParser(string $trecho, string $arquivo): array
    {
        $script = "set -euo pipefail\nsource \"\$1\"\n" . $trecho . "\n";
        $processo = proc_open(
            [
                'bash',
                '-c',
                $script,
                'ambiente-seguro-test',
                self::RAIZ . '/scripts/operacao/carregar_ambiente_seguro.sh',
                $arquivo,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::RAIZ,
        );
        if (!is_resource($processo)) {
            self::fail('Não foi possível iniciar o parser seguro.');
        }

        $stdout = '';
        $stderr = '';
        $codigo = null;
        try {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            self::assertIsString($stdout);
            self::assertIsString($stderr);
            $codigo = proc_close($processo);
            $processo = null;
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($processo)) {
                proc_close($processo);
            }
        }

        return ['codigo' => $codigo, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
