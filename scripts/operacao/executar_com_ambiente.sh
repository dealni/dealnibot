#!/bin/sh
# shellcheck shell=bash

# /bin/sh é só o launcher portátil. No macOS ele é Bash em modo POSIX; o Bash
# filho abaixo volta em modo normal/privilegiado. Essa diferença, junto de
# BASH_VERSION, detecta a reentrada sem reservar ou consumir um argumento do
# usuário.
reentrada=0
if [ -n "${BASH_VERSION:-}" ] && ! shopt -q -o posix 2>/dev/null; then
    reentrada=1
fi
if [ "$reentrada" -eq 0 ]; then
    exec /usr/bin/env -i \
        PATH='/usr/local/sbin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' \
        HOME="${HOME:-}" \
        USER="${USER:-}" \
        LOGNAME="${LOGNAME:-}" \
        SUDO_USER="${SUDO_USER:-}" \
        CHATBOTS_ENV_FILE="${CHATBOTS_ENV_FILE:-}" \
        /bin/bash -p "$0" "$@" || exit 126
fi

# Entrada única dos jobs do host. O /etc/cron.d não ganha uma segunda cópia
# das variáveis (nem das credenciais de produto): o mesmo contrato usado pelo FPM é
# carregado aqui e o comando recebido substitui este processo.
#
# Além de executar um comando arbitrário, este wrapper oferece duas formas
# deliberadas para o cron:
#   --php                 usa o PHP85_BIN do environment, nunca um caminho
#                         duplicado no crontab;
#   --lock                espera por flock no arquivo informado antes do comando;
#   --lock-nonblocking    tenta flock sem esperar e sai sem executar se ocupado.
# O modo padrão espera o lock; `--lock-nonblocking` é para workers de minuto,
# que já têm lease/idempotência e podem deixar a próxima rodada continuar.

set -euo pipefail

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) ---------
# Nome do bot: é o usuário/grupo de runtime e o diretório em /etc.
bot="dealnibot"
# --- FIM DOS DADOS; daqui para baixo o código é canônico ---------------------

# CHATBOTS_ENV_FILE aponta para outro EnvironmentFile (a suíte usa isso); o
# prefixo é o comum da frota, como CHATBOTS_RELEASE_FILE, porque a variável é
# do wrapper e não do produto.
environment_file="${CHATBOTS_ENV_FILE:-/etc/$bot/environment}"
unset CHATBOTS_ENV_FILE
export PATH='/usr/local/sbin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'

canonicalizar_caminho_existente() {
    # GNU realpath aceita -e/--; o utilitário do macOS não. O arquivo é
    # conferido como regular antes desta chamada, então ambas as variantes
    # preservam a garantia de que nenhum ancestral é um symlink.
    if [[ "$OSTYPE" == darwin* ]]; then
        realpath "$1"
    else
        realpath -e -- "$1"
    fi
}

environment_file_seguro() {
    local canonico metadados dono grupo modo

    [[ "$environment_file" == /* && -f "$environment_file" && ! -L "$environment_file" ]] || return 1
    canonico="$(canonicalizar_caminho_existente "$environment_file" 2>/dev/null)" || return 1
    [[ "$canonico" == "$environment_file" ]] || return 1
    [[ ! -L "$(dirname "$environment_file")" ]] || return 1

    if [[ "$OSTYPE" == darwin* ]]; then
        dono="$(stat -f '%u' "$environment_file" 2>/dev/null)" || return 1
        grupo="$(stat -f '%Sg' "$environment_file" 2>/dev/null)" || return 1
        modo="$(stat -f '%Lp' "$environment_file" 2>/dev/null)" || return 1
        [[ "$dono" == 0 && ("$grupo" == root || "$grupo" == "$bot") && ("$modo" == 600 || "$modo" == 640) ]]
    else
        metadados="$(stat -c '%u:%U:%G:%a' -- "$environment_file" 2>/dev/null)" || return 1
        [[ "$metadados" == '0:root:root:600' || "$metadados" == "0:root:$bot:640" ]]
    fi
}

if [[ ! -r "$environment_file" ]]; then
    echo "Contrato de ambiente ausente ou ilegível: $environment_file" >&2
    exit 1
fi
if ! environment_file_seguro; then
    echo "EnvironmentFile inseguro: use root:root 0600 ou root:$bot 0640, sem symlink: $environment_file" >&2
    exit 1
fi
if (($# == 0)); then
    echo "Uso: executar_com_ambiente.sh COMANDO [ARGUMENTOS...]" >&2
    exit 2
fi

# EnvironmentFile compartilha a sintaxe do drop-in do systemd, mas não é
# código de shell. O parser mantém valores com espaços/metacaracteres como
# dados e falha em linhas inválidas.
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$script_dir/carregar_ambiente_seguro.sh"
carregar_ambiente_seguro "$environment_file"

lock_file=""
lock_nonblocking=0
php_mode=0

# Opções do wrapper vêm antes do comando. Não usamos `bash -c`: os argumentos
# continuam vetorizados e nomes de arquivo vindos do environment não viram
# código de shell.
while (($# > 0)); do
    case "$1" in
        --php)
            php_mode=1
            shift
            ;;
        --lock)
            if (($# < 2)); then
                echo "Uso: executar_com_ambiente.sh --lock ARQUIVO COMANDO [ARGUMENTOS...]" >&2
                exit 2
            fi
            lock_file="$2"
            shift 2
            ;;
        --lock-nonblocking)
            if (($# < 2)); then
                echo "Uso: executar_com_ambiente.sh --lock-nonblocking ARQUIVO COMANDO [ARGUMENTOS...]" >&2
                exit 2
            fi
            lock_file="$2"
            lock_nonblocking=1
            shift 2
            ;;
        --)
            shift
            break
            ;;
        *)
            break
            ;;
    esac
done

if (($# == 0)); then
    echo "Uso: executar_com_ambiente.sh [--php] [--lock ARQUIVO|--lock-nonblocking ARQUIVO] COMANDO [ARGUMENTOS...]" >&2
    exit 2
fi

if ((php_mode)); then
    php_bin="${PHP85_BIN:-}"
    if [[ "$php_bin" != /* || ! -x "$php_bin" || -L "$php_bin" ]]; then
        echo "PHP85_BIN ausente, não absoluto ou não executável: ${php_bin:-<vazio>}" >&2
        exit 1
    fi
    if ! php_bin_canonico="$(canonicalizar_caminho_existente "$php_bin" 2>/dev/null)" ||
        [[ "$php_bin_canonico" != "$php_bin" ]]; then
        echo "PHP85_BIN precisa ser um caminho canônico sem symlink: $php_bin" >&2
        exit 1
    fi
    if ! "$php_bin" -r 'exit(PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600 ? 0 : 1);' 2>/dev/null; then
        echo "PHP85_BIN não aponta para PHP 8.5.12 ou superior na linha 8.5: $php_bin" >&2
        exit 1
    fi
    set -- "$php_bin" "$@"
fi

lock_fd_tem_modo_0600() {
    local modo
    if [[ "$OSTYPE" == darwin* ]]; then
        modo="$(stat -L -f '%Lp' "$lock_fd_path" 2>/dev/null)" || return 1
    else
        # /proc/<pid>/fd/N é um symlink para o arquivo aberto. Sem -L, o GNU
        # stat confere o modo do alias em /proc, não o 0600 do lock real, e
        # todos os jobs com --lock/--lock-nonblocking abortam antes do comando.
        modo="$(stat -L -c '%a' -- "$lock_fd_path" 2>/dev/null)" || return 1
    fi
    [[ "$modo" == 600 ]]
}

if [[ -n "$lock_file" ]]; then
    if [[ "$lock_file" != /* ]]; then
        echo "Arquivo de lock precisa ser um caminho absoluto: $lock_file" >&2
        exit 2
    fi
    # O instalador provisiona os locks como arquivos regulares dentro de uma
    # árvore protegida. Não deixe `flock` criar um caminho arbitrário nem siga
    # um symlink no último componente ou em qualquer ancestral. A comparação
    # com `realpath -e` também recusa `.`/`..`, barras duplicadas e aliases.
    if [[ ! -f "$lock_file" || -L "$lock_file" ]]; then
        echo "Arquivo de lock ausente, não regular ou symlink: $lock_file" >&2
        exit 1
    fi
    if ! lock_canonico="$(canonicalizar_caminho_existente "$lock_file" 2>/dev/null)"; then
        echo "Arquivo de lock não pôde ser canonicalizado: $lock_file" >&2
        exit 1
    fi
    if [[ "$lock_canonico" != "$lock_file" ]]; then
        echo "Arquivo de lock precisa ser caminho canônico sem symlinks: $lock_file" >&2
        exit 1
    fi
    flock_bin="$(command -v flock || true)"
    if [[ -z "$flock_bin" ]]; then
        echo "flock não encontrado; instale util-linux antes de habilitar os jobs." >&2
        exit 1
    fi
    # Abrir primeiro e travar o descritor evita que o comando use um segundo
    # lookup pelo nome. A conferência do descritor reduz a janela entre a
    # checagem acima e a abertura; em Bash não há openat(O_NOFOLLOW)/dirfd
    # portátil para eliminar completamente essa janela.
    if ! exec {lock_fd}<>"$lock_file"; then
        echo "Não foi possível abrir o arquivo de lock: $lock_file" >&2
        exit 1
    fi
    lock_fd_path="/proc/$$/fd/$lock_fd"
    if [[ "$OSTYPE" == darwin* ]]; then
        lock_fd_path="/dev/fd/$lock_fd"
    fi
    if ! lock_fd_canonico="$(canonicalizar_caminho_existente "$lock_fd_path" 2>/dev/null)" ||
        [[ "$lock_fd_canonico" != "$lock_file" ]]; then
        echo "O arquivo de lock mudou durante a abertura: $lock_file" >&2
        exit 1
    fi
    if ! chmod 0600 "$lock_fd_path" || ! lock_fd_tem_modo_0600; then
        echo "Lock de operação não ficou restrito a 0600: $lock_file" >&2
        exit 1
    fi
    if ((lock_nonblocking)); then
        "$flock_bin" --nonblock "$lock_fd" || exit $?
    else
        "$flock_bin" --exclusive "$lock_fd" || exit $?
    fi
    if ! lock_fd_tem_modo_0600; then
        echo "Lock de operação mudou de modo após flock: $lock_file" >&2
        exit 1
    fi
fi

exec "$@"
