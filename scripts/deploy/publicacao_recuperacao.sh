#!/usr/bin/env bash
# Ferramentas persistentes, sem configuração ou credenciais do runtime.
publicacao_criar_bundle() {
    [[ "$(id -u)" == 0 ]] || return 1
    php "${publicacao_ferramentas_dir:?}/publicacao_bundle.php" criar "${bot_slug:?}" "${project_dir:?}" "$1" "$2" "$3" "$(cd "${publicacao_ferramentas_dir:?}/../.." && pwd -P)"
    export publicacao_bundle_criado=1
}
publicacao_marcar_bundle() {
    [[ "$(id -u)" == 0 ]] || return 1
    php "/var/lib/chatbots-publicacao/${bot_slug:?}.recuperacao/scripts/deploy/publicacao_bundle.php" estado "${bot_slug:?}" "$1"
}
publicacao_validar_retomada() {
    local dados projeto alvo
    dados="$(php "${publicacao_ferramentas_dir:?}/publicacao_bundle.php" validar "${bot_slug:?}")" || return 1
    projeto="$(printf '%s\n' "$dados" | sed -n '1p')"
    alvo="$(printf '%s\n' "$dados" | sed -n '2p')"
    export publicacao_recuperacao_operacao
    publicacao_recuperacao_operacao="$(printf '%s\n' "$dados" | sed -n '4p')"
    [[ "$(git rev-parse HEAD)" == "$alvo" && "$projeto" == "${project_dir:?}" && "$alvo" == "${destino:?}" \
        && "${publicacao_ferramentas_dir:?}" == "/var/lib/chatbots-publicacao/${bot_slug:?}.recuperacao/scripts/deploy" ]] || {
        echo 'Retomada exige o bundle privado, projeto e alvo registrados.' >&2; return 1;
    }
}

publicacao_exigir_bundle_disponivel() {
    php "${publicacao_ferramentas_dir:?}/publicacao_bundle.php" disponivel "${bot_slug:?}" || {
        echo 'Há recuperação pendente ou bundle inválido; use recuperar_publicacao.sh e preserve a suspensão.' >&2
        return 1
    }
}
