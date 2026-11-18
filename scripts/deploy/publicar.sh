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
        DEPLOY_CONFIRM="${DEPLOY_CONFIRM:-}" \
        DEPLOY_SEM_BACKUP="${DEPLOY_SEM_BACKUP:-0}" \
        /bin/bash -p "$0" "$@" || exit 126
fi

# Publicação completa: checkout, dependências, ativação e verificação sob o mesmo lock.
set -euo pipefail
# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
bot_slug="dealnibot"
expected_dir="/var/www/dealnibot"
servicos_extras=()
publicacao_timers=("dealnibot-snapshot-recencia.timer" "dealnibot-backup-local.timer")
publicacao_timers_opcionais=("dealnibot-snapshot-recencia.timer" "dealnibot-backup-local.timer")
publicacao_exige_cron=1
# --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------
project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ferramentas_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -n "${RELEASE_PROJECT_DIR:-}" ]]; then project_dir="$RELEASE_PROJECT_DIR"; fi
if [[ "$project_dir" != /* || ! -d "$project_dir" || -L "$project_dir" ]] \
    || [[ "$(realpath -e -- "$project_dir" 2>/dev/null)" != "$project_dir" ]]; then
    echo "Base de publicação precisa ser um diretório absoluto e canônico." >&2
    exit 1
fi
if [[ "${DEPLOY_CONFIRM:-}" != "$bot_slug" ]]; then
    echo "Publicação não iniciada. No host: DEPLOY_CONFIRM=$bot_slug scripts/deploy/publicar.sh --esperado=SHA" >&2
    exit 2
fi
[[ "$project_dir" == "$expected_dir" && "$#" == 1 && "$1" =~ ^--esperado=[0-9a-fA-F]{40,64}$ ]] || {
    echo 'Publicação exige checkout de produção e --esperado=SHA completo.' >&2; exit 2;
}
cd "$project_dir"
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_COMMON_DIR GIT_NAMESPACE GIT_CEILING_DIRECTORIES GIT_PREFIX
# shellcheck source=scripts/deploy/publicacao_operacao.sh
source "$ferramentas_dir/publicacao_operacao.sh"
publicacao_adquirir_lock "$bot_slug"
[[ "$(git rev-parse --show-toplevel)" == "$project_dir" \
    && -z "$(git --no-optional-locks status --porcelain=v1 --untracked-files=normal)" ]] || {
    echo 'Publicação exige árvore limpa e checkout próprio.' >&2; exit 1;
}
branch_atual="$(git branch --show-current)"
publicacao_apos_rollback=0
if [[ "$branch_atual" != main ]]; then
    if [[ -n "$branch_atual" || ! -f .release.json || -L .release.json ]]; then
        echo 'Publicação exige main ou um detached comprovado pelo carimbo de rollback.' >&2; exit 1;
    fi
    # shellcheck disable=SC2016
    if ! php -r '
        $dados = json_decode((string) file_get_contents($argv[1]), true);
        exit(is_array($dados) && ($dados["origem"] ?? null) === "rollback"
            && ($dados["commit"] ?? null) === $argv[2] && ($dados["checkout"] ?? null) === $argv[3]
            && ($dados["bot"] ?? null) === $argv[4] ? 0 : 1);
    ' .release.json "$(git rev-parse HEAD)" "$project_dir" "$bot_slug"; then
        echo 'Detached sem origem de rollback correspondente ao checkout; publicação recusada.' >&2; exit 1;
    fi
    publicacao_apos_rollback=1
fi
if ((${#servicos_extras[@]} > 0)); then
    publicacao_definir_servicos "$bot_slug" "${servicos_extras[@]}"
else
    publicacao_definir_servicos "$bot_slug"
fi
publicacao_exigir_bundle_disponivel
# Fetch não troca o código servido. Resolva a referência antes de parar o runtime.
git fetch --no-tags origin main
esperado="${1#*=}"
alvo="$(git rev-parse --verify --end-of-options "$esperado^{commit}")"
[[ "$(git rev-parse refs/remotes/origin/main)" == "$alvo" ]] || {
    echo 'O SHA esperado não é o origin/main obtido agora; nada foi publicado.' >&2; exit 1;
}
git merge-base --is-ancestor HEAD "$alvo" || { echo 'Checkout divergiu de origin/main; atualização recusada.' >&2; exit 1; }
if ((publicacao_apos_rollback == 1)); then
    git rev-parse --verify refs/heads/main >/dev/null
    git merge-base --is-ancestor main "$alvo" || { echo 'A main local divergiu do alvo; nada será trocado.' >&2; exit 1; }
fi
# O preparo não recebe ambiente nem segredos do runtime e não toca o vendor servido.
# shellcheck source=scripts/deploy/publicacao_dependencias.sh
source "$ferramentas_dir/publicacao_dependencias.sh"
publicacao_dependencias_dir=""
publicacao_suspensao_iniciada=0
publicacao_finalizar() {
    local codigo=$?
    if ((publicacao_suspensao_iniciada == 1)); then publicacao_ao_sair "$codigo" || true; fi
    publicacao_limpar_dependencias "$bot_slug" "$project_dir" || echo 'Não foi possível limpar o preparo privado; confira o diretório.' >&2
    return "$codigo"
}
trap publicacao_finalizar EXIT
publicacao_preparar_dependencias "$alvo" "$project_dir" "$bot_slug"
# A release atual faz a cópia enquanto o runtime ainda atende. Se falhar,
# nenhuma suspensão, troca de checkout ou alteração do vendor servido começou.
if [[ "${DEPLOY_SEM_BACKUP:-0}" != "1" ]]; then
    bash scripts/deploy/db_backup.sh --rotulo=pre-deploy
fi
publicacao_suspensao_iniciada=1
publicacao_parar_servicos
umask 022
publicacao_criar_bundle "$alvo" "$publicacao_sha_inicial" deploy
if ((publicacao_apos_rollback == 1)); then
    git switch main
    publicacao_mudou=1
fi
git merge --ff-only "$alvo"
publicacao_mudou=1
# Chegamos aqui com backup concluído ou dispensa explícita do operador.
DEPLOY_CONFIRM="$bot_slug" DEPLOY_SEM_BACKUP=1 \
    DEPLOY_DEPENDENCIAS_PREPARADAS="$publicacao_dependencias_dir" scripts/deploy/deploy.sh
publicacao_iniciar_servicos
scripts/deploy/deploy_verify.sh "--esperado=$alvo" --smoke
publicacao_restaurar_agendamentos
publicacao_marcar_bundle concluida
publicacao_concluida=1
echo "Publicação concluída e verificada: $alvo."
