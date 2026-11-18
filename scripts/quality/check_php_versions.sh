#!/usr/bin/env bash

# Runtime e validação local: PHP 8.5 é o único alvo suportado.
# A suíte usa a sandbox de rede nativa do sistema operacional.

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

usage() {
    echo "Uso: $0 [local|runtime]" >&2
}

# Gate único de versão: aceita apenas 8.5.x (>=80512 e <80600), como
# composer.json (require.php) e phpstan.neon (phpVersion).
assert_php85() {
    local php_bin="$1"
    local version_id
    local version

    version_id="$("$php_bin" -r 'echo PHP_VERSION_ID;')"
    version="$("$php_bin" -r 'echo PHP_VERSION;')"
    if ((version_id < 80512 || version_id >= 80600)); then
        echo "Esperado PHP 8.5.12 ou superior na linha 8.5; encontrado PHP $version." >&2
        echo "Defina PHP85_BIN se o binário 8.5 estiver em outro caminho." >&2
        exit 1
    fi

    echo "$version"
}

run_local() {
    local mode="$1"
    local php_bin="${PHP85_BIN:-php}"
    local composer_bin="${COMPOSER85_BIN:-composer}"
    local composer_path
    local version

    if ! command -v "$php_bin" >/dev/null 2>&1; then
        echo "PHP 8.5 local não encontrado: $php_bin" >&2
        exit 1
    fi
    if ! composer_path="$(command -v "$composer_bin")"; then
        echo "Composer local não encontrado: $composer_bin" >&2
        exit 1
    fi

    version="$(assert_php85 "$php_bin")"

    # Pin de desenvolvimento/CI; não interfere no deploy ou rollback.
    local composer_version
    composer_version="$("$php_bin" "$composer_path" --no-plugins --no-ansi --version | awk 'NR == 1 {print $3}')"
    if [[ "$composer_version" != "2.10.3" ]]; then
        echo "Composer 2.10.3 exigido; encontrado ${composer_version:-desconhecido}. Defina COMPOSER85_BIN com o binário oficial verificado." >&2
        exit 1
    fi

    echo "Validando $(basename "$project_root") no PHP $version local"
    (
        cd "$project_root"
        "$php_bin" "$composer_path" check-platform-reqs
        # O modo runtime permite validar pins e extensões sem repetir o
        # agregado. O modo local continua sendo a toolchain completa.
        if [[ "$mode" == "local" ]]; then
            "$php_bin" "$composer_path" check:all
        fi
    )
}

if [[ $# -gt 1 || (${1:-local} != "local" && ${1:-local} != "runtime") ]]; then
    usage
    exit 2
fi

run_local "${1:-local}"
