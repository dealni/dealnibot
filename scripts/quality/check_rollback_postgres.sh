#!/usr/bin/env bash
set -euo pipefail
raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
tmp="$(mktemp -d "${TMPDIR:-/tmp}/rollback-pg-test.XXXXXX")"
tmp="$(cd "$tmp" && pwd -P)"
trap 'rm -rf -- "$tmp"' EXIT
mkdir -p "$tmp/bot/scripts/deploy" "$tmp/hooks"
cp "$raiz/scripts/deploy/rollback.sh" "$tmp/bot/scripts/deploy/rollback.sh"
cd "$tmp/bot"
git init -q
git config user.name 'Teste local'
git config user.email 'teste@example.invalid'
git config core.hooksPath "$tmp/hooks"
git config commit.gpgsign false
git add .
git commit -qm 'base SQLite sintética'
sqlite="$(git rev-parse HEAD)"
mkdir -p database/postgresql inclusos
echo 'SELECT 1;' > database/postgresql/001_inicial.sql
touch inclusos/banco.php inclusos/BancoPostgres.php scripts/deploy/preparar_fpm.sh
git add .
git commit -qm 'contrato PostgreSQL sintético'
pg="$(git rev-parse HEAD)"
echo 'SELECT 2;' > database/postgresql/002_nova.sql
git add .
git commit -qm 'migration PostgreSQL sintética'
com_migration="$(git rev-parse HEAD)"
echo 'revisão de código' > codigo.txt
git add .
git commit -qm 'código sem alteração de schema'

conferir() {
    local esperado="$1" mensagem="$2" codigo=0
    shift 2
    ROLLBACK_CONFIRM=bot bash scripts/deploy/rollback.sh --ensaio "$@" > "$tmp/saida" 2>&1 || codigo=$?
    if [[ "$codigo" != "$esperado" ]] || ! grep -Fq "$mensagem" "$tmp/saida"; then
        cat "$tmp/saida" >&2
        echo 'Guarda de rollback PostgreSQL falhou.' >&2
        exit 1
    fi
}
conferir 1 'remove o suporte PostgreSQL' "--para=$sqlite"
conferir 1 'remove o suporte PostgreSQL' "--para=$sqlite" --aceito-schema-divergente
conferir 1 'schema mais novo' "--para=$pg"
conferir 0 'Ensaio: nada foi alterado' "--para=$pg" --aceito-schema-divergente
conferir 0 'Ensaio: nada foi alterado' "--para=$com_migration"
# Como root no host, o install do rollback não pode rodar plugin nem script de pacote.
conferir 0 'composer install --no-dev --optimize-autoloader --no-plugins --no-scripts' "--para=$com_migration"
echo 'SELECT 3;' > database/postgresql/002_nova.sql
git add .
git commit -qm 'alteração sintética para testar recusa'
conferir 1 'schema mais novo' "--para=$com_migration"
[[ -z "$(git status --porcelain)" ]]
echo 'Rollback PostgreSQL: fronteira de backend, migrations novas/alteradas e destino compatível OK.'
