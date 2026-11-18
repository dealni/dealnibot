#!/usr/bin/env bash
# Suspensão por bot funciona inclusive antes da primeira versão com este helper.

publicacao_cron_relacionado() {
    local conteudo
    conteudo="$(sed '/^[[:space:]]*#/d; /^[[:space:]]*$/d' "$1")" || return 2
    [[ -n "$conteudo" ]] || return 1
    if [[ "$1" == "/var/spool/cron/crontabs/${publicacao_bot:?Bot obrigatório}" ]]; then return 0; fi
    if printf '%s\n' "$conteudo" | grep -Fq -- "${project_dir:?Checkout obrigatório}"; then return 0; fi
    # A conta do bot e caminhos relativos também contam como referência.
    printf '%s\n' "$conteudo" | grep -Eq "(^|[[:space:]/])${publicacao_bot:?Bot obrigatório}([[:space:]/]|$)"
}

publicacao_inspecionar_crons() {
    local arquivo codigo
    for arquivo in /etc/crontab /etc/cron.d/* /var/spool/cron/crontabs/* \
        /etc/cron.hourly/* /etc/cron.daily/* /etc/cron.weekly/* /etc/cron.monthly/*; do
        [[ -f "$arquivo" ]] || continue
        [[ "$arquivo" != "$publicacao_cron_original" ]] || continue
        codigo=0
        publicacao_cron_relacionado "$arquivo" || codigo=$?
        if [[ "$codigo" == 0 ]]; then
            echo "Agendamento extra do bot não coberto: $arquivo. Publicação recusada." >&2
            return 1
        elif [[ "$codigo" != 1 ]]; then
            echo "Não foi possível inspecionar o agendamento: $arquivo." >&2; return 1
        fi
    done
}

publicacao_timer_permitido() {
    local timer
    if ((${#publicacao_timers[@]} > 0)); then
        for timer in "${publicacao_timers[@]}"; do [[ "$timer" != "$1" ]] || return 0; done
    fi
    return 1
}

publicacao_timer_opcional() {
    local timer
    if ((${#publicacao_timers_opcionais[@]} > 0)); then
        for timer in "${publicacao_timers_opcionais[@]}"; do [[ "$timer" != "$1" ]] || return 0; done
    fi
    return 1
}

publicacao_selecionar_timers_instalados() {
    local timer estado codigo erros=0
    publicacao_timers_instalados=()
    if ((${#publicacao_timers[@]} > 0)); then
        for timer in "${publicacao_timers[@]}"; do
            codigo=0
            estado="$(systemctl show -p LoadState --value "$timer")" || codigo=$?
            if [[ "$estado" == loaded && "$codigo" == 0 ]]; then
                publicacao_timers_instalados+=("$timer")
            elif [[ "$estado" != not-found ]] || ! publicacao_timer_opcional "$timer"; then
                echo "Timer obrigatório ausente ou não verificável: $timer." >&2
                erros=1
            fi
        done
    fi
    return "$erros"
}

publicacao_parar_timers() {
    local codigo=0
    publicacao_selecionar_timers_instalados || codigo=$?
    if ((${#publicacao_timers_instalados[@]} > 0)); then
        systemctl stop "${publicacao_timers_instalados[@]}" || return 1
    fi
    return "$codigo"
}

publicacao_inspecionar_timers() {
    local listagem timer _restante alvos unidade comando diretorio usuario
    listagem="$(systemctl list-units --all --type=timer --no-legend --plain --no-pager)" || return 1
    while read -r timer _restante; do
        [[ -n "$timer" ]] || continue
        alvos="$(systemctl show -p Triggers --value "$timer")" || return 1
        for unidade in $alvos; do
            comando="$(systemctl show -p ExecStart --value "$unidade")" || return 1
            diretorio="$(systemctl show -p WorkingDirectory --value "$unidade")" || return 1
            usuario="$(systemctl show -p User --value "$unidade")" || return 1
            if [[ "$usuario" == "${publicacao_bot:?Bot obrigatório}" || "$comando" == *"${project_dir:?Checkout obrigatório}"* || "$diretorio" == "${project_dir:?Checkout obrigatório}"* ]]; then
                if ! publicacao_timer_permitido "$timer"; then
                    echo "Timer do bot não inventariado: $timer. Publicação recusada." >&2
                    return 1
                fi
            fi
        done
    done <<< "$listagem"
}

publicacao_preparar_agendamentos() {
    local bot="$1"
    publicacao_bot="$bot"
    publicacao_cron_original="/etc/cron.d/$bot"
    publicacao_cron_guardado="/var/lib/chatbots-publicacao/$bot.cron.suspenso"
    publicacao_quiescido="/var/lib/chatbots-publicacao/$bot.quiescido"
    publicacao_agendamentos_ja_suspensos=0
    if [[ -e "$publicacao_cron_guardado" || -e "/var/lib/chatbots-publicacao/$bot.timers.suspensos" ]]; then
        publicacao_agendamentos_ja_suspensos=1
    fi
    publicacao_cron_restaurar=0
    # A lista vem do bloco de dados do publicador/rollback, nunca do ambiente.
    if ! declare -p publicacao_timers >/dev/null 2>&1; then publicacao_timers=(); fi
    if ! declare -p publicacao_timers_opcionais >/dev/null 2>&1; then publicacao_timers_opcionais=(); fi
    publicacao_selecionar_timers_instalados
    publicacao_timers_guardados="/var/lib/chatbots-publicacao/$bot.timers.suspensos"
    publicacao_scanner="/var/lib/chatbots-publicacao/$bot.processos.php"
    cp "${publicacao_ferramentas_dir:?Ferramentas obrigatórias}/publicacao_processos.php" "$publicacao_scanner"
    chmod 0600 "$publicacao_scanner"
    publicacao_inspecionar_crons
    publicacao_inspecionar_timers
    if [[ -L "$publicacao_cron_original" || -L "$publicacao_cron_guardado" ]]; then return 1; fi
    if [[ -f "$publicacao_cron_original" && -f "$publicacao_cron_guardado" ]]; then
        echo 'Cron original e suspenso coexistem; resolva antes de publicar.' >&2; return 1
    fi
    if [[ -f "$publicacao_cron_original" || -f "$publicacao_cron_guardado" ]]; then
        publicacao_cron_restaurar=1
    else
        if [[ "${publicacao_exige_cron:?Contrato do cron obrigatório}" == 1 ]]; then
            echo 'Cron do bot ausente tanto no destino quanto no arquivo suspenso.' >&2; return 1
        fi
    fi
}

publicacao_suspender_timers() {
    local timer
    if [[ ! -e "$publicacao_timers_guardados" ]]; then
        (umask 077; : > "$publicacao_timers_guardados")
        if ((${#publicacao_timers[@]} > 0)); then
            for timer in "${publicacao_timers[@]}"; do
                if systemctl is-active --quiet "$timer"; then printf '%s\n' "$timer" >> "$publicacao_timers_guardados"; fi
            done
        fi
    fi
    [[ ! -L "$publicacao_timers_guardados" && -f "$publicacao_timers_guardados" \
        && "$(stat -c '%u:%g:%a' "$publicacao_timers_guardados")" == '0:0:600' ]] || return 1
    publicacao_parar_timers
}

publicacao_suspender_agendamentos() {
    publicacao_suspender_timers
    rm -f -- "$publicacao_quiescido"
    if [[ -f "$publicacao_cron_original" ]]; then
        [[ "$(stat -c '%u:%g' "$publicacao_cron_original")" == '0:0' ]] || return 1
        mv -- "$publicacao_cron_original" "$publicacao_cron_guardado"
        chmod 0600 "$publicacao_cron_guardado"
    fi
    # cron relê o diretório a cada minuto. A espera também cobre primeira
    # implantação e rollback para código que desconhece qualquer reader lock.
    sleep 65
}

publicacao_contar_processos() {
    php "$publicacao_scanner" "${project_dir:?Checkout obrigatório}"
}

publicacao_drenar_processos() {
    local rodada quantidade
    for ((rodada=0; rodada<60; rodada++)); do
        quantidade="$(publicacao_contar_processos)" || return 1
        if [[ "$quantidade" == 0 ]]; then
            (umask 077; printf '%s\n' "${publicacao_sha_inicial:?SHA inicial obrigatório}" > "$publicacao_quiescido")
            return
        fi
        sleep 1
    done
    echo 'Jobs do bot não drenaram em 60 segundos; nenhum processo foi morto e o checkout não será alterado.' >&2
    return 1
}

publicacao_exigir_agendamentos_suspensos() {
    local timer
    if ((${#publicacao_timers[@]} > 0)); then
        for timer in "${publicacao_timers[@]}"; do
            if systemctl is-active --quiet "$timer"; then echo "Timer $timer ainda ativo." >&2; return 1; fi
        done
    fi
    [[ ! -e "$publicacao_cron_original" && ( "$publicacao_cron_restaurar" == 0 || -f "$publicacao_cron_guardado" ) \
        && -f "$publicacao_quiescido" && ! -L "$publicacao_quiescido" ]] || {
        echo 'O cron precisa ser suspenso e drenado pelo publicador antes do preparo.' >&2; return 1;
    }
    [[ "$(publicacao_contar_processos)" == 0 ]] || { echo 'Ainda existem jobs do bot em execução.' >&2; return 1; }
}

publicacao_restaurar_agendamentos() {
    local timer
    if ((publicacao_cron_restaurar == 1)) && [[ -f "$publicacao_cron_guardado" ]]; then
        [[ ! -e "$publicacao_cron_original" ]] || return 1
        mv -- "$publicacao_cron_guardado" "$publicacao_cron_original"
    fi
    if [[ -f "$publicacao_timers_guardados" ]]; then
        while IFS= read -r timer; do
            [[ -n "$timer" ]] || continue
            publicacao_timer_permitido "$timer" || return 1
            systemctl start "$timer" || return 1
        done < "$publicacao_timers_guardados"
        rm -f -- "$publicacao_timers_guardados"
    fi
    rm -f -- "$publicacao_quiescido"
}

publicacao_reter_agendamentos() {
    if [[ -f "$publicacao_cron_original" && ! -e "$publicacao_cron_guardado" ]]; then
        mv -- "$publicacao_cron_original" "$publicacao_cron_guardado" || return 1
    fi
    publicacao_parar_timers
}

publicacao_pode_restaurar_agendamentos_anteriores() {
    ((publicacao_agendamentos_ja_suspensos == 0))
}
