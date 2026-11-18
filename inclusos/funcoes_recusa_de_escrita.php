<?php

declare(strict_types=1);

/**
 * Memória de "o bot não pode escrever neste chat".
 *
 * Supergrupo que tirou do bot o direito de mandar mensagem continua mandando
 * updates: a cada menção o bot chamava `sendChatAction`, recebia a mesma recusa
 * (`CHAT_WRITE_FORBIDDEN`, `not enough rights`) e gravava um erro. Em 7 dias
 * foram 2.682 linhas `error`, todas recusas esperadas, afogando o erro real.
 * A primeira recusa definitiva fica no Redis por RECUSA_DE_ESCRITA_TTL_SEGUNDOS
 * e o roteador nem chama a API daquele chat até a chave vencer; se o grupo
 * devolver a permissão, o bot volta sozinho no vencimento.
 *
 * Redis fora do ar não muda nada: a consulta responde "pode escrever" e a
 * gravação é descartada. O pior caso é a recusa voltar ao log, como antes.
 * A classificação da recusa é de `TelegramErrorLogger::ehRecusaDefinitivaDeEscrita()`.
 */

/** Seis horas: longe o bastante para calar o grupo, perto para voltar sozinho. */
const RECUSA_DE_ESCRITA_TTL_SEGUNDOS = 21600;

function chaveRecusaDeEscrita(int $chatId): string
{
    return 'telegram:sem_escrita:' . $chatId;
}

/**
 * Lembra a recusa quando a resposta da Bot API for definitiva.
 *
 * @param array<mixed> $resposta resposta decodificada da Bot API
 * @return bool verdadeiro quando a resposta é recusa definitiva de escrita
 */
function lembrarRecusaDeEscrita(Redis $redis, ?int $chatId, array $resposta): bool
{
    if (!TelegramErrorLogger::ehRecusaDefinitivaDeEscrita($resposta)) {
        return false;
    }
    if ($chatId !== null) {
        try {
            $redis->set(chaveRecusaDeEscrita($chatId), '1', ['EX' => RECUSA_DE_ESCRITA_TTL_SEGUNDOS]);
        } catch (RedisException) {
            // Sem memória, a próxima menção só repete a recusa já conhecida.
        }
    }

    return true;
}

function chatSemPermissaoDeEscrita(Redis $redis, ?int $chatId): bool
{
    if ($chatId === null) {
        return false;
    }
    try {
        $existe = $redis->exists(chaveRecusaDeEscrita($chatId));

        return is_int($existe) && $existe > 0;
    } catch (RedisException) {
        return false;
    }
}
