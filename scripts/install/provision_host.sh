#!/usr/bin/env bash

# Provisiona a máquina Linux que hospeda o bot. É deliberadamente separado do
# setup do checkout porque instala/atualiza pacotes e habilita serviços do SO.

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
project_name="$(basename "$project_dir")"

if [[ "${PROVISION_CONFIRM:-}" != "$project_name" ]]; then
    echo "Provisionamento não iniciado. Confirme a alteração da máquina, como root, com:" >&2
    echo "  PROVISION_CONFIRM=$project_name bash scripts/install/provision_host.sh" >&2
    exit 2
fi

if [[ "$(uname -s)" != "Linux" ]]; then
    echo "Provisionamento de host só é suportado em Linux." >&2
    exit 1
fi

exec bash "$project_dir/scripts/install/instalar_linux.sh"
