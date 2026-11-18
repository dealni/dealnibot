#!/bin/sh
# shellcheck shell=bash
if [ -z "${BASH_VERSION:-}" ] || shopt -q -o posix 2>/dev/null; then
    exec /usr/bin/env -i PATH='/usr/local/sbin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' \
        HOME="${HOME:-}" RECOVERY_CONFIRM="${RECOVERY_CONFIRM:-}" /bin/bash -p "$0" "$@" || exit 126
fi
set -euo pipefail
bot="${RECOVERY_CONFIRM:-}"
if [[ "$(id -u)" != 0 || ! "$bot" =~ ^[a-z0-9]+$ || "$#" != 0 ]]; then
    echo 'Recuperação exige root e RECOVERY_CONFIRM com o nome do bot.' >&2
    exit 2
fi
bundle="/var/lib/chatbots-publicacao/$bot.recuperacao"
# Só execute ferramentas após verificar a cadeia privada de diretórios.
[[ -d /var/lib/chatbots-publicacao && ! -L /var/lib/chatbots-publicacao \
    && "$(stat -c '%u:%g:%a' /var/lib/chatbots-publicacao)" == '0:0:700' \
    && -d "$bundle" && ! -L "$bundle" \
    && "$(stat -c '%u:%g:%a' "$bundle")" == '0:0:700' ]] || exit 1
validador="$bundle/scripts/deploy/publicacao_bundle.php"
[[ -f "$validador" && ! -L "$validador" \
    && "$(realpath -e -- "$validador")" == "$validador" \
    && "$(stat -c '%u:%g:%a' "$validador")" == '0:0:600' ]] || exit 1
dados="$(php "$validador" validar "$bot")"
projeto="$(printf '%s\n' "$dados" | sed -n '1p')"
alvo="$(printf '%s\n' "$dados" | sed -n '2p')"
ROLLBACK_CONFIRM="$bot" RELEASE_PROJECT_DIR="$projeto" \
    "$bundle/scripts/deploy/rollback.sh" "--para=$alvo" --retomar-incompleto
