#!/usr/bin/env bash
set -euo pipefail

if [[ $# -eq 0 ]]; then
    echo 'Informe o comando da suíte de testes.' >&2
    exit 2
fi
if [[ -n "${CHATBOTS_REDIS_TEST_SOCKET:-}" ]]; then
    exec "$@"
fi
command -v redis-server >/dev/null
command -v redis-cli >/dev/null

diretorio_redis_teste=$(mktemp -d /tmp/chatbots-redis-teste.XXXXXX)
chmod 700 "$diretorio_redis_teste"
export CHATBOTS_REDIS_TEST_SOCKET="$diretorio_redis_teste/redis.sock"
redis-server --port 0 --unixsocket "$CHATBOTS_REDIS_TEST_SOCKET" \
    --unixsocketperm 700 --save '' --appendonly no --daemonize no \
    --dir "$diretorio_redis_teste" > "$diretorio_redis_teste/redis.log" 2>&1 &
pid_redis_teste=$!
trap '
    kill "$pid_redis_teste" 2>/dev/null || true
    wait "$pid_redis_teste" 2>/dev/null || true
    rm -rf -- "$diretorio_redis_teste"
' EXIT
for ((tentativa_redis_teste = 0; tentativa_redis_teste < 100; tentativa_redis_teste++)); do
    if [[ -S "$CHATBOTS_REDIS_TEST_SOCKET" ]] && [[ "$(redis-cli -s "$CHATBOTS_REDIS_TEST_SOCKET" ping 2>/dev/null)" == PONG ]]; then
        # O cliente acrescenta a porta validada ao URI unix; o alias fica só nesta fixture.
        ln -s "$CHATBOTS_REDIS_TEST_SOCKET" "$CHATBOTS_REDIS_TEST_SOCKET:1"
        "$@"
        exit $?
    fi
    sleep 0.02
done
echo 'O Redis descartável não ficou disponível.' >&2
exit 2
