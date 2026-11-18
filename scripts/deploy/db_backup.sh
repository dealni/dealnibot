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
        /bin/bash -p "$0" "$@" || exit 126
fi

set -euo pipefail
raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ -f "/etc/dealnibot/environment" ]]; then
    # Caminho depende do checkout; o helper é conferido separadamente.
    # shellcheck disable=SC1091
    source "$raiz/scripts/operacao/carregar_ambiente_seguro.sh"
    carregar_ambiente_seguro "/etc/dealnibot/environment"
fi
exec php "$raiz/scripts/deploy/db_backup.php" "$@"
