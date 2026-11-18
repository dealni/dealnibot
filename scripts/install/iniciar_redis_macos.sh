#!/usr/bin/env bash

# Instância local exclusiva. Execute novamente após reiniciar o Mac.
set -euo pipefail
umask 077

bot_slug="dealnibot"
redis_port="6380"
state_root="${CHATBOTS_DEV_STATE_DIR:-$HOME/Library/Application Support/Chatbots}"

if [[ "$(uname -s)" != Darwin || "$state_root" != /* ]]; then
    echo "Execute no macOS com um diretório de estado absoluto." >&2
    exit 1
fi
for command in redis-server redis-cli; do
    command -v "$command" >/dev/null || { echo "Instale Redis pelo Homebrew primeiro." >&2; exit 1; }
done
state_dir="$state_root/$bot_slug/redis"
if [[ -L "$state_root" || -L "$state_root/$bot_slug" || -L "$state_dir" ]]; then
    echo "O diretório da instância Redis não pode ser symlink." >&2
    exit 1
fi
mkdir -p "$state_dir"
state_dir="$(cd "$state_dir" && pwd -P)"
for file in redis.pid redis.log dump.rdb appendonlydir; do
    [[ ! -L "$state_dir/$file" ]] || { echo "Estado Redis não pode ser symlink." >&2; exit 1; }
done

instancia_pronta() {
    [[ "$(redis-cli -h 127.0.0.1 -p "$redis_port" --raw PING 2>/dev/null || true)" == PONG ]] &&
        [[ "$(redis-cli -h 127.0.0.1 -p "$redis_port" --raw CONFIG GET dir 2>/dev/null | tail -n 1)" == "$state_dir" ]]
}
if instancia_pronta; then
    echo "Redis do $bot_slug já está disponível em 127.0.0.1:$redis_port."
    exit 0
fi
if [[ -n "$(redis-cli -h 127.0.0.1 -p "$redis_port" --raw PING 2>/dev/null || true)" ]]; then
    echo "Porta $redis_port pertence a outra instância; nenhuma configuração foi alterada." >&2
    exit 1
fi
redis-server --bind 127.0.0.1 --protected-mode yes --port "$redis_port" \
    --dir "$state_dir" --dbfilename dump.rdb --appendonly yes \
    --daemonize yes --pidfile "$state_dir/redis.pid" --logfile "$state_dir/redis.log"
for _attempt in 1 2 3 4 5; do
    if instancia_pronta; then
        echo "Redis do $bot_slug disponível em 127.0.0.1:$redis_port."
        exit 0
    fi
    sleep 0.2
done
echo "Redis do $bot_slug não iniciou; confira $state_dir/redis.log." >&2
exit 1
