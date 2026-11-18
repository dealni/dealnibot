#!/bin/sh
# shellcheck shell=bash

# /bin/sh é só o launcher portátil. No macOS ele é Bash em modo POSIX; o Bash
# filho abaixo volta em modo normal/privilegiado. Essa diferença, junto de
# BASH_VERSION, detecta a reentrada sem reservar ou consumir um argumento do
# usuário.
reentrada=0
if [ -n "${BASH_VERSION:-}" ] && ! shopt -q -o posix 2>/dev/null; then
    reentrada=1
fi
if [ "$reentrada" -eq 0 ]; then
    exec /usr/bin/env -i \
        PATH='/usr/local/sbin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' \
        HOME="${HOME:-}" \
        USER="${USER:-}" \
        LOGNAME="${LOGNAME:-}" \
        SUDO_USER="${SUDO_USER:-}" \
        RELEASE_PROJECT_DIR="${RELEASE_PROJECT_DIR:-}" \
        /bin/bash -p "$0" "$@" || exit 126
fi

# Prova de que ESTE commit está no ar: `scripts/deploy/deploy_verify.sh`, no host.
# Chame o script direto: como root, o Composer recusa o alias do composer.json.
#
# Aqui não existe deployment ID de plataforma: o identificador nativo de um bot
# em VPS é o par (checkout publicado, processos que o executam). O código é
# servido direto do diretório, então nenhuma das duas metades sozinha responde à
# pergunta — o checkout pode estar no commit novo com o FPM ainda executando o
# opcode antigo, e o FPM pode ter reiniciado por outro motivo qualquer.
#
# Por isso a verificação confronta TRÊS fatos:
#
#   1. o commit gravado no carimbo que `scripts/deploy/deploy.sh` escreveu;
#   2. o HEAD do checkout publicado, com árvore limpa e composer.lock intacto;
#   3. o instante em que cada processo mestre foi iniciado
#      (`ExecMainStartTimestamp` do systemd), que precisa ser POSTERIOR à
#      publicação. O deploy reescreve fpm.environment; só um novo mestre
#      reimporta o EnvironmentFile. Reload preserva o ambiente antigo.
#
# Só responde no host: fora de $expected_dir ele recusa, porque comparar o
# commit da máquina de desenvolvimento com o carimbo de produção não prova nada.
#
# Nada aqui escreve: é leitura, `systemctl show` e um GET no entrypoint PHP público.

set -euo pipefail

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
bot_slug="dealnibot"
expected_dir="/var/www/dealnibot"
environment_file="/etc/dealnibot/environment"
# Grupo dono do EnvironmentFile no host (root:<grupo> 0640).
runtime_group="dealnibot"
env_prefix="DEALNI"
fpm_service="php8.5-fpm"
# Instalações isoladas mantêm o socket do pool e têm mestre próprio.
if [[ -f "/etc/systemd/system/php8.5-fpm-${bot_slug}.service" ]]; then
    fpm_service="php8.5-fpm-${bot_slug}"
fi
public_status="404"
public_location=""
public_path="index.php"
# Rota de liveness. Vazia no bot que não a publica (ver docs/ARQUITETURA.md).
health_path="health"
# Sem worker residente.
servicos_extras=()
worker_screen=""
worker_process_pattern=""
# --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ -n "${RELEASE_PROJECT_DIR:-}" ]]; then project_dir="$RELEASE_PROJECT_DIR"; fi
validador_sonda="$(cd "$(dirname "${BASH_SOURCE[0]}")/../quality" && pwd)/validar_sonda_publicada.php"
if [[ "$project_dir" != /* || ! -d "$project_dir" || -L "$project_dir" ]] \
    || [[ "$(realpath -e -- "$project_dir" 2>/dev/null)" != "$project_dir" ]]; then
    echo "Base da verificação precisa ser um diretório absoluto e canônico." >&2
    exit 1
fi
release_file="$project_dir/.release.json"
cd "$project_dir"

environment_file_seguro() {
    local metadados
    [[ -f "$environment_file" && ! -L "$environment_file" ]] || return 1
    [[ ! -L "$(dirname "$environment_file")" ]] || return 1
    metadados="$(stat -c '%U:%G:%a' "$environment_file" 2>/dev/null)" || return 1
    [[ "$metadados" == 'root:root:600' || "$metadados" == "root:${runtime_group}:640" ]]
}

# `GIT_DIR` e afins podem ter sido exportados por um hook ou pelo shell do
# operador. Limpe-os para que a prova sempre se refira a este checkout e ao
# seu próprio índice/objetos.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY \
    GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_COMMON_DIR GIT_NAMESPACE \
    GIT_CEILING_DIRECTORIES GIT_PREFIX

esperado=""
rodar_smoke=0

for argumento in "$@"; do
    case "$argumento" in
        --esperado=*) esperado="${argumento#*=}" ;;
        --smoke) rodar_smoke=1 ;;
        -h|--help)
            cat >&2 <<USO
Uso: scripts/deploy/deploy_verify.sh [opções]

  --esperado=SHA   exige que a publicação seja exatamente este commit
  --smoke          roda também o smoke de produção, scripts/quality/run_smoke.php (usa serviços reais)
USO
            exit 0
            ;;
        *) echo "Opção desconhecida: $argumento" >&2; exit 2 ;;
    esac
done

if [[ "$project_dir" != "$expected_dir" ]]; then
    echo "deploy:verify só responde no host de produção." >&2
    echo "Checkout inesperado: $project_dir (esperado: $expected_dir)." >&2
    exit 1
fi
checkout_root="$(git -C "$project_dir" rev-parse --show-toplevel 2>/dev/null || true)"
if [[ "$checkout_root" != "$project_dir" ]]; then
    echo "O diretório publicado não é um checkout Git próprio: $checkout_root" >&2
    exit 1
fi

problemas=0
reprovar() {
    echo "  [x] $1" >&2
    problemas=$((problemas + 1))
}
aprovar() {
    echo "  [ok] $1"
}
avisar() {
    echo "  [!] $1"
}

sha256_de() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

https_origin_valida() {
    # Reuse o mesmo contrato de origem HTTPS do runtime, em vez de uma regex
    # que aceitaria query, fragmento, userinfo ou host inválido.
    # shellcheck disable=SC2016
    php -r '
        require "scripts/quality/env_schema.php";
        exit(isHttpsOrigin($argv[1]) ? 0 : 1);
    ' "$1" >/dev/null 2>&1
}

campo_do_carimbo() {
    # $argv é do PHP, não do shell: a aspa simples é o que impede o bash de
    # tentar expandir e o que mantém o trecho legível.
    # shellcheck disable=SC2016
    php -r '
        $dados = json_decode((string) file_get_contents($argv[1]), true);
        echo is_array($dados) && isset($dados[$argv[2]]) && is_scalar($dados[$argv[2]])
            ? (string) $dados[$argv[2]]
            : "";
    ' "$release_file" "$1"
}

echo "Verificação de publicação — $bot_slug"
echo "  checkout: $project_dir"

if [[ -L "$release_file" ]]; then
    echo "  [x] carimbo de publicação não pode ser symlink: $release_file" >&2
    exit 1
fi
if [[ ! -f "$release_file" ]]; then
    echo "  [x] carimbo de publicação ausente: $release_file" >&2
    echo >&2
    echo "Ele é gravado por 'scripts/deploy/deploy.sh'. Sem ele não há identificador nativo" >&2
    echo "para comparar, e afirmar que um commit está no ar seria comparar o HEAD" >&2
    echo "consigo mesmo." >&2
    exit 1
fi

marca_bot="$(campo_do_carimbo bot)"
marca_commit="$(campo_do_carimbo commit)"
marca_checkout="$(campo_do_carimbo checkout)"
marca_data="$(campo_do_carimbo publicado_em)"
marca_lock="$(campo_do_carimbo composer_lock_sha256)"
marca_origem="$(campo_do_carimbo origem)"
marca_tag="$(campo_do_carimbo tag)"
marca_commit_curto="$(campo_do_carimbo commit_curto)"
marca_anterior="$(campo_do_carimbo commit_anterior)"

commit_atual="$(git rev-parse --verify HEAD)"

echo "  publicado em:       ${marca_data:-<sem data>} (origem: ${marca_origem:-<desconhecida>})"
echo "  tag do carimbo:     ${marca_tag:-<sem tag>}"
echo "  commit do carimbo:  ${marca_commit:-<vazio>}"
echo "  commit do checkout: $commit_atual"

if [[ "$marca_bot" != "$bot_slug" ]]; then
    reprovar "o carimbo é do bot '${marca_bot:-<vazio>}', não de $bot_slug"
else
    aprovar "carimbo pertence a $bot_slug"
fi

if [[ "$marca_checkout" != "$project_dir" ]]; then
    reprovar "o carimbo aponta para '$marca_checkout', e estamos em '$project_dir'"
else
    aprovar "carimbo aponta para este checkout"
fi

if [[ "$marca_commit" != "$commit_atual" ]]; then
    reprovar "o diretório publicado mudou de commit depois do deploy (carimbo $marca_commit, disco $commit_atual)"
else
    aprovar "o commit publicado é o commit em disco"
fi

if [[ -n "$esperado" ]]; then
    if [[ ! "$esperado" =~ ^[0-9a-fA-F]{7,64}$ ]]; then
        reprovar "commit esperado deve ser um SHA hexadecimal (mínimo de 7 dígitos): $esperado"
    elif ! esperado_resolvido="$(git rev-parse --verify --end-of-options "$esperado^{commit}" 2>/dev/null)"; then
        reprovar "commit esperado não é uma revisão Git válida: $esperado"
    elif [[ "$commit_atual" == "$esperado_resolvido" ]]; then
        aprovar "publicação corresponde ao commit esperado ($esperado)"
    else
        reprovar "commit esperado $esperado não é o publicado ($commit_atual)"
    fi
fi

if ! arvore_status="$(git --no-optional-locks status --porcelain=v1 --untracked-files=normal)"; then
    reprovar "não foi possível determinar se a árvore do checkout está limpa"
    echo "Verificação interrompida: não carregarei o parser sem uma prova da árvore." >&2
    exit 1
elif [[ -n "$arvore_status" ]]; then
    reprovar "árvore suja: há arquivo alterado no host depois da publicação"
    echo "Verificação interrompida: não carregarei o parser a partir de um checkout alterado." >&2
    exit 1
else
    aprovar "árvore limpa: nada foi editado no host"
fi

if ! environment_file_seguro; then
    reprovar "EnvironmentFile ausente, não regular, symlink ou com dono/permissões inseguros: $environment_file"
    echo "Verificação interrompida: não carregarei o parser nem executarei o smoke." >&2
    exit 1
fi

# Carregue o ambiente depois de provar que o checkout não foi alterado. O
# parser é código do próprio checkout e a função exporta os valores que o
# smoke (PostgreSQL, Redis e integrações opcionais) precisa; fazê-lo numa
# command-substitution deixaria essas variáveis confinadas ao subshell e faria
# `--smoke` rodar com defaults silenciosos. Nenhum valor é impresso aqui.
# shellcheck disable=SC1091
if ! source scripts/operacao/carregar_ambiente_seguro.sh; then
    reprovar "parser seguro ausente ou não pôde ser carregado no checkout"
    exit 1
fi
if ! carregar_ambiente_seguro "$environment_file"; then
    reprovar "$environment_file não pôde ser carregado pelo parser seguro"
    exit 1
fi
if [[ -n "${DEALNI_BOT_USERNAME:-}" ]]; then
    if [[ ! "$DEALNI_BOT_USERNAME" =~ ^[a-zA-Z0-9_]{5,32}$ ]]; then
        reprovar "DEALNI_BOT_USERNAME deve ter de 5 a 32 letras, dígitos ou sublinhados, sem @"
    else
        public_status="302"
        public_location="https://t.me/$DEALNI_BOT_USERNAME"
    fi
fi

php85_bin_suportado() {
    local php_bin="${PHP85_BIN:-}" canonico
    [[ "$php_bin" == /* && -x "$php_bin" && ! -L "$php_bin" ]] || return 1
    canonico="$(realpath -e -- "$php_bin" 2>/dev/null)" || return 1
    [[ "$canonico" == "$php_bin" ]] || return 1
    "$php_bin" -r 'exit(PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600 ? 0 : 1);' 2>/dev/null
}
if ! php85_bin_suportado; then
    reprovar "PHP85_BIN ausente, não canônico ou não aponta para PHP 8.5"
fi

lock_atual="$(sha256_de composer.lock)"
if [[ -n "$marca_lock" && "$marca_lock" != "$lock_atual" ]]; then
    reprovar "composer.lock mudou depois do deploy; vendor/ pode não ser o que foi publicado"
else
    aprovar "composer.lock é o mesmo do deploy"
fi

if [[ -f vendor/composer/installed.php ]]; then
    aprovar "vendor/ instalado"
else
    reprovar "vendor/ ausente: o código publicado não roda"
fi

# Como root, o Composer só roda com COMPOSER_ALLOW_SUPERUSER=1; --no-plugins
# impede que plugin de vendor/ rode como root.
if COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev --no-plugins --no-interaction >/dev/null 2>&1; then
    aprovar "requisitos de plataforma satisfeitos pelo PHP do host"
else
    reprovar "composer check-platform-reqs reprovou no host"
fi

# O deploy reescreve fpm.environment. Reload do PHP-FPM preserva o ambiente
# do mestre, portanto mudança de estado não comprova importação das variáveis.
# ExecMainStartTimestamp exige um processo novo, inclusive para os workers.
# A opção us+utc fixa UTC e microssegundos; ajustes do relógio ainda podem
# inverter a ordem, portanto o host precisa manter sincronização de horário.
servicos=("$fpm_service")
if ((${#servicos_extras[@]} > 0)); then
    servicos+=("${servicos_extras[@]}")
fi
publicado_epoch="$(date -u -d "$marca_data" +%s 2>/dev/null || echo '')"
publicado_epoch_ns="$(date -u -d "$marca_data" +%s%N 2>/dev/null || echo '')"
if command -v systemctl >/dev/null 2>&1; then
    for servico in "${servicos[@]}"; do
        if ! systemctl is-active --quiet "$servico"; then
            reprovar "$servico não está ativo"
            continue
        fi
        mudou_em="$(systemctl show --timestamp=us+utc -p ExecMainStartTimestamp --value "$servico" 2>/dev/null || echo '')"
        mudou_epoch_ns="$(date -u -d "$mudou_em" +%s%N 2>/dev/null || echo '')"
        if [[ -z "$marca_data" || -z "$publicado_epoch_ns" || -z "$mudou_em" || -z "$mudou_epoch_ns" ]]; then
            reprovar "$servico sem timestamp comparável de início do processo (${mudou_em:-desconhecido})"
        elif ((mudou_epoch_ns < publicado_epoch_ns)); then
            reprovar "$servico não iniciou um novo processo desde a publicação (último início: $mudou_em): reinicie-o"
        else
            aprovar "$servico iniciou um novo processo em $mudou_em, depois da publicação"
        fi
    done
else
    reprovar "systemctl indisponível: o reinício dos serviços não pôde ser verificado"
fi

if [[ -n "$worker_process_pattern" ]]; then
    if ! command -v pgrep >/dev/null 2>&1 || ! command -v ps >/dev/null 2>&1; then
        reprovar "pgrep/ps indisponível: o worker não pôde ser verificado"
    else
        pids_worker=()
        while IFS= read -r pid_worker; do
            [[ -n "$pid_worker" ]] && pids_worker+=("$pid_worker")
        done < <(pgrep -f -- "$worker_process_pattern" 2>/dev/null || true)
        worker_label="${worker_screen:-$worker_process_pattern}"
        if ((${#pids_worker[@]} == 0)); then
            reprovar "worker '$worker_label' não encontrado: está parado"
        elif ((${#pids_worker[@]} > 1)); then
            reprovar "worker '$worker_label' está duplicado (${#pids_worker[@]} processos)"
        else
            # ps usa o fuso e o idioma do host, mas date -u interpreta o texto
            # como UTC. Normalize ambos para não envelhecer um processo novo
            # em três horas num host America/Sao_Paulo.
            inicio_worker="$(LC_ALL=C TZ=UTC ps -p "${pids_worker[0]}" -o lstart= 2>/dev/null || echo '')"
            inicio_worker_epoch="$(LC_ALL=C date -u -d "$inicio_worker" +%s 2>/dev/null || echo '')"
            if [[ -z "$publicado_epoch" || -z "$inicio_worker_epoch" ]]; then
                reprovar "worker '$worker_label' existe, mas não foi possível provar quando iniciou"
            elif ((inicio_worker_epoch < publicado_epoch)); then
                reprovar "worker '$worker_label' iniciou em $inicio_worker, ANTES da publicação: reinicie-o"
            else
                aprovar "worker '$worker_label' iniciou em $inicio_worker, depois da publicação"
            fi
        fi
    fi
fi

# Compatibilidade para bot que só consegue provar a sessão. Quando há padrão de
# processo, o PID real acima já cobre tanto screen quanto unit systemd.
if [[ -n "$worker_screen" && -z "$worker_process_pattern" ]]; then
    if command -v screen >/dev/null 2>&1 && screen -ls 2>/dev/null | grep -q "$worker_screen"; then
        aprovar "worker vivo na sessão screen '$worker_screen'"
    else
        reprovar "sessão screen '$worker_screen' não encontrada: o worker está parado"
    fi
fi

if bash scripts/deploy/preparar_fpm.sh --verificar && php scripts/quality/check_fpm_environment.php --host; then
    aprovar "ambiente FPM e pool instalado correspondem ao contrato do bot"
else
    reprovar "prepare o ambiente FPM e instale pool/drop-in conforme docs/OPERACAO.md"
fi

# A superfície pública é a última prova: o processo pode estar de pé e o nginx
# apontando para outro socket. A URL vem do mesmo EnvironmentFile já carregado
# no shell atual, para que o smoke abaixo receba exatamente o mesmo contrato.
public_url_variable="${env_prefix}_PUBLIC_BASE_URL"
public_url="${!public_url_variable:-}"
if [[ -z "$public_url" ]]; then
    reprovar "$public_url_variable não está definida em $environment_file"
elif ! https_origin_valida "$public_url"; then
    reprovar "$public_url_variable precisa ser uma origem HTTPS sem caminho"
else
    public_endpoint="${public_url%/}/${public_path#/}"
    cabecalhos="$(mktemp "${TMPDIR:-/tmp}/$bot_slug-http-XXXXXX")"
    if ! codigo="$(curl -sS -D "$cabecalhos" -o /dev/null -w '%{http_code}' --max-time 10 "$public_endpoint")"; then
        reprovar "o entrypoint público não respondeu ($public_endpoint)"
    elif [[ "$codigo" != "$public_status" ]]; then
        reprovar "o entrypoint público respondeu HTTP $codigo; o contrato exige $public_status"
    else
        location="$(sed -n 's/^[Ll]ocation:[[:space:]]*//p' "$cabecalhos" | tr -d '\r' | tail -n 1)"
        if [[ "$location" != "$public_location" ]]; then
            reprovar "o entrypoint respondeu $codigo com Location '${location:-<vazio>}'; esperado '${public_location:-<vazio>}'"
        else
            aprovar "entrypoint PHP respondeu HTTP $codigo${location:+ e redirecionou para $location}"
        fi
    fi
    rm -f "$cabecalhos"

    # A sonda confirma que o PHP executa o commit que acabou de ser publicado.
    if [[ -z "$health_path" ]]; then
        avisar "este bot não publica /health; a prova de FPM vivo depende do smoke (docs/ARQUITETURA.md)"
    else
        health_endpoint="${public_url%/}/${health_path#/}"
        # Mesma ordem de ContextoDeExecução::versao(): a tag quando a
        # publicação tem nome, senão o commit_curto que o Git gravou (ele
        # pode ter mais de 7 dígitos para permanecer único). Derivar aqui
        # de novo é de propósito — se as duas ordens divergirem, é
        # exatamente isso que esta comparação tem de acusar.
        if [[ -n "$marca_tag" ]]; then
            versao_esperada="$marca_tag"
        elif [[ -n "$marca_commit_curto" ]]; then
            versao_esperada="$marca_commit_curto"
        else
            versao_esperada="${marca_commit:0:7}"
        fi
        health_body="$(mktemp "${TMPDIR:-/tmp}/$bot_slug-health-XXXXXX")"
        if ! health_status="$(curl -sS --output "$health_body" --write-out '%{http_code}' --max-time 10 "$health_endpoint")"; then
            reprovar "/health não respondeu ($health_endpoint) — FPM pode estar fora"
        elif [[ "$health_status" != "200" ]]; then
            reprovar "/health respondeu HTTP $health_status; o contrato exige 200"
        elif php "$validador_sonda" "$health_body" "$bot_slug" "$versao_esperada" health; then
            aprovar "/health respondeu pelo PHP como $bot_slug na versão $versao_esperada"
        else
            reprovar "/health não confirmou identidade, versão e estado da publicação"
        fi
        rm -f "$health_body"
    fi
fi


# Consulte também a prontidão pelo HTTP/FPM: o smoke de CLI pode usar um
# ambiente diferente daquele importado pelo mestre que atende o webhook.
if [[ -n "$public_url" ]] && https_origin_valida "$public_url"; then
    ready_endpoint="${public_url%/}/ready"
    ready_body="$(mktemp "${TMPDIR:-/tmp}/$bot_slug-ready-XXXXXX")"
    if ! ready_status="$(curl -sS --output "$ready_body" --write-out '%{http_code}' --max-time 10 "$ready_endpoint")"; then
        reprovar "/ready não respondeu pelo HTTP/FPM"
    elif [[ "$ready_status" != "200" ]]; then
        reprovar "/ready respondeu HTTP $ready_status; o contrato exige 200"
    elif php "$validador_sonda" "$ready_body" "$bot_slug" "$versao_esperada" ready; then
        aprovar "/ready confirmou a versão publicada, PostgreSQL e Redis pelo HTTP/FPM"
    else
        reprovar "/ready não confirmou identidade, versão e dependências da publicação"
    fi
    rm -f -- "$ready_body"
fi

if ((rodar_smoke == 1)); then
    echo
    echo "Smoke pós-deploy (somente leitura, usa serviços reais):"
    # O mesmo comando de `composer test:smoke`, sem o Composer, que como root
    # recusaria o script.
    if php scripts/quality/run_smoke.php --confirm-production; then
        aprovar "smoke pós-deploy passou"
    else
        reprovar "smoke pós-deploy reprovou"
    fi
else
    avisar "smoke não executado; rode 'scripts/deploy/deploy_verify.sh --smoke' no host"
fi

echo
if ((problemas > 0)); then
    echo "Publicação NÃO verificada: $problemas problema(s)." >&2
    echo "Rollback de CÓDIGO (não toca em dado):" >&2
    echo "  ROLLBACK_CONFIRM=$bot_slug scripts/deploy/rollback.sh${marca_anterior:+ --para=$marca_anterior}" >&2
    exit 1
fi

echo "Publicação verificada: $commit_atual está no ar em $project_dir."
