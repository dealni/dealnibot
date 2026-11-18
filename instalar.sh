#!/bin/sh
# shellcheck shell=bash

# /bin/sh é só o launcher portátil. O Bash filho usa o mesmo ambiente mínimo dos
# entrypoints de instalação e recebe todos os argumentos do chamador.
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

# Script wrapper que detecta o SO e chama o script de instalação apropriado.
# A execução direta mantém o shim do instalador filho e preserva o vetor de
# argumentos sem passar por `bash script`, que o faria ignorar a limpeza acima.
script_dir="$(cd "$(dirname "$0")" && pwd)"
case "$(uname -s)" in
    Darwin*) exec "$script_dir/scripts/install/instalar_macos.sh" "$@" ;;
    Linux*)  exec "$script_dir/scripts/install/instalar_linux.sh" "$@" ;;
    *)
        echo "SO não suportado. Use: composer run-script install-macos ou install-linux" >&2
        exit 1
        ;;
esac
