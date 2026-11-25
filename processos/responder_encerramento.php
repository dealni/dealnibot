<?php

declare(strict_types=1);

namespace DealniBot\Encerramento;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

const MENSAGEM = 'O DealniBot está temporariamente pausado. Durante a pausa, os comandos e as funções do bot estão indisponíveis.';
const RETENCAO_TELEGRAM = 86400;
const LONG_POLLING = 30;

/** Mensagens fixas e seguras para o journal. */
final class Falha extends \RuntimeException
{
}

final class Estado
{
    public readonly int $ativadoEm;
    public private(set) int $offset;
    private readonly string $arquivo;
    /** @var resource */
    private $lock;

    public function __construct(private readonly string $diretorio, int $agora)
    {
        $permissoes = @fileperms($diretorio);
        if (!is_int($permissoes) || ($permissoes & 0077) !== 0) {
            throw new Falha('StateDirectory precisa ser privado.');
        }
        $caminhoLock = $diretorio . '/respondedor.lock';
        if (is_link($caminhoLock) || (file_exists($caminhoLock) && !is_file($caminhoLock))) {
            throw new Falha('Trava do respondedor inválida.');
        }
        $lock = @fopen($caminhoLock, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new Falha('Não foi possível obter a trava exclusiva do respondedor.');
        }
        $this->lock = $lock;
        $this->arquivo = $diretorio . '/estado.json';
        if (is_link($this->arquivo)) {
            throw new Falha('Estado do respondedor inválido.');
        }
        if (!file_exists($this->arquivo)) {
            $this->ativadoEm = $agora;
            $this->salvar(0);
            return;
        }
        $estado = $this->ler();
        $this->ativadoEm = $estado['ativado_em'];
        $this->offset = $estado['offset'];
        $ultimaGravacao = @filemtime($this->arquivo);
        if ($this->offset > 0 && is_int($ultimaGravacao) && $ultimaGravacao <= $agora - namespace\RETENCAO_TELEGRAM) {
            $this->salvar(0);
        }
    }

    public function __destruct()
    {
        fclose($this->lock);
    }

    /** @return array{ativado_em: int, offset: int} */
    private function ler(): array
    {
        if (!is_file($this->arquivo)) {
            throw new Falha('Estado do respondedor inválido.');
        }
        $bruto = @file_get_contents($this->arquivo, length: 256);
        $estado = is_string($bruto) ? json_decode($bruto, true) : null;
        if (!is_array($estado) || count($estado) !== 2
            || !is_int($estado['ativado_em'] ?? null) || $estado['ativado_em'] < 1
            || !is_int($estado['offset'] ?? null) || $estado['offset'] < 0) {
            throw new Falha('Estado do respondedor inválido.');
        }
        return ['ativado_em' => $estado['ativado_em'], 'offset' => $estado['offset']];
    }

    public function salvar(int $offset): void
    {
        $temporario = @tempnam($this->diretorio, '.estado-');
        if ($temporario === false) {
            throw new Falha('Não foi possível preparar o estado do respondedor.');
        }
        try {
            if (dirname($temporario) !== $this->diretorio) {
                throw new Falha('O temporário do estado precisa ficar no StateDirectory.');
            }
            $dados = json_encode(['ativado_em' => $this->ativadoEm, 'offset' => $offset], JSON_THROW_ON_ERROR) . "\n";
            $this->gravarArquivo($temporario, $dados);
            if (!@rename($temporario, $this->arquivo)) {
                throw new Falha('Não foi possível publicar o estado do respondedor.');
            }
            $this->sincronizarDiretorio();
            $this->offset = $offset;
        } finally {
            if (is_file($temporario)) {
                @unlink($temporario);
            }
        }
    }

    private function gravarArquivo(string $arquivo, string $dados): void
    {
        $saida = @fopen($arquivo, 'wb');
        if ($saida === false) {
            throw new Falha('Não foi possível abrir o estado do respondedor.');
        }
        try {
            if (@fwrite($saida, $dados) !== strlen($dados) || !@fflush($saida) || !@fsync($saida)) {
                throw new Falha('Não foi possível gravar o estado do respondedor.');
            }
        } finally {
            fclose($saida);
        }
    }

    private function sincronizarDiretorio(): void
    {
        $diretorio = @fopen($this->diretorio, 'r');
        if ($diretorio === false) {
            throw new Falha('Não foi possível abrir o diretório do estado.');
        }
        try {
            if (!@fsync($diretorio)) {
                throw new Falha('Não foi possível sincronizar o diretório do estado.');
            }
        } finally {
            fclose($diretorio);
        }
    }
}

/**
 * @phpstan-type Metodo 'getMe'|'getUpdates'|'sendMessage'
 * @phpstan-type Resposta array{http: int, erro: int, corpo: string|false}
 */
final class Telegram
{
    /**
     * @param \Closure(Metodo, array<string, mixed>): Resposta $transporte
     * @param \Closure(int): void $esperar
     * @param \Closure(string): void $registrar
     */
    public function __construct(
        private readonly \Closure $transporte,
        private readonly \Closure $esperar,
        private readonly \Closure $registrar,
    ) {
    }

    public function conferirIdentidade(): void
    {
        $bot = $this->chamar('getMe', []);
        $nome = is_array($bot) ? ($bot['username'] ?? null) : null;
        if (!is_string($nome) || strcasecmp($nome, 'DealniBot') !== 0) {
            throw new Falha('A credencial precisa pertencer ao @DealniBot.');
        }
    }

    /** @return array<int, array<mixed>> */
    public function buscar(int $offset): array
    {
        $lote = $this->chamar('getUpdates', ['offset' => $offset, 'timeout' => namespace\LONG_POLLING, 'limit' => 20, 'allowed_updates' => ['message']]);
        if (!is_array($lote) || !array_is_list($lote)) {
            throw new Falha('O Telegram devolveu um lote inválido.');
        }
        $porId = [];
        foreach ($lote as $update) {
            $id = is_array($update) ? ($update['update_id'] ?? null) : null;
            if (!is_array($update) || !is_int($id) || $id < 1 || $id === PHP_INT_MAX) {
                throw new Falha('O Telegram devolveu um update inválido.');
            }
            $porId[$id] = $update;
        }
        ksort($porId);
        return $porId;
    }

    public function responder(int $chat): void
    {
        $this->chamar('sendMessage', ['chat_id' => $chat, 'text' => namespace\MENSAGEM]);
    }

    /**
     * @param Metodo $metodo
     * @param array<string, mixed> $dados
     */
    private function chamar(string $metodo, array $dados): mixed
    {
        $intervalo = 1;
        while (true) {
            $resposta = ($this->transporte)($metodo, $dados);
            $corpo = is_string($resposta['corpo']) ? json_decode($resposta['corpo'], true) : null;
            $http = $resposta['http'];
            if ($http >= 200 && $http < 300 && is_array($corpo) && ($corpo['ok'] ?? false) === true) {
                return $corpo['result'] ?? null;
            }
            if ($http === 429) {
                ($this->registrar)('Limite temporário do Telegram; aguardando nova tentativa.');
                ($this->esperar)($this->segundosDeRetry($corpo, $intervalo));
            } elseif ($this->podeTentarDeNovo($metodo, $http, $resposta['erro'])) {
                ($this->registrar)('Falha transitória na comunicação com o Telegram; aguardando nova tentativa.');
                ($this->esperar)($intervalo);
            } elseif ($metodo === 'sendMessage' && !in_array($http, [401, 404, 409], true)) {
                ($this->registrar)('Resposta do Telegram recusada ou indeterminada; envio não repetido.');
                return null;
            } else {
                throw new Falha('O Telegram recusou a operação do respondedor.');
            }
            $intervalo = min($intervalo * 2, 60);
        }
    }

    private function segundosDeRetry(mixed $corpo, int $padrao): int
    {
        $parametros = is_array($corpo) ? ($corpo['parameters'] ?? null) : null;
        $segundos = is_array($parametros) ? ($parametros['retry_after'] ?? null) : null;

        return is_int($segundos) && $segundos > 0 ? $segundos : $padrao;
    }

    private function podeTentarDeNovo(string $metodo, int $http, int $erro): bool
    {
        if ($metodo !== 'sendMessage') {
            return $http === 0 || $http >= 500;
        }
        // Sem chave de idempotência, só repete envio sem uma conexão estabelecida.
        return $http === 0 && in_array($erro, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true);
    }
}

/**
 * @phpstan-import-type Metodo from Telegram
 * @phpstan-import-type Resposta from Telegram
 */
final class TransporteHttps
{
    public function __construct(private readonly string $token)
    {
    }

    /** @param array<string, mixed> $dados
     * @param Metodo $metodo
     * @return Resposta
     */
    public function __invoke(string $metodo, array $dados): array
    {
        $curl = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $metodo);
        if ($curl === false || !curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode((object) $dados, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $metodo === 'getUpdates' ? namespace\LONG_POLLING + 10 : 10,
            CURLOPT_NOSIGNAL => true,
        ])) {
            throw new Falha('Não foi possível preparar a conexão HTTPS do respondedor.');
        }
        $corpo = curl_exec($curl);
        return [
            'http' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'erro' => curl_errno($curl),
            'corpo' => is_string($corpo) ? $corpo : false,
        ];
    }
}

/** @param array<mixed> $update */
function chatPrivadoNovo(array $update, int $ativadoEm): ?int
{
    $mensagem = $update['message'] ?? null;
    if (!is_array($mensagem) || !is_int($mensagem['date'] ?? null) || $mensagem['date'] <= $ativadoEm) {
        return null;
    }
    $chat = $mensagem['chat'] ?? null;
    if (!is_array($chat) || ($chat['type'] ?? null) !== 'private') {
        return null;
    }
    $id = $chat['id'] ?? null;
    return is_int($id) && $id > 0 && namespace\temConteudoDePessoa($mensagem) ? $id : null;
}

/** @param array<mixed> $mensagem */
function temConteudoDePessoa(array $mensagem): bool
{
    $autor = $mensagem['from'] ?? null;
    if (!is_array($autor) || ($autor['is_bot'] ?? null) !== false) {
        return false;
    }
    foreach ([
        'text', 'animation', 'audio', 'document', 'paid_media', 'photo', 'sticker',
        'story', 'video', 'video_note', 'voice', 'contact', 'dice', 'game',
        'poll', 'venue', 'location', 'checklist', 'live_photo',
    ] as $campo) {
        if (isset($mensagem[$campo])) {
            return true;
        }
    }

    return false;
}

/** @param array<int, array<mixed>> $lote */
function processarLote(Estado $estado, Telegram $telegram, array $lote): void
{
    if ($lote === [] && $estado->offset > 0) {
        // O polling vazio com offset positivo já confirmou os IDs anteriores.
        $estado->salvar(0);
        return;
    }
    $proximoOffset = $estado->offset;
    foreach ($lote as $id => $update) {
        if ($id < $proximoOffset) {
            continue;
        }
        $proximoOffset = $id + 1;
        $chat = namespace\chatPrivadoNovo($update, $estado->ativadoEm);
        if ($chat !== null) {
            // Um envio pode chegar ao Telegram sem confirmação. Persista antes
            // para que um reinício nunca repita automaticamente essa resposta.
            $estado->salvar($proximoOffset);
            $telegram->responder($chat);
        }
    }
    if ($estado->offset !== $proximoOffset) {
        $estado->salvar($proximoOffset);
    }
}

function diretorioDoSystemd(string $nome): string
{
    $valor = getenv($nome);
    $caminho = is_string($valor) && str_starts_with($valor, '/') ? realpath($valor) : false;
    if ($caminho === false || !is_dir($caminho)) {
        throw new Falha("Diretório obrigatório indisponível: {$nome}.");
    }
    return $caminho;
}

function executar(): never
{
    umask(0077);
    // Mensagens do PHP/cURL podem conter a URL com a credencial.
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    $registrar = static function (string $mensagem): void {
        fwrite(STDERR, $mensagem . "\n");
    };
    try {
        if (!extension_loaded('curl')) {
            throw new Falha('A extensão cURL é obrigatória para o respondedor.');
        }
        $arquivo = namespace\diretorioDoSystemd('CREDENTIALS_DIRECTORY') . '/telegram.token';
        $bruto = is_file($arquivo) ? @file_get_contents($arquivo, length: 256) : false;
        $token = is_string($bruto) ? trim($bruto) : '';
        if (preg_match('/\A[1-9][0-9]{0,19}:[A-Za-z0-9_-]{20,128}\z/', $token) !== 1) {
            throw new Falha('Credencial do Telegram ausente ou inválida.');
        }
        $transporte = new TransporteHttps($token);
        $telegram = new Telegram($transporte(...), static function (int $segundos): void {
            sleep($segundos);
        }, $registrar);
        $telegram->conferirIdentidade();
        $estado = new Estado(namespace\diretorioDoSystemd('STATE_DIRECTORY'), time());
        $primeiro = true;
        while (true) {
            $lote = $telegram->buscar($estado->offset);
            if ($primeiro) {
                $registrar('Respondedor do @DealniBot conectado ao Telegram.');
                $primeiro = false;
            }
            namespace\processarLote($estado, $telegram, $lote);
        }
    } catch (Falha $erro) {
        $registrar($erro->getMessage());
    } catch (\Throwable) {
        $registrar('O respondedor foi encerrado por uma falha inesperada.');
    }
    exit(1);
}

$entrada = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($entrada) && realpath($entrada) === __FILE__) {
    namespace\executar();
}
