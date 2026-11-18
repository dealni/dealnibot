#!/usr/bin/env bash

set -euo pipefail

# `scripts/deploy/deploy.sh` é um entrypoint `#!/bin/sh` que se reexecuta em
# Bash com o ambiente limpo. Chamado como `bash scripts/deploy/deploy.sh`, essa
# reentrada não acontece — o corpo roda direto, com o ambiente de quem chamou.
# Os dois caminhos precisam recusar: conferir só um deixaria o outro livre para
# regredir em silêncio.
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

conferir() {
    local descricao="$1"
    shift
    local output
    local status=0

    set +e
    output="$(env -u DEPLOY_CONFIRM "$@" 2>&1)"
    status=$?
    set -e

    if [[ $status -ne 2 ]] || [[ "$output" != *"Deploy não iniciado"* ]]; then
        echo "O deploy sem confirmação deveria recusar com status 2 (${descricao})." >&2
        echo "$output" >&2
        exit 1
    fi
    # No host o deploy roda como root, e o Composer recusa rodar como root: a
    # recusa tem de ensinar a chamada direta do script, não o alias do Composer.
    if [[ "$output" != *"scripts/deploy/deploy.sh"* ]] || [[ "$output" == *composer* ]]; then
        echo "A recusa do deploy deveria indicar scripts/deploy/deploy.sh, sem o Composer (${descricao})." >&2
        echo "$output" >&2
        exit 1
    fi
}

conferir 'entrypoint sh, passando pelo shim de ambiente' ./scripts/deploy/deploy.sh
conferir 'invocado por bash, sem o shim' bash scripts/deploy/deploy.sh

echo "Guarda de confirmação do deploy: OK nos dois modos de invocação, com a chamada direta do script."
