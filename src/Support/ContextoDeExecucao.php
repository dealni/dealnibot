<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Identidade do processo para o contrato de observabilidade da frota.
 *
 * Responde às quatro perguntas que todo evento de log precisa carregar — QUEM
 * emitiu (`service`), em QUE ambiente (`env`), com QUAL código (`version`) e
 * dentro de QUAL ciclo (`request_id`) — e responde uma vez só por processo:
 * todos os valores são resolvidos na primeira leitura e ficam em cache. Sem o
 * cache o `request_id` mudaria a cada linha e deixaria de correlacionar coisa
 * alguma, que é a única razão de ele existir.
 *
 * O `request_id` é gerado sob demanda de propósito. Este bot não tem
 * middleware: os entrypoints web são seis arquivos PHP soltos e os workers são
 * scripts de CLI. Amarrar a geração a uma chamada explícita em cada um deles
 * significaria que o entrypoint esquecido produziria log sem correlação — e o
 * esquecido seria justamente o novo. Aqui a primeira linha logada já força a
 * resolução, e `iniciarCicloWeb()` existe apenas para o efeito que a geração
 * preguiçosa não consegue ter sozinha: devolver o header na resposta.
 */
final class ContextoDeExecucao
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    // Vazio de propósito: o nome do serviço vem do diretório raiz e o carimbo
    // de publicação vem de uma variável de ambiente com o prefixo comum
    // (`CHATBOTS_`), não com o prefixo deste bot.
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    /**
     * Padrão do contrato para o `X-Request-Id` que CHEGA de fora.
     *
     * Aceitar o valor do cliente sem esse filtro deixaria ele injetar quebra de
     * linha (uma linha de log forjada), payload gigante (arquivo de log cheio)
     * ou controle de terminal em todo evento do ciclo.
     */
    private const PADRAO_REQUEST_ID = '/^[A-Za-z0-9_-]{8,64}$/';

    /**
     * Apelidos aceitos para `APP_ENV`. Ambiente desconhecido cai em
     * `production`: o valor mais restritivo é o lado seguro, porque é ele que
     * mantém o `stack` fora do log.
     *
     * @var array<string, string>
     */
    private const APELIDOS_DE_AMBIENTE = [
        'dev' => 'development',
        'local' => 'development',
        'development' => 'development',
        'test' => 'test',
        'testing' => 'test',
        'staging' => 'staging',
        'homolog' => 'staging',
        'homologacao' => 'staging',
        'prod' => 'production',
        'production' => 'production',
    ];

    private static ?string $requestId = null;

    private static ?string $versao = null;

    /** @var array<string, int|string> */
    private static array $item = [];

    /** @return array{request_id: string, item: array<string, int|string>} */
    public static function iniciarItem(int $updateId, int $tentativa, bool $replay): array
    {
        $anterior = ['request_id' => self::requestId(), 'item' => self::$item];
        self::$item = ['update_id' => $updateId, 'attempt' => $tentativa];
        if ($replay) {
            self::$item['job_id'] = $anterior['request_id'];
            self::$requestId = self::uuidV4();
        }
        return $anterior;
    }

    /** @param array{request_id: string, item: array<string, int|string>} $anterior */
    public static function restaurarItem(array $anterior): void
    {
        self::$requestId = $anterior['request_id'];
        self::$item = $anterior['item'];
    }

    /** @return array<string, int|string> */
    public static function item(): array
    {
        return self::$item;
    }

    /**
     * Nome do serviço no campo `service` — o nome do repositório.
     *
     * Sai do DIRETÓRIO RAIZ, que é o mesmo mecanismo de
     * `scripts/deploy/registrar_publicacao.sh` (que grava `bot` no
     * `.release.json` a partir de `basename "$project_dir"`). Uma constante
     * com o nome escrito dentro seria um valor por bot no meio do arquivo —
     * exatamente o que este arranjo evita.
     *
     * Na VPS o checkout é `/var/www/<bot>`; na máquina de desenvolvimento é
     * `~/Developer/Chatbots/<bot>`. Nos dois o basename é o nome do bot, e o
     * teste deste repositório crava o literal esperado — é ele que reprova se
     * alguém publicar o código num diretório com outro nome.
     */
    public static function servico(): string
    {
        return basename(self::raiz());
    }

    public static function ambiente(): string
    {
        $bruto = strtolower(trim(RuntimeEnvironment::name()));

        return self::APELIDOS_DE_AMBIENTE[$bruto] ?? 'production';
    }

    public static function producao(): bool
    {
        return self::ambiente() === 'production';
    }

    /**
     * Versão publicada, na mesma string do log, do `/health` e do `/ready`.
     *
     * Não existe deployment ID numa VPS: o artefato publicado é o diretório. O
     * substituto já existente é o carimbo `.release.json` que o `deploy` grava
     * (ver docs/OPERACAO.md, a seção sobre a identidade da publicação), e é dele que a versão
     * sai — tag quando a publicação tem nome, senão o `commit_curto` gravado
     * pelo Git (que pode ter mais de sete dígitos para continuar único).
     *
     * Fora do host publicado não há carimbo, e aí a resposta vem do metadado
     * Git LIDO COMO ARQUIVO (inclusive o gitfile de um linked worktree), nunca
     * por `git` em subprocesso: logar não pode custar um fork por evento nem
     * depender de o binário existir no PATH do www-data.
     *
     * `desconhecida` é resposta legítima e não um erro: é o que aparece quando
     * o código roda fora de uma publicação e fora de um checkout — e é
     * infinitamente melhor que um número inventado.
     */
    public static function versao(): string
    {
        if (self::$versao !== null) {
            return self::$versao;
        }

        return self::$versao = self::doCarimbo() ?? VersaoDoGit::commitCurto(self::raiz()) ?? 'desconhecida';
    }

    /**
     * Identificador do ciclo — uma requisição web ou uma execução de cron/CLI.
     */
    public static function requestId(): string
    {
        if (self::$requestId !== null) {
            return self::$requestId;
        }

        $recebido = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        if (is_string($recebido) && preg_match(self::PADRAO_REQUEST_ID, $recebido) === 1) {
            return self::$requestId = $recebido;
        }

        return self::$requestId = self::uuidV4();
    }

    /**
     * Fixa o `request_id` do ciclo e o devolve no header da resposta.
     *
     * O header sai ANTES de qualquer decisão do entrypoint — inclusive antes do
     * 403 de origem — porque o contrato pede o eco "sempre, inclusive em erro":
     * é ele que permite a alguém com o log na mão explicar uma recusa.
     */
    public static function iniciarCicloWeb(): string
    {
        $requestId = self::requestId();

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('X-Request-Id: ' . $requestId);
        }

        return $requestId;
    }

    /**
     * Devolve o ciclo ao estado "ainda não resolvido".
     *
     * Dois usos: a suíte (o cache é por processo e o PHPUnit reaproveita o
     * dele) e o long polling de `bot.php`, que chama isto por UPDATE — ali o
     * processo é reaproveitado entre updates, e sem o reset um único
     * `request_id` marcaria a sessão inteira em vez de separar um atendimento
     * do seguinte. O próximo evento logado gera o id novo sozinho.
     */
    public static function reset(): void
    {
        self::$requestId = null;
        self::$versao = null;
        self::$item = [];
    }

    private static function raiz(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function doCarimbo(): ?string
    {
        $configurado = getenv('CHATBOTS_RELEASE_FILE');
        $caminho = is_string($configurado) && $configurado !== ''
            ? $configurado
            : self::raiz() . '/.release.json';

        if (!is_file($caminho) || !is_readable($caminho)) {
            return null;
        }

        $conteudo = @file_get_contents($caminho);
        if (!is_string($conteudo)) {
            return null;
        }

        try {
            $carimbo = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($carimbo) ? self::doCarimboDecodificado($carimbo) : null;
    }

    /**
     * Tag quando a publicação tem nome; senão, o commit curto que o Git gravou.
     * É a mesma ordem que o `composer release:check` imprime, para os dois lados
     * baterem sem ninguém adivinhar nada. Carimbos antigos sem `commit_curto`
     * usam os sete primeiros dígitos do SHA como fallback.
     *
     * @param array<array-key, mixed> $carimbo
     */
    private static function doCarimboDecodificado(array $carimbo): ?string
    {
        $tag = $carimbo['tag'] ?? null;
        if (is_string($tag) && trim($tag) !== '') {
            return trim($tag);
        }

        $commitCurto = $carimbo['commit_curto'] ?? null;
        if (is_string($commitCurto)
            && preg_match('/^(?:[0-9a-f]{7,64})$/i', trim($commitCurto)) === 1) {
            return trim($commitCurto);
        }

        $commit = $carimbo['commit'] ?? null;

        return is_string($commit) && trim($commit) !== '' ? substr(trim($commit), 0, 7) : null;
    }

    /**
     * UUID v4 conforme a RFC 4122, sobre bytes criptográficos.
     *
     * `random_bytes()` e não `uniqid()`/`mt_rand()`: o id entra em log de fluxo
     * de dinheiro e precisa ser único entre processos concorrentes do FPM, que
     * é exatamente onde um gerador semeado pelo relógio colide.
     */
    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }
}
