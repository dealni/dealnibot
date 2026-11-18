#!/usr/bin/env bash
# Funções carregadas pelo publicador e pelo rollback.
# shellcheck source=scripts/deploy/publicacao_agendamentos.sh
publicacao_ferramentas_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$publicacao_ferramentas_dir/publicacao_agendamentos.sh"
# shellcheck source=scripts/deploy/publicacao_recuperacao.sh
source "$publicacao_ferramentas_dir/publicacao_recuperacao.sh"

publicacao_lock_identico() {
    local descritor caminho
    descritor="$(stat -Lc '%d:%i' "/proc/$$/fd/9" 2>/dev/null)" || return 1
    caminho="$(stat -c '%d:%i' "$1" 2>/dev/null)" || return 1
    [[ -n "$descritor" && "$descritor" == "$caminho" ]]
}

publicacao_adquirir_lock() {
    local bot="$1" diretorio="${2:-/var/lib/chatbots-publicacao}" arquivo
    [[ "$bot" =~ ^[a-z0-9]+$ && "$(id -u)" == 0 ]] || { echo 'Publicação exige root e nome de bot válido.' >&2; return 1; }
    command -v flock >/dev/null || { echo 'flock é obrigatório para publicar.' >&2; return 1; }
    [[ "$diretorio" == /* && ! -L "$diretorio" ]] || return 1
    if [[ ! -e "$diretorio" ]]; then
        mkdir -m 0700 -- "$diretorio" || return 1
    fi
    [[ -d "$diretorio" && "$(stat -c '%u:%g:%a' "$diretorio")" == '0:0:700' ]] || {
        echo 'Diretório do lock de publicação exige root:root 0700.' >&2; return 1;
    }
    arquivo="$diretorio/$bot.lock"
    [[ ! -L "$arquivo" && ( ! -e "$arquivo" || -f "$arquivo" ) ]] || return 1
    if [[ -e "$arquivo" && "$(stat -c '%u:%g:%a' "$arquivo")" != '0:0:600' ]]; then
        echo 'Lock de publicação exige root:root 0600.' >&2; return 1
    fi
    if ! publicacao_lock_identico "$arquivo"; then
        # O descritor herdado só é reutilizado quando aponta para este inode.
        # Nenhuma variável de ambiente dispensa a aquisição do flock.
        (umask 077; touch -- "$arquivo") || return 1
        exec 9>>"$arquivo" || return 1
    fi
    flock --exclusive --nonblock 9 || { echo 'Outra publicação/rollback está em andamento.' >&2; return 1; }
    if [[ -L "$arquivo" || ! -f "$arquivo" || "$(stat -c '%u:%g:%a' "$arquivo")" != '0:0:600' ]] \
        || ! publicacao_lock_identico "$arquivo"; then
        echo 'Lock de publicação mudou durante a aquisição.' >&2; return 1
    fi
}

publicacao_definir_servicos() {
    local bot="$1" fpm="php8.5-fpm-$1"
    shift
    [[ "$(systemctl show -p LoadState --value "$fpm")" == loaded ]] || {
        echo 'Publicação exige mestre FPM dedicado; o serviço compartilhado não é suportado neste fluxo.' >&2; return 1;
    }
    publicacao_servicos=("$fpm" "$@")
    publicacao_anteriores=()
    publicacao_mudou=0
    publicacao_concluida=0
    publicacao_bundle_criado=0
    publicacao_sha_inicial="$(git rev-parse HEAD)"
    publicacao_preparar_agendamentos "$bot"
}

publicacao_exigir_parados() {
    local servico estado
    publicacao_exigir_agendamentos_suspensos || return 1
    for servico in "${publicacao_servicos[@]}"; do
        estado="$(systemctl show -p ActiveState --value "$servico")" || return 1
        case "$estado" in
            inactive|failed) ;;
            *) echo "Pare $servico antes de preparar o checkout; use scripts/deploy/publicar.sh para o fluxo completo." >&2; return 1 ;;
        esac
    done
}

publicacao_parar_servicos() {
    local servico
    for servico in "${publicacao_servicos[@]}"; do
        if systemctl is-active --quiet "$servico"; then publicacao_anteriores+=("$servico"); fi
    done
    publicacao_suspender_agendamentos
    systemctl stop "${publicacao_servicos[@]}"
    publicacao_drenar_processos
    publicacao_exigir_parados
}

publicacao_iniciar_servicos() {
    systemctl start "${publicacao_servicos[@]}"
}

publicacao_ao_sair() {
    local codigo="${1:-$?}"
    if ((codigo == 0 || publicacao_concluida == 1)); then return; fi
    if ((publicacao_mudou == 0)) \
        && publicacao_pode_restaurar_agendamentos_anteriores \
        && [[ "$(git rev-parse HEAD)" == "$publicacao_sha_inicial" ]] \
        && [[ -z "$(git --no-optional-locks status --porcelain=v1 --untracked-files=normal)" ]]; then
        if ((${#publicacao_anteriores[@]} > 0)); then
            systemctl start "${publicacao_anteriores[@]}" || echo 'Falha ao restaurar os serviços anteriores: intervenção necessária.' >&2
        fi
        publicacao_restaurar_agendamentos || echo 'Falha ao restaurar o cron anterior: intervenção necessária.' >&2
        if ((publicacao_bundle_criado == 1)); then publicacao_marcar_bundle concluida || true; fi
        echo 'Publicação falhou antes de alterar o checkout; disponibilidade anterior restaurada quando possível.' >&2
    else
        publicacao_reter_agendamentos || echo 'Falha ao manter agendamentos suspensos: intervenção imediata necessária.' >&2
        systemctl stop "${publicacao_servicos[@]}" || true
        publicacao_marcar_bundle incompleta || echo 'Não foi possível marcar o bundle; não retome sem conferir o estado.' >&2
        echo 'PUBLICAÇÃO INCOMPLETA: cron suspenso e serviços mantidos parados para evitar código/dependências incompatíveis.' >&2
        echo 'Confira o erro e o estado do checkout; repita publicar.sh ou use rollback.sh com destino explícito conforme docs/OPERACAO.md. O banco não é restaurado automaticamente.' >&2
    fi
    return "$codigo"
}

# Um timer instalado continua existindo depois do switch. Não entregue a ele
# um checkout que perdeu o executável ou desconhece seu agendamento.
publicacao_validar_monitor_snapshot_no_destino() {
    local destino="$2" estado arquivo conteudo timer="$1-snapshot-recencia.timer"
    if ! estado="$(systemctl show -p LoadState --value "$timer")"; then
        echo "Falha ao inspecionar $timer; rollback recusado antes da parada." >&2
        return 1
    fi
    if [[ "$estado" == not-found ]]; then return 0; fi
    [[ "$estado" == loaded ]] || {
        echo "Não foi possível verificar o monitor de snapshots instalado; rollback recusado." >&2
        return 1
    }
    for arquivo in scripts/operacao/verificar_snapshot_recente.php inclusos/funcoes_snapshot_recencia.php; do
        if ! git cat-file -e "$destino:$arquivo" 2>/dev/null; then
            echo "Rollback recusado antes da parada: $timer está instalado e o destino não suporta o monitor de snapshots." >&2
            echo 'Para voltar a essa versão, revise primeiro a desinstalação do monitor conforme docs/DADOS.md; nenhum serviço foi parado.' >&2
            return 1
        fi
    done
    for arquivo in scripts/deploy/deploy.sh scripts/deploy/publicar.sh scripts/deploy/rollback.sh; do
        conteudo="$(git show "$destino:$arquivo" 2>/dev/null)" || return 1
        if [[ "$conteudo" != *"$timer"* ]]; then
            echo "Rollback recusado antes da parada: o destino não inventaria $timer em $arquivo; nenhum serviço foi parado." >&2
            return 1
        fi
    done
}
