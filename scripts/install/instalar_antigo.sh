#!/usr/bin/env bash

set -euo pipefail

# Compatibilidade para automações antigas. Há uma única implementação Linux:
# manter uma cópia legada do bootstrap recriava o banco e deixava ownership,
# snapshots e versões do PHP divergirem silenciosamente.
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "Aviso: instalar_antigo.sh foi substituído por instalar_linux.sh." >&2
exec "${script_dir}/instalar_linux.sh" "$@"
