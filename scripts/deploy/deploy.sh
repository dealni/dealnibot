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
        DEPLOY_CONFIRM="${DEPLOY_CONFIRM:-}" \
        DEPLOY_SEM_BACKUP="${DEPLOY_SEM_BACKUP:-0}" \
        DEPLOY_DEPENDENCIAS_PREPARADAS="${DEPLOY_DEPENDENCIAS_PREPARADAS:-}" \
        /bin/bash -p "$0" "$@" || exit 126
fi

# Publicação no host, como root: `DEPLOY_CONFIRM=<bot> scripts/deploy/deploy.sh`.
# Chame o script direto: como root, o Composer recusa o alias do composer.json.
#
# Prepara o checkout de produção e, no fim, grava o carimbo que identifica a
# publicação. A ordem importa e não é decorativa: o carimbo é escrito por
# ÚLTIMO, quando código e dependências já são os do commit que ele registra —
# gravá-lo antes faria `deploy:verify` aprovar uma publicação que não terminou.
#
# Antes de qualquer coisa, um backup verificado do estado durável: `db:backup`
# faz pg_dump, confere a integridade da CÓPIA e grava a soma ao lado. Um
# backup pré-publicação que só se descobre corrompido na hora de restaurar não
# é backup.
#
# Este script NÃO recarrega nem reinicia serviço: essa ação privilegiada fica
# no runbook de docs/OPERACAO.md. Quem prova que ela aconteceu é
# `scripts/deploy/deploy_verify.sh`, comparando o instante do carimbo com o instante em
# que o PHP-FPM entrou em atividade.

set -euo pipefail

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
expected_dir="/var/www/dealnibot"
environment_file="/etc/dealnibot/environment"
# Grupo dono do EnvironmentFile no host (root:<grupo> 0640).
runtime_group="dealnibot"
nota_final="O deploy refez o fpm.environment: reinicie o PHP-FPM (systemctl restart php8.5-fpm-dealnibot, ou php8.5-fpm onde não há mestre exclusivo). Este bot não tem worker residente."
# Nenhuma credencial de runtime precisa sair do ambiente antes do backup.
credenciais_de_runtime=()
# Scripts PHP que o composer.json liga aos eventos do install; o install roda
# com --no-scripts, então o deploy os chama explicitamente, nesta ordem.
passos_pos_install=()
publicacao_timers=("dealnibot-snapshot-recencia.timer" "dealnibot-backup-local.timer")
publicacao_timers_opcionais=("dealnibot-snapshot-recencia.timer" "dealnibot-backup-local.timer")
publicacao_exige_cron=1
# --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
project_name="$(basename "$project_dir")"
release_file="$project_dir/.release.json"
cd "$project_dir"

environment_file_seguro() {
    local metadados
    [[ -f "$environment_file" && ! -L "$environment_file" ]] || return 1
    [[ ! -L "$(dirname "$environment_file")" ]] || return 1
    metadados="$(stat -c '%U:%G:%a' "$environment_file" 2>/dev/null)" || return 1
    [[ "$metadados" == 'root:root:600' || "$metadados" == "root:${runtime_group}:640" ]]
}

# Um GIT_DIR/GIT_INDEX_FILE herdado de outro checkout não pode redirecionar as
# guardas de branch e de árvore limpa; `-C` apenas troca o diretório.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY \
    GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_COMMON_DIR GIT_NAMESPACE \
    GIT_CEILING_DIRECTORIES GIT_PREFIX

if [[ "${DEPLOY_CONFIRM:-}" != "$project_name" ]]; then
    echo "Deploy não iniciado. No host, confirme explicitamente:" >&2
    echo "  DEPLOY_CONFIRM=$project_name scripts/deploy/deploy.sh" >&2
    exit 2
fi
if [[ "$project_dir" != "$expected_dir" ]]; then
    echo "Checkout inesperado: $project_dir (esperado: $expected_dir)." >&2
    exit 1
fi
checkout_root="$(git -C "$project_dir" rev-parse --show-toplevel 2>/dev/null || true)"
if [[ "$checkout_root" != "$project_dir" ]]; then
    echo "O diretório publicado não é um checkout Git próprio: $checkout_root" >&2
    exit 1
fi
if [[ "$(git --no-optional-locks -C "$project_dir" branch --show-current)" != main ]] \
    || [[ -n "$(git --no-optional-locks -C "$project_dir" status --porcelain=v1 --untracked-files=normal)" ]]; then
    echo "Deploy exige a branch main e árvore de trabalho limpa." >&2
    exit 1
fi
if [[ ! -f inclusos/config.php ]] || [[ ! -f "$environment_file" ]]; then
    echo "Faltam inclusos/config.php ou $environment_file." >&2
    exit 1
fi

# O marcador é estado do host e fica fora do Git. Recuse um caminho que não
# possa ser substituído com segurança antes do backup, das migrations ou do
# Composer; registrar_publicacao.sh repete a guarda no ponto de publicação, mas
# fazê-la somente lá deixaria um deploy parcial quando o marcador fosse um
# symlink, diretório ou outro tipo não regular.
if [[ -L "$release_file" || ( -e "$release_file" && ! -f "$release_file" ) ]]; then
    echo "Carimbo de publicação existente não é um arquivo regular: $release_file" >&2
    echo "Remova ou corrija o caminho manualmente antes do deploy; nada foi alterado." >&2
    exit 1
fi

if ! environment_file_seguro; then
    echo "EnvironmentFile inseguro: use root:root 0600 ou root:$runtime_group 0640, sem symlink: $environment_file" >&2
    exit 1
fi

# O preparo direto só é seguro com o runtime parado. O publicador completo
# adquire este mesmo lock antes de trocar o checkout e o conserva até verificar.
# shellcheck source=scripts/deploy/publicacao_operacao.sh
source scripts/deploy/publicacao_operacao.sh
publicacao_adquirir_lock "$project_name"
publicacao_definir_servicos "$project_name"
publicacao_exigir_parados
if [[ -n "${DEPLOY_DEPENDENCIAS_PREPARADAS:-}" ]]; then
    # Recuse preparo inválido antes de saneamento, backup ou migrations.
    # shellcheck source=scripts/deploy/publicacao_dependencias.sh
    source scripts/deploy/publicacao_dependencias.sh
    publicacao_dependencias_conferir "$DEPLOY_DEPENDENCIAS_PREPARADAS" "$project_dir" "$project_name" "$(git rev-parse HEAD)"
fi

# Host antigo pode ter chaves que saíram do schema; elas reprovariam a
# validação abaixo com "variável sem schema". Removê-las é a primeira escrita
# do deploy, com cópia datada ao lado do original; sem chave legada nada é
# escrito.
bash scripts/deploy/sanear_ambiente_legado.sh "--file=$environment_file"

php scripts/quality/check_env.php "--file=$environment_file"
# O EnvironmentFile também é usado pelo cron. Carregue a sintaxe do systemd
# como dados, nunca como código de shell. O parser exporta cada variável que
# aceita, então não é preciso `set -a` em volta.
# shellcheck disable=SC1091
source scripts/operacao/carregar_ambiente_seguro.sh
carregar_ambiente_seguro "$environment_file"

# Backup, migrations e Composer não precisam das credenciais de runtime deste
# bot. Remova-as antes de iniciar qualquer um deles; DB_DSN e os demais
# parâmetros operacionais do backup permanecem disponíveis.
if [[ ${#credenciais_de_runtime[@]} -gt 0 ]]; then
    unset "${credenciais_de_runtime[@]}"
fi

if [[ "${DEPLOY_SEM_BACKUP:-0}" != "1" ]]; then
    bash scripts/deploy/db_backup.sh --rotulo=pre-deploy
fi

# As migrations pendentes entram ANTES do código novo: `BEGIN IMMEDIATE` espera
# escritores em curso e bloqueia novos até confirmar schema + hashes na mesma
# transação. Banco legado sem livro-razão é recusado em vez de receber SQL
# histórico por cima — ver docs/OPERACAO.md.
php scripts/deploy/migrate.php

# O checkout é do root e o deploy roda como root. COMPOSER_ALLOW_SUPERUSER=1
# só vale com --no-plugins --no-scripts: nenhum código de pacote roda como root.
# Por isso os portões abaixo chamam o PHP direto, e não `composer <script>`.
# DEPLOY_DEPENDENCIAS_PREPARADAS v1
if [[ -n "${DEPLOY_DEPENDENCIAS_PREPARADAS:-}" ]]; then
    # A guarda de runtime parado acima é obrigatória também para consumir o preparo.
    # shellcheck source=scripts/deploy/publicacao_dependencias.sh
    source scripts/deploy/publicacao_dependencias.sh
    publicacao_ativar_dependencias "$DEPLOY_DEPENDENCIAS_PREPARADAS" "$project_dir" "$project_name"
else
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader \
        --no-interaction --no-progress --no-plugins --no-scripts
fi
if [[ ${#passos_pos_install[@]} -gt 0 ]]; then
    for passo in "${passos_pos_install[@]}"; do
        php "$passo"
    done
fi
# O preparo isolado já conferiu a plataforma antes de pausar o runtime.
# Após consumir esse vendor, não repita o mesmo comando no preparo interno.
# A instalação direta ainda precisa conferir a plataforma que acabou de instalar.
if [[ -z "${DEPLOY_DEPENDENCIAS_PREPARADAS:-}" ]]; then
    COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev --no-plugins --no-interaction
fi
php scripts/quality/check_env.php
php scripts/quality/check_production_config.php

DEPLOY_CONFIRM="$project_name" bash scripts/deploy/preparar_fpm.sh

RELEASE_PROJECT_DIR="$project_dir" bash scripts/deploy/registrar_publicacao.sh "$release_file" deploy

echo
echo "Código e dependências preparados; o carimbo está em $release_file."
echo "$nota_final"
echo "Após mudar o ambiente FPM, use restart: reload não reimporta o EnvironmentFile."
echo "Depois do restart indicado acima, prove a publicação com:"
echo "  scripts/deploy/deploy_verify.sh --esperado=\$(git rev-parse HEAD) --smoke"
echo "Se algo estiver errado, o rollback de CÓDIGO tem token próprio:"
echo "  ROLLBACK_CONFIRM=$project_name scripts/deploy/rollback.sh"
