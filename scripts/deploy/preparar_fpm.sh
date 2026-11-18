#!/usr/bin/env bash

# Deriva o ambiente privado do mestre FPM a partir do contrato de um único bot.
# Nunca imprima os valores: o arquivo pode conter senha/chave de API.
set -euo pipefail

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) ----------
bot_slug="dealnibot"
env_prefix="DEALNI"
expected_dir="/var/www/dealnibot"
environment_file="/etc/dealnibot/environment"
fpm_environment_file="/etc/dealnibot/fpm.environment"
redis_port_default="6380"
source_names=(DEALNI_DB_DSN DEALNI_DB_USER DEALNI_DB_PASSWORD DEALNI_PUBLIC_BASE_URL DEALNI_BOT_USERNAME REDIS_HOST REDIS_PORT REDIS_PASSWORD DEALNI_ALERTA_CHAT_ID)
# --- FIM DOS DADOS; daqui para baixo o código é canônico ---------------------

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
entrada="$environment_file"
saida="$fpm_environment_file"
verificar=0
for argumento in "$@"; do
    case "$argumento" in
        --entrada=*) entrada="${argumento#*=}" ;;
        --saida=*) saida="${argumento#*=}" ;;
        --verificar) verificar=1 ;;
        *) echo "Uso: preparar_fpm.sh [--verificar] [--entrada=arquivo --saida=arquivo]" >&2; exit 2 ;;
    esac
done
if [[ "$entrada" != /* || "$saida" != /* || "$entrada" == "$saida" ]]; then
    echo "Entrada e saída devem ser caminhos absolutos distintos." >&2
    exit 2
fi
if [[ "$saida" == "$fpm_environment_file" && "$verificar" -eq 0 ]]; then
    if [[ "$project_dir" != "$expected_dir" || "$(id -u)" -ne 0 || "${DEPLOY_CONFIRM:-}" != "$bot_slug" ]]; then
        echo "No host, execute como root: DEPLOY_CONFIRM=$bot_slug scripts/deploy/preparar_fpm.sh" >&2
        exit 2
    fi
fi
if [[ ! -f "$entrada" || -L "$entrada" || -L "$saida" || ( -e "$saida" && ! -f "$saida" ) ]]; then
    echo "Ambiente FPM exige arquivos regulares sem symlink." >&2
    exit 1
fi
saida_dir="$(dirname "$saida")"
if [[ ! -d "$saida_dir" || -L "$saida_dir" ]]; then
    echo "Diretório de saída ausente ou symlink." >&2
    exit 1
fi
php "$project_dir/scripts/quality/check_env.php" "--file=$entrada" >/dev/null
# O mesmo parser usado pelo cron, sem eval/source de dados do EnvironmentFile.
# O helper é conferido separadamente pelo check:shell; o caminho depende do checkout.
# shellcheck disable=SC1091
source "$project_dir/scripts/operacao/carregar_ambiente_seguro.sh"
carregar_ambiente_seguro "$entrada"
# No host, valide também os segredos exigidos pelos recursos habilitados.
if [[ "$saida" == "$fpm_environment_file" ]]; then
    php "$project_dir/scripts/quality/check_production_config.php"
fi
export REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
export REDIS_PORT="${REDIS_PORT:-$redis_port_default}"
export REDIS_PASSWORD="${REDIS_PASSWORD:-}"

temporario="$(mktemp "$saida_dir/.fpm-environment.XXXXXX")"
trap 'rm -f -- "$temporario"' EXIT
chmod 0600 "$temporario"
# Somente nomes e caminhos entram em argv; os valores chegam pelo ambiente.
# shellcheck disable=SC2016
php -r '
    $prefixo = $argv[1];
    $linhas = ["# Gerado por fpm:prepare; edite somente o EnvironmentFile de origem."];
    foreach (array_slice($argv, 3) as $nome) {
        $valor = getenv($nome);
        $valor = is_string($valor) ? $valor : "";
        if (strpbrk($valor, "\r\n\0") !== false) {
            fwrite(STDERR, "Valor FPM precisa ocupar uma linha: " . $nome . PHP_EOL);
            exit(1);
        }
        $sufixo = str_starts_with($nome, $prefixo . "_") ? substr($nome, strlen($prefixo) + 1) : $nome;
        $valor = str_replace(["\\", "\""], ["\\\\", "\\\""], $valor);
        $linhas[] = $prefixo . "_FPM_" . $sufixo . "=\"" . $valor . "\"";
    }
    if (file_put_contents($argv[2], implode(PHP_EOL, $linhas) . PHP_EOL) === false) {
        exit(1);
    }
' "$env_prefix" "$temporario" "${source_names[@]}"
if ((verificar)); then
    # O arquivo derivado pode conter credenciais; compare também seu modo.
    # shellcheck disable=SC2016
    if [[ ! -f "$saida" ]] || ! php -r 'exit((fileperms($argv[1]) & 0777) === 0600 ? 0 : 1);' "$saida"; then
        echo "Ambiente FPM ausente ou sem permissão privada 0600." >&2
        exit 1
    fi
    if ! cmp -s "$temporario" "$saida"; then
        echo "Ambiente FPM desatualizado: execute fpm:prepare e reinicie o PHP-FPM." >&2
        exit 1
    fi
    echo "Ambiente FPM corresponde ao contrato do bot."
    exit 0
fi
mv -f -- "$temporario" "$saida"
trap - EXIT
echo "Ambiente FPM preparado. Reinicie o PHP-FPM para carregar os valores."
