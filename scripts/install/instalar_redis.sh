#!/usr/bin/env bash

set -euo pipefail

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
bot_slug="dealnibot"
redis_port="6380"
# Desenho do host: o Redis deste bot roda como o próprio usuário do bot, com a
# configuração ao lado do ambiente (/etc/dealnibot, root:dealnibot 0750) e os
# dados dentro do estado do host (/var/lib/dealnibot, do bot e 0700).
redis_user="dealnibot"
redis_group="dealnibot"
config_dir="/etc/dealnibot"
config="/etc/dealnibot/redis.conf"
# Diretório pai criado antes dos dados, do usuário do Redis e 0700; vazio
# quando os dados ficam direto em /var/lib.
state_dir="/var/lib/dealnibot"
data_dir="/var/lib/dealnibot/redis"
# --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
service="redis-${bot_slug}.service"
unit="/etc/systemd/system/${service}"
dropin_dir="/etc/systemd/system/${service}.d"
dropin="${dropin_dir}/60-isolamento.conf"

if [[ "$(uname -s)" != Linux || "$(id -u)" -ne 0 ]]; then
    echo "Execute este instalador como root em Linux." >&2
    exit 1
fi

# Unidades já instaladas mantêm configuração, diretório e dados existentes.
# A primeira instalação cria somente a instância deste bot.
if ! systemctl cat "$service" >/dev/null 2>&1; then
    if ! id -u "$redis_user" >/dev/null 2>&1; then
        echo "Usuário ${redis_user} ausente; crie-o antes de instalar o Redis." >&2
        exit 1
    fi
    for path in "$config_dir" "$config" "$state_dir" "$data_dir" "$unit" "$dropin_dir" "$dropin"; do
        if [[ -n "$path" && -L "$path" ]]; then
            echo "Caminho do Redis não pode ser symlink: $path" >&2
            exit 1
        fi
    done
    listeners="$(ss -H -lnt "sport = :${redis_port}")"
    if [[ -n "$listeners" ]]; then
        echo "Porta ${redis_port} ocupada sem ${service}; confira a instância antes de instalar." >&2
        exit 1
    fi
    if [[ ! -d "$config_dir" ]]; then
        install -d -o root -g "$redis_group" -m 0750 "$config_dir"
    fi
    if [[ -n "$state_dir" ]]; then
        install -d -o "$redis_user" -g "$redis_group" -m 0700 "$state_dir"
    fi
    install -d -o "$redis_user" -g "$redis_group" -m 0750 "$data_dir"
    if [[ ! -e "$config" ]]; then
        install -o root -g "$redis_group" -m 0640 "$project_dir/deploy/redis/${bot_slug}.conf" "$config"
    fi
    install -o root -g root -m 0644 "$project_dir/deploy/systemd/${service}" "$unit"
    install -d -o root -g root -m 0755 "$dropin_dir"
    install -o root -g root -m 0644 "$project_dir/deploy/systemd/${service}.d/60-isolamento.conf" "$dropin"
    systemctl daemon-reload
fi
systemctl enable --now "$service"
for _attempt in 1 2 3 4 5; do
    if [[ "$(timeout 1 redis-cli -h 127.0.0.1 -p "$redis_port" --raw PING 2>/dev/null || true)" == PONG ]]; then
        echo "Redis do ${bot_slug} disponível em 127.0.0.1:${redis_port}."
        exit 0
    fi
    sleep 0.2
done
echo "A instância ${service} não respondeu em 127.0.0.1:${redis_port}." >&2
exit 1
