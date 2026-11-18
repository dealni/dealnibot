<?php

declare(strict_types=1);

/**
 * Gerencia os limites de requisições diárias para a API do ChatGPT
 * usando Redis para armazenar os contadores de uso.
 */

/**
 * Dia da janela da cota, sempre no fuso de São Paulo.
 *
 * Antes isto era `date('Ymd')`, que usa o fuso AMBIENTE do processo. Os três
 * comandos de IA só chamam date_default_timezone_set('America/Sao_Paulo')
 * DEPOIS do portão de cota, e nada mais no boot define fuso: no webhook a
 * "cota diária" virava o dia UTC (zerando às 21h de São Paulo) e, no polling,
 * a primeira mensagem do processo caía num balde e as seguintes em outro,
 * porque aí o fuso já tinha sido trocado pelo comando anterior. Calcular a
 * janela aqui torna a cota independente da ordem das chamadas.
 *
 * @return string Data no formato YYYYMMDD
 */
function limite_diario_dia_atual(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Ymd');
}

/**
 * Quem paga a cota deste update.
 *
 * Em chat privado é o USUÁRIO. Em grupo/supergrupo é o PRÓPRIO GRUPO: o bot
 * responde ali por menção, por resposta a ele ou pelo sorteio de 10%, e nenhuma
 * dessas três é escolha de quem mandou a mensagem — cobrar da cota pessoal
 * puniria quem só postou uma foto no grupo. Contar por grupo também é o que
 * limita o gasto onde ele é ilimitado por natureza: um grupo grande gera muito
 * mais update do que uma conversa privada. O id do chat é negativo no Telegram,
 * então a chave `limite_diario:<id>:<dia>` nunca colide com a de um usuário.
 *
 * Qualquer outro chat (ou update sem chat identificável) cai na cota do usuário
 * — nunca em "sem cota": este é o portão que fica entre um update e uma chamada
 * paga da OpenAI, e ele não pode ter buraco.
 *
 * @param string|null $chatType 'private', 'group', 'supergroup'...
 * @param int $idUsuario ID do usuário do Telegram
 * @param int|null $idChat ID do chat de onde veio o update
 * @return int ID que indexa o balde da cota
 */
function limite_diario_sujeito(?string $chatType, int $idUsuario, ?int $idChat): int
{
    if (($chatType === 'group' || $chatType === 'supergroup') && $idChat !== null) {
        return $idChat;
    }

    return $idUsuario;
}

/**
 * Verifica se um usuário atingiu o limite diário de requisições
 * @param Redis $redis Instância do Redis
 * @param int $id_usuario ID do usuário do Telegram
 * @param int $limite_maximo Limite máximo de requisições diárias (padrão: 100)
 * @return array{atingiu_limite: bool, contagem_atual: int, limite_maximo: int} Informações sobre o limite
 */
function verificar_limite_diario(Redis $redis, int $id_usuario, int $limite_maximo = 100): array
{
    // Chave para contagem de requisições diárias por usuário (formato: limite_diario:USER_ID:YYYYMMDD)
    $hoje = limite_diario_dia_atual();
    $chave_limite = "limite_diario:{$id_usuario}:{$hoje}";

    // Leitura pura: a chave é criada (com TTL) apenas pelo incremento em
    // incrementar_contador_requisicoes(). Chave ausente equivale a contagem 0.
    // O padrão antigo exists() → set(0) → expire() não era atômico e podia
    // recriar a chave sem TTL sob concorrência (vazando-a para sempre).

    // Obter contagem atual de requisições
    // O Redis devolve string (ou false quando a chave não existe); só converte
    // quando o valor for realmente numérico. Qualquer outro caso (false, "",
    // lixo) vira 0, exatamente como o cast direto já fazia.
    $contagem_bruta = $redis->get($chave_limite);
    $contagem_atual = is_numeric($contagem_bruta) ? (int)$contagem_bruta : 0;

    // Verificar se o usuário já atingiu o limite diário
    $atingiu_limite = ($contagem_atual >= $limite_maximo);

    return [
        'atingiu_limite' => $atingiu_limite,
        'contagem_atual' => $contagem_atual,
        'limite_maximo' => $limite_maximo
    ];
}

/**
 * Admite a aquisição de mídia ANTES de getFile/download, sem cobrar a OpenAI.
 *
 * Imagem e áudio compartilham até 5 aquisições por sujeito e 20 por bot em
 * janelas de 60 segundos iniciadas no primeiro consumo. Mídia inválida também
 * custa aquisição. A cota paga continua sendo reservada depois da validação:
 * esta consulta antecipada não substitui a reserva atômica final.
 *
 * @return 'permitida'|'cota'|'temporario'
 */
function admitir_midia(Redis $redis, int $id_sujeito, int $unidades): string
{
    if ($unidades < 1 || $unidades > 2) {
        return 'cota';
    }

    $script = <<<'LUA'
local paga = tonumber(redis.call('GET', KEYS[1]) or '0')
if paga == nil or paga < 0 or paga + tonumber(ARGV[1]) > 100 then
    return -1
end
local sujeito = tonumber(redis.call('GET', KEYS[2]) or '0')
local global = tonumber(redis.call('GET', KEYS[3]) or '0')
if sujeito == nil or global == nil then
    return 0
end
if sujeito < 0 or global < 0 or sujeito >= 5 or global >= 20 then
    return 0
end
local novoSujeito = redis.call('INCRBY', KEYS[2], 1)
if novoSujeito == 1 then
    redis.call('EXPIRE', KEYS[2], 60)
end
local novoGlobal = redis.call('INCRBY', KEYS[3], 1)
if novoGlobal == 1 then
    redis.call('EXPIRE', KEYS[3], 60)
end
return 1
LUA;

    $resultado = $redis->eval($script, [
        'limite_diario:' . $id_sujeito . ':' . limite_diario_dia_atual(),
        'dealnibot:midia:v1:sujeito:' . $id_sujeito,
        'dealnibot:midia:v1:global',
        $unidades,
    ], 3);

    // Como na cota paga, false recusa e exceções interrompem o fluxo.
    return match ($resultado) {
        1 => 'permitida',
        0 => 'temporario',
        default => 'cota',
    };
}

/**
 * Consome uma vaga da cota em uma única operação atômica no Redis.
 *
 * @return array{permitida: bool, atingiu_limite: bool, contagem_atual: int, limite_maximo: int}
 */
function consumir_limite_diario(
    Redis $redis,
    int $id_usuario,
    int $limite_maximo = 100,
    int $unidades = 1
): array {
    $unidades = max(1, $unidades);
    $hoje = limite_diario_dia_atual();
    $chave_limite = "limite_diario:{$id_usuario}:{$hoje}";
    $script = <<<'LUA'
local atual = redis.call('GET', KEYS[1])
if atual == false then
    atual = 0
else
    atual = tonumber(atual)
end
local unidades = tonumber(ARGV[2])
local limite = tonumber(ARGV[3])
if atual + unidades > limite then
    return -(atual + 1)
end
local novo = redis.call('INCRBY', KEYS[1], unidades)
if novo == unidades then
    redis.call('EXPIRE', KEYS[1], tonumber(ARGV[1]))
end
return novo
LUA;

    $resultado = $redis->eval($script, [$chave_limite, 86400, $unidades, $limite_maximo], 1);
    // Falha fechada: sem contagem confiável, não libera uma chamada paga.
    $resultadoNumerico = is_numeric($resultado) ? (int) $resultado : -($limite_maximo + 2);
    $permitida = $resultadoNumerico >= 0 && $resultadoNumerico <= $limite_maximo;
    // Resultado negativo codifica recusa sem alterar o contador: -(atual + 1).
    $contagemAtual = $resultadoNumerico < 0 ? max(0, -$resultadoNumerico - 1) : $resultadoNumerico;

    return [
        'permitida' => $permitida,
        'atingiu_limite' => !$permitida,
        'contagem_atual' => $contagemAtual,
        'limite_maximo' => $limite_maximo,
    ];
}

/**
 * Incrementa o contador de requisições diárias de um usuário
 * @param Redis $redis Instância do Redis
 * @param int $id_usuario ID do usuário do Telegram
 * @return int Nova contagem de requisições
 */
function incrementar_contador_requisicoes(Redis $redis, int $id_usuario): int
{
    $hoje = limite_diario_dia_atual();
    $chave_limite = "limite_diario:{$id_usuario}:{$hoje}";

    // Helper legado para consultas internas. O portão de produção usa
    // consumir_limite_diario(), que combina INCR, EXPIRE e decisão via Lua.
    $nova_contagem = $redis->incr($chave_limite);
    if (!is_int($nova_contagem)) {
        // incr() devolve false em falha (ou Redis em modo pipeline): sem uma
        // contagem confiável, devolve 0 e não aplica TTL — a próxima chamada
        // bem-sucedida que devolver 1 aplicará a expiração.
        return 0;
    }
    if ($nova_contagem === 1) {
        $redis->expire($chave_limite, 86400);
    }

    return $nova_contagem;
}
