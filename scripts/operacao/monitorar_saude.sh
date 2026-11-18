#!/bin/sh
# shellcheck shell=bash

# /bin/sh é só o launcher portátil. No macOS ele é Bash em modo POSIX; o Bash
# filho abaixo volta em modo normal/privilegiado. Essa diferença, junto de
# BASH_VERSION, detecta a reentrada sem reservar ou consumir um argumento do
# usuário.
# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) ---------
# Nome do serviço (o `service` que /health devolve, o usuário de runtime e o
# diretório em /var) e prefixo das variáveis de ambiente deste bot.
bot="dealnibot"
prefixo="DEALNI"
# --- FIM DOS DADOS; daqui para baixo o código é canônico ---------------------

reentrada=0
if [ -n "${BASH_VERSION:-}" ] && ! shopt -q -o posix 2>/dev/null; then
    reentrada=1
fi
if [ "$reentrada" -eq 0 ]; then
    # As quatro sobrescritas chegam com o prefixo do bot e atravessam o `env -i`
    # com nomes fixos: o corpo abaixo lê só os fixos.
    eval "monitor_base_url=\"\${${prefixo}_PUBLIC_BASE_URL:-}\""
    eval "monitor_state_dir=\"\${${prefixo}_MONITOR_STATE_DIR:-}\""
    eval "monitor_release_file=\"\${${prefixo}_RELEASE_FILE:-}\""
    eval "monitor_alert_interval=\"\${${prefixo}_MONITOR_ALERT_INTERVAL_SECONDS:-}\""
    # shellcheck disable=SC2154 # as quatro variáveis vêm dos eval acima.
    exec /usr/bin/env -i \
        PATH='/usr/local/sbin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' \
        HOME="${HOME:-}" \
        USER="${USER:-}" \
        LOGNAME="${LOGNAME:-}" \
        SUDO_USER="${SUDO_USER:-}" \
        MONITOR_PUBLIC_BASE_URL="$monitor_base_url" \
        MONITOR_STATE_DIR="$monitor_state_dir" \
        MONITOR_RELEASE_FILE="$monitor_release_file" \
        MONITOR_ALERT_INTERVAL_SECONDS="$monitor_alert_interval" \
        /bin/bash -p "$0" "$@" || exit 126
fi

# Uma única sonda acionável para o mantenedor único. O cron envia stderr por
# e-mail; falhas repetidas são lembradas no máximo uma vez por hora e a
# recuperação também é avisada. Nenhum token ou payload do Telegram é lido.

set -euo pipefail

# Cada valor vem do nome fixo (entregue pelo shim) ou, quando o script é
# chamado direto por um Bash já em modo normal, do nome prefixado do bot
# (expansão indireta: `${!nome}`).
nome="${prefixo}_PUBLIC_BASE_URL"
base_url="${MONITOR_PUBLIC_BASE_URL:-${!nome:-}}"
nome="${prefixo}_MONITOR_STATE_DIR"
state_dir="${MONITOR_STATE_DIR:-${!nome:-/var/lib/$bot}}"
nome="${prefixo}_RELEASE_FILE"
release_file="${MONITOR_RELEASE_FILE:-${!nome:-/var/www/$bot/.release.json}}"
nome="${prefixo}_MONITOR_ALERT_INTERVAL_SECONDS"
alert_interval="${MONITOR_ALERT_INTERVAL_SECONDS:-${!nome:-3600}}"
state_file="$state_dir/monitor-saude.estado"

if [[ ! "$base_url" =~ ^https://[^/]+$ ]]; then
    echo "${prefixo}_PUBLIC_BASE_URL precisa ser uma origem HTTPS sem caminho." >&2
    exit 2
fi
if [[ ! "$alert_interval" =~ ^[1-9][0-9]*$ ]]; then
    echo "${prefixo}_MONITOR_ALERT_INTERVAL_SECONDS precisa ser inteiro positivo." >&2
    exit 2
fi
if [[ -L "$release_file" ]]; then
    echo "${prefixo}_RELEASE_FILE não pode ser symlink: $release_file" >&2
    exit 2
fi

mkdir -p "$state_dir"
chmod 0700 "$state_dir" 2>/dev/null || true

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

problemas=()
consultar() {
    local rota="$1"
    local esperado="$2"
    local corpo="$tmp_dir/${rota}.json"
    local status codigo_curl=0
    status="$(curl --silent --show-error --max-time 10 \
        --output "$corpo" --write-out '%{http_code}' "$base_url/$rota" 2>"$tmp_dir/${rota}.erro")" || codigo_curl=$?
    if ((codigo_curl != 0)); then
        problemas+=("/$rota falhou no transporte (curl $codigo_curl, HTTP ${status:-000})")
        return
    fi
    if [[ "$status" != "200" ]]; then
        problemas+=("/$rota respondeu HTTP ${status:-000}")
        return
    fi
    # $argv pertence ao PHP e não deve ser expandido pelo shell.
    # shellcheck disable=SC2016
    if ! php -r '
        $dados = json_decode((string) file_get_contents($argv[1]), true);
        exit(is_array($dados)
            && ($dados["service"] ?? null) === $argv[3]
            && ($dados["status"] ?? null) === $argv[2] ? 0 : 1);
    ' "$corpo" "$esperado" "$bot"; then
        problemas+=("/$rota devolveu JSON sem service=$bot e status=$esperado")
    fi
}

consultar health ok
consultar ready ok

if [[ -f "$release_file" && -f "$tmp_dir/health.json" ]]; then
    # shellcheck disable=SC2016
    if ! php -r '
        $saude = json_decode((string) file_get_contents($argv[1]), true);
        $release = json_decode((string) file_get_contents($argv[2]), true);
        $versao = is_array($saude) ? trim((string) ($saude["version"] ?? "")) : "";
        $esperada = "";
        if (is_array($release)) {
            $tag = trim((string) ($release["tag"] ?? ""));
            $curto = trim((string) ($release["commit_curto"] ?? ""));
            $commit = trim((string) ($release["commit"] ?? ""));
            if ($tag !== "") {
                $esperada = $tag;
            } elseif (preg_match("/^[0-9a-f]{7,64}$/i", $curto) === 1) {
                $esperada = $curto;
            } elseif ($commit !== "") {
                $esperada = substr($commit, 0, 7);
            }
        }
        $servico = is_array($saude) ? ($saude["service"] ?? null) : null;
        exit($servico === $argv[3] && $versao !== "" && $esperada !== ""
            && $versao === $esperada ? 0 : 1);
    ' "$tmp_dir/health.json" "$release_file" "$bot"; then
        problemas+=("/health não corresponde ao commit do carimbo de publicação")
    fi
fi

agora="$(date +%s)"
estado_anterior=""
ultimo_alerta=0
if [[ -r "$state_file" ]]; then
    read -r estado_anterior ultimo_alerta < "$state_file" || true
fi

if ((${#problemas[@]} == 0)); then
    printf 'ok %s\n' "$agora" > "$state_file"
    chmod 0600 "$state_file"
    if [[ "$estado_anterior" == "falha" ]]; then
        echo "RECUPERADO $bot: /health e /ready voltaram a responder." >&2
    fi
    exit 0
fi

if [[ "$estado_anterior" != "falha" ]] || ((agora - ultimo_alerta >= alert_interval)); then
    ultimo_alerta="$agora"
    printf 'ALERTA %s: %s\n' "$bot" "$(IFS='; '; echo "${problemas[*]}")" >&2
fi
printf 'falha %s\n' "$ultimo_alerta" > "$state_file"
chmod 0600 "$state_file"
exit 1
