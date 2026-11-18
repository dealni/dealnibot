#!/usr/bin/env bash
set -euo pipefail
raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
bash "$raiz/scripts/quality/check_rollback_postgres.sh"
bash "$raiz/scripts/deploy/db_restore_test.sh"

# O rollback roda como root no host, onde o Composer recusa rodar: a recusa sem
# token tem de ensinar a chamada direta do script, não o alias do Composer.
codigo=0
saida="$(env -u ROLLBACK_CONFIRM "$raiz/scripts/deploy/rollback.sh" 2>&1)" || codigo=$?
if [[ "$codigo" -ne 2 || "$saida" != *"scripts/deploy/rollback.sh"* || "$saida" == *composer* ]]; then
    echo "A recusa do rollback deveria sair com 2 e indicar scripts/deploy/rollback.sh, sem o Composer." >&2
    echo "$saida" >&2
    exit 1
fi
