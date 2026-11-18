#!/usr/bin/env bash
# Provisiona somente o banco deste checkout e um EnvironmentFile candidato.
set -Eeuo pipefail
umask 077
bot_slug="dealnibot"
env_prefix="DEALNI"
[[ "$(id -u)" -eq 0 && "${DEPLOY_CONFIRM:-}" == "$bot_slug" ]] || {
    echo "Execute como root com DEPLOY_CONFIRM=$bot_slug." >&2
    exit 1
}
project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[[ "$project_dir" == "/var/www/$bot_slug" ]] || { echo 'Checkout fora do caminho canônico.' >&2; exit 1; }
command -v psql >/dev/null
php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);'
php "$project_dir/scripts/quality/check_env.php" "--file=/etc/$bot_slug/environment"
# O Python mantém a senha fora do histórico, argv, saída e arquivos temporários.
python3 - "$bot_slug" "$env_prefix" <<'PY'
import grp, os, pathlib, secrets, subprocess, sys, tempfile
bot, prefix = sys.argv[1:]
candidate = pathlib.Path('/etc') / bot / 'postgresql.environment'
source = candidate.with_name('environment')
if candidate.exists() or candidate.is_symlink() or source.is_symlink():
    raise SystemExit('Arquivo candidato já existe ou ambiente é symlink; nenhuma alteração.')
def query(sql):
    result = subprocess.run(['runuser','-u','postgres','--','psql','-X','-v','ON_ERROR_STOP=1','-At','-d','postgres'],input=sql,text=True,capture_output=True)
    if result.returncode:
        raise SystemExit('Provisionamento PostgreSQL falhou; confira o serviço local. Nenhuma senha foi exibida.')
    return result.stdout.strip()
if query("SELECT 1 FROM pg_roles WHERE rolname = '"+bot+"';") or query("SELECT 1 FROM pg_database WHERE datname = '"+bot+"';"):
    raise SystemExit('Usuário ou banco já existe; provisionamento recusado para preservar o estado.')
password = secrets.token_hex(32)
query("SET password_encryption = 'scram-sha-256'; CREATE ROLE "+bot+" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '"+password+"';")
query('CREATE DATABASE '+bot+' OWNER '+bot+" ENCODING 'UTF8' TEMPLATE template0;")
query('REVOKE ALL ON DATABASE '+bot+' FROM PUBLIC;')
keys = {prefix+'_DB_DSN',prefix+'_DB_USER',prefix+'_DB_PASSWORD'}
lines = [line for line in source.read_text().splitlines() if line.split('=',1)[0].strip() not in keys]
lines += [prefix+'_DB_DSN="pgsql:host=127.0.0.1;port=5432;dbname='+bot+';connect_timeout=5"',prefix+'_DB_USER="'+bot+'"',prefix+'_DB_PASSWORD="'+password+'"']
fd = os.open(candidate, os.O_WRONLY|os.O_CREAT|os.O_EXCL, 0o640)
with os.fdopen(fd,'w') as out:
    out.write('\n'.join(lines)+'\n')
os.chown(candidate,0,grp.getgrnam(bot).gr_gid)
os.chmod(candidate,0o640)
print('Banco e usuário criados. Ambiente candidato: '+str(candidate))
print('O ambiente ativo ainda usa o banco anterior.')
PY
php "$project_dir/scripts/quality/check_env.php" "--file=/etc/$bot_slug/postgresql.environment"
