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
        MONITOR_SNAPSHOT_CONFIRM="${MONITOR_SNAPSHOT_CONFIRM:-}" \
        /bin/bash -p "$0" "$@" || exit 126
fi

set -euo pipefail
# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
bot="dealnibot"
# --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------
raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ "$(id -u)" != 0 || "$raiz" != "/var/www/$bot" || "${MONITOR_SNAPSHOT_CONFIRM:-}" != "$bot" ]]; then
    echo "No host, como root: MONITOR_SNAPSHOT_CONFIRM=$bot scripts/deploy/instalar_monitor_snapshot.sh" >&2
    exit 2
fi
[[ -f "/etc/$bot/environment" && ! -L "/etc/$bot/environment" ]] || exit 1
for tipo in service timer; do
    destino="/etc/systemd/system/$bot-snapshot-recencia.$tipo"
    [[ ! -L "$destino" ]] || exit 1
    if [[ -e "$destino" ]] && ! cmp -s "$raiz/deploy/systemd/$bot-snapshot-recencia.$tipo" "$destino"; then
        echo "Unidade existente diverge: $destino. Revise antes de instalar." >&2
        exit 1
    fi
done
for tipo in service timer; do
    install -o root -g root -m 0644 "$raiz/deploy/systemd/$bot-snapshot-recencia.$tipo" "/etc/systemd/system/$bot-snapshot-recencia.$tipo"
done
systemctl daemon-reload
systemctl enable --now "$bot-snapshot-recencia.timer"
echo "Timer instalado; confira a primeira execução e a entrega dos avisos."
