#!/usr/bin/env bash
set -euo pipefail
# Cluster exclusivo desta execução, sem TCP nem acesso a bancos existentes.
if [[ -n "${CHATBOTS_PG_TEST_DSN:-}" ]]; then
    exec "$@"
fi
# Não herdar o destino de um cliente PostgreSQL de produção.
unset PGHOST PGPORT PGDATABASE PGUSER PGPASSWORD PGSERVICE PGSERVICEFILE PGPASSFILE PGOPTIONS
pg_bin="${CHATBOTS_PG_BIN:-}"
if [[ -z "$pg_bin" ]]; then
    # A descoberta automática cobre apenas as séries 16 e 17 usadas nos testes.
    # Ambos os prefixos Homebrew são possíveis, inclusive sob Rosetta.
    for candidato in /opt/homebrew/opt/postgresql@17/bin /usr/local/opt/postgresql@17/bin \
        /opt/homebrew/opt/postgresql@16/bin /usr/local/opt/postgresql@16/bin \
        /usr/lib/postgresql/17/bin /usr/lib/postgresql/16/bin; do
        if [[ -x "$candidato/initdb" ]]; then pg_bin="$candidato"; break; fi
    done
fi
if [[ -z "$pg_bin" ]]; then
    echo 'PostgreSQL 16/17 não encontrado nos prefixos Homebrew ou Linux conhecidos. Defina CHATBOTS_PG_BIN com o diretório bin da instalação local.' >&2
    exit 2
fi
for ferramenta in initdb pg_ctl createdb; do
    if [[ ! -x "$pg_bin/$ferramenta" ]]; then
        echo "CHATBOTS_PG_BIN inválido: falta executável $ferramenta em $pg_bin. Informe o diretório bin de uma instalação PostgreSQL completa." >&2
        exit 2
    fi
done
export PATH="$pg_bin:$PATH"
export LC_ALL=C
temporario="$(mktemp -d /tmp/bot-pg.XXXXXXXX)"
limpar() {
    "$pg_bin/pg_ctl" -D "$temporario/data" -m immediate stop >/dev/null 2>&1 || true
    rm -rf -- "$temporario"
}
trap limpar EXIT
"$pg_bin/initdb" -D "$temporario/data" -A trust --no-locale -E UTF8 >"$temporario/init.log" 2>&1
porta_postgres_testes=$((20000 + RANDOM % 40000))
if ! "$pg_bin/pg_ctl" -D "$temporario/data" -l "$temporario/server.log" -o "-k $temporario -p $porta_postgres_testes -c listen_addresses='' -c fsync=off -c synchronous_commit=off -c max_connections=100" -w start >/dev/null; then
    cat "$temporario/server.log" >&2
    exit 1
fi
"$pg_bin/createdb" -h "$temporario" -p "$porta_postgres_testes" chatbots_test_suite
export CHATBOTS_PG_TEST_DSN="pgsql:host=$temporario;port=$porta_postgres_testes;dbname=chatbots_test_suite"
# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
# Prefixo das variáveis de banco deste bot: o mesmo cluster efêmero é
# apresentado ao código do produto com o nome que ele espera.
export DEALNI_DB_DSN="$CHATBOTS_PG_TEST_DSN"
DEALNI_DB_USER="$(id -un)"
export DEALNI_DB_USER
export DEALNI_DB_PASSWORD=""
# --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------
"$@"
