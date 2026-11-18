<?php

declare(strict_types=1);

/**
 * Memória conversacional dos três comandos de IA (chatgpt, ver_audio e
 * ver_imagem): a chave por sujeito e tópico, o histórico rolante no Redis com
 * TTL de 24 h e a gravação compare-and-set de um turno.
 *
 * Saiu de funcoes_ia_comuns.php para aquele arquivo caber no teto de linhas do
 * `composer check:complexity`. Quem precisa da memória continua carregando
 * funcoes_ia_comuns.php, que dá require_once neste irmão: nenhum comando
 * mudou de include, e o segundo include continua sendo no-op — a razão de tudo
 * isso ser require_once está no cabeçalho de funcoes_ia_comuns.php.
 *
 * Só declara funções — nenhum efeito no include.
 */

// lerTextoDoRedis() e salvarTextoComTtl() continuam no arquivo comum; o
// require_once cruzado é no-op quando ele já está no meio do include.
require_once __DIR__ . '/funcoes_ia_comuns.php';

/**
 * Extrai o tópico de uma mensagem de grupo. Mensagens fora de grupos — e
 * grupos que não usam tópicos — ficam no tópico lógico zero.
 *
 * @param array<array-key, mixed> $mensagem
 */
function idTopicoDaMensagem(array $mensagem): int
{
    $id = $mensagem['message_thread_id'] ?? null;

    return is_int($id) && $id > 0 ? $id : 0;
}

/**
 * Monta a chave versionada da memória de uma conversa.
 *
 * Privados ficam isolados por usuário. Grupos ficam isolados por grupo e por
 * tópico e participante do Telegram, sem importar histórico compartilhado antigo.
 * O prefixo explícito também evita colisões caso um ID de usuário e um ID de
 * chat tenham o mesmo valor em algum ambiente de teste ou integração.
 */
function chaveMemoriaConversa(?string $chatType, ?int $idUsuario, ?int $idChat, int $idTopico = 0): string
{
    $ehGrupo = $chatType === 'group' || $chatType === 'supergroup';
    $sujeito = $ehGrupo && $idChat !== null ? 'grupo:' . $idChat : 'privado:' . ($idUsuario ?? 'desconhecido');
    $topico = $ehGrupo && $idTopico > 0 ? $idTopico : 0;

    if ($ehGrupo) {
        return 'memoria:v3:' . $sujeito . ':topico:' . $topico . ':usuario:' . ($idUsuario ?? 'desconhecido');
    }

    return 'memoria:v2:' . $sujeito . ':topico:' . $topico;
}

/**
 * TTL do histórico. Funções, em vez de constantes globais, evitam
 * redeclarações quando os comandos são extraídos lado a lado nos testes.
 */
function ttlMemoriaConversa(): int
{
    return 86400;
}

function maxTurnosMemoriaConversa(): int
{
    return 8;
}

/**
 * Valida o formato interno do histórico. Só mensagens textuais de usuário e
 * assistente entram na memória; imagens não são persistidas, pois a URL do
 * Telegram é temporária e a legenda é suficiente para o contexto futuro.
 *
 * @return array<int, array{role: 'user'|'assistant', content: string}>
 */
function normalizarHistoricoMemoria(mixed $historico): array
{
    if (!is_array($historico)) {
        return [];
    }

    $normalizado = [];
    foreach ($historico as $mensagem) {
        if (!is_array($mensagem)) {
            continue;
        }

        $role = $mensagem['role'] ?? null;
        $content = $mensagem['content'] ?? null;
        if (($role !== 'user' && $role !== 'assistant') || !is_string($content) || $content === '') {
            continue;
        }

        $normalizado[] = ['role' => $role, 'content' => $content];
    }

    // O limite é em turnos completos. Como cada turno tem user + assistant,
    // mantemos somente as últimas 16 mensagens textuais.
    return array_slice($normalizado, -2 * maxTurnosMemoriaConversa());
}

/**
 * Lê a memória nova e, durante a migração, cai uma única vez nas duas chaves
 * legadas de última pergunta/última resposta. Assim a atualização não joga
 * fora uma conversa ativa, mas qualquer gravação bem-sucedida passa a usar a
 * estrutura versionada.
 *
 * @return array<int, array{role: 'user'|'assistant', content: string}>
 */
function lerHistoricoMemoria(
    Redis $redis,
    string $chaveMemoria,
    ?string $chaveContextoLegada = null,
    ?string $chaveAssistenteLegada = null
): array {
    $bruto = lerTextoDoRedis($redis, $chaveMemoria);
    if ($bruto !== '') {
        $decodificado = json_decode($bruto, true);
        $historico = normalizarHistoricoMemoria($decodificado);
        if ($historico !== []) {
            return $historico;
        }
    }

    if (str_starts_with($chaveMemoria, 'memoria:v3:')
        || $chaveContextoLegada === null || $chaveAssistenteLegada === null) {
        return [];
    }

    $mensagem = lerTextoDoRedis($redis, $chaveContextoLegada);
    $resposta = lerTextoDoRedis($redis, $chaveAssistenteLegada);
    $legado = [];
    if ($mensagem !== '') {
        $legado[] = ['role' => 'user', 'content' => $mensagem];
    }
    if ($resposta !== '') {
        $legado[] = ['role' => 'assistant', 'content' => $resposta];
    }

    return $legado;
}

/**
 * Acrescenta um turno completo e grava o histórico com TTL renovado.
 *
 * @param array<int, array{role: 'user'|'assistant', content: string}> $historico
 */
function salvarTurnoMemoria(
    Redis $redis,
    string $chaveMemoria,
    array $historico,
    string $mensagem,
    string $resposta
): bool {
    try {
        // Compare-and-set otimista. Cada tentativa relê o valor mais novo antes
        // de acrescentar o turno; o Lua só grava se ninguém o mudou no intervalo.
        // Assim dois workers do mesmo tópico não sobrescrevem o turno um do outro.
        $script = <<<'LUA'
local atual = redis.call('GET', KEYS[1])
if ARGV[1] == '1' then
    if atual ~= ARGV[2] then
        return 0
    end
else
    if atual ~= false then
        return 0
    end
end
redis.call('SET', KEYS[1], ARGV[3], 'EX', tonumber(ARGV[4]))
return 1
LUA;

        for ($tentativa = 0; $tentativa < 5; $tentativa++) {
            $brutoAtual = $redis->get($chaveMemoria);
            $chaveExiste = is_string($brutoAtual);
            if ($chaveExiste) {
                $decodificado = json_decode($brutoAtual, true);
                $base = normalizarHistoricoMemoria($decodificado);
            } else {
                // Na primeira gravação preserva o fallback legado que o chamador
                // acabou de ler; num conflito subsequente a chave nova é a fonte.
                $base = $tentativa === 0 ? normalizarHistoricoMemoria($historico) : [];
            }

            $base[] = ['role' => 'user', 'content' => $mensagem];
            $base[] = ['role' => 'assistant', 'content' => $resposta];
            $base = array_slice($base, -2 * maxTurnosMemoriaConversa());

            $json = json_encode(
                $base,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if (!is_string($json)) {
                return false;
            }

            $gravou = $redis->eval(
                $script,
                [
                    $chaveMemoria,
                    $chaveExiste ? '1' : '0',
                    $chaveExiste ? $brutoAtual : '',
                    $json,
                    ttlMemoriaConversa(),
                ],
                1
            );
            if (is_numeric($gravou) && (int) $gravou === 1) {
                return true;
            }
        }
    } catch (Throwable $erro) {
        if (function_exists('escreverLog')) {
            escreverLog(
                'Falha ao atualizar memória de conversa: ' . $erro->getMessage(),
                'erros_redis',
                true,
                'error'
            );
        }

        return false;
    }

    if (function_exists('escreverLog')) {
        escreverLog(
            'Memória de conversa sofreu contenção acima do limite de tentativas.',
            'erros_redis',
            true,
            'warning'
        );
    }

    return false;
}

/** @return array{string, string} */
function chavesLegadasDaConversa(Redis $redis, string $chave_memoria, int $id_telegram): array
{
    $chave_contexto = str_starts_with($chave_memoria, 'memoria:v3:') ? 'contexto:' . $chave_memoria : 'contexto' . $id_telegram;
    $mensagem_contexto = lerTextoDoRedis($redis, $chave_contexto);

    $chave_assistente = str_starts_with($chave_memoria, 'memoria:v3:') ? 'assistente:' . $chave_memoria : 'assistente' . $id_telegram;
    $mensagem_contexto_assistente = lerTextoDoRedis($redis, $chave_assistente);

    return [$chave_contexto, $chave_assistente];
}
