#!/usr/bin/env bash
# Preparo isolado; só o consumidor, sob lock e com runtime parado, troca vendor.
publicacao_dependencias_validar_diretorio() {
    local diretorio="$1" bot="$2" raiz=/var/lib/chatbots-publicacao
    [[ "$bot" =~ ^[a-z0-9]+$ && "$(id -u)" == 0 \
        && "$diretorio" == "$raiz/$bot.dependencias."* \
        && "$(dirname "$diretorio")" == "$raiz" \
        && -d "$raiz" && ! -L "$raiz" \
        && "$(stat -c '%u:%g:%a' "$raiz")" == '0:0:700' \
        && -d "$diretorio" && ! -L "$diretorio" \
        && "$(realpath -e -- "$diretorio")" == "$diretorio" \
        && "$(stat -c '%u:%g:%a' "$diretorio")" == '0:0:700' ]] || {
        echo 'Preparo de dependências exige diretório privado, canônico e próprio do bot.' >&2; return 1;
    }
}

publicacao_dependencias_conferir() {
    local diretorio="$1" projeto="$2" bot="$3" alvo="$4" lock recibo
    publicacao_dependencias_validar_diretorio "$diretorio" "$bot" || return 1
    [[ "$alvo" =~ ^[0-9a-f]{40,64}$ ]] || return 1
    for recibo in composer.json composer.lock .dependencias-prontas; do
        [[ -f "$diretorio/$recibo" && ! -L "$diretorio/$recibo" ]] || return 1
    done
    [[ "$(stat -c '%u:%g:%a' "$diretorio/.dependencias-prontas")" == '0:0:600' \
        && -d "$diretorio/vendor" && ! -L "$diretorio/vendor" \
        && -f "$diretorio/vendor/autoload.php" \
        && "$(stat -c '%d' "$diretorio")" == "$(stat -c '%d' "$projeto")" ]] || return 1
    lock="$(git -C "$projeto" rev-parse --verify "$alvo:composer.lock")" || return 1
    [[ "$(git -C "$projeto" hash-object --no-filters "$diretorio/composer.lock")" == "$lock" \
        && "$(git -C "$projeto" hash-object --no-filters "$diretorio/composer.json")" == "$(git -C "$projeto" rev-parse --verify "$alvo:composer.json")" \
        && "$(cat "$diretorio/.dependencias-prontas")" == "$(printf '%s\n%s' "$alvo" "$lock")" ]] || {
        echo 'SHA, manifesto ou lock do preparo divergiu; vendor servido foi preservado.' >&2; return 1;
    }
}

publicacao_preparar_dependencias() {
    local alvo="$1" projeto="$2" bot="$3" lock
    # Sem suporte no destino, nunca entre na pausa para depois reinstalar pela rede.
    git -C "$projeto" show "$alvo:scripts/deploy/deploy.sh" | grep '# DEPLOY_DEPENDENCIAS_PREPARADAS v1' >/dev/null || {
        echo 'Destino sem contrato de dependências preparadas; publicação recusada antes da pausa.' >&2; return 1;
    }
    [[ -z "$(git -C "$projeto" ls-tree "$alvo" -- vendor)" ]] || return 1
    publicacao_dependencias_dir="$(mktemp -d "/var/lib/chatbots-publicacao/$bot.dependencias.XXXXXX")" || return 1
    publicacao_dependencias_validar_diretorio "$publicacao_dependencias_dir" "$bot" || return 1
    [[ "$(stat -c '%d' "$publicacao_dependencias_dir")" == "$(stat -c '%d' "$projeto")" ]] || {
        echo 'Preparo e checkout exigem o mesmo filesystem para trocar vendor por rename.' >&2; return 1;
    }
    git -C "$projeto" archive --format=tar "$alvo" | tar -xf - -C "$publicacao_dependencias_dir" || return 1
    [[ ! -e "$publicacao_dependencias_dir/vendor" && ! -L "$publicacao_dependencias_dir/vendor" \
        && ! -e "$publicacao_dependencias_dir/.dependencias-prontas" && ! -L "$publicacao_dependencias_dir/.dependencias-prontas" \
        && -f "$publicacao_dependencias_dir/composer.json" && ! -L "$publicacao_dependencias_dir/composer.json" \
        && -f "$publicacao_dependencias_dir/composer.lock" && ! -L "$publicacao_dependencias_dir/composer.lock" ]] || return 1
    (
        cd "$publicacao_dependencias_dir" || exit 1
        umask 022
        export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_VENDOR_DIR="$publicacao_dependencias_dir/vendor" \
            COMPOSER_BIN_DIR="$publicacao_dependencias_dir/vendor/bin"
        composer validate --no-check-publish --no-plugins --no-interaction \
            && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-plugins --no-scripts \
            && composer check-platform-reqs --no-dev --no-plugins --no-interaction
    ) || return 1
    lock="$(git -C "$projeto" rev-parse --verify "$alvo:composer.lock")" || return 1
    (umask 077; printf '%s\n%s\n' "$alvo" "$lock" > "$publicacao_dependencias_dir/.dependencias-prontas") || return 1
    publicacao_dependencias_conferir "$publicacao_dependencias_dir" "$projeto" "$bot" "$alvo"
}

publicacao_ativar_dependencias() {
    local diretorio="$1" projeto="$2" bot="$3" alvo
    alvo="$(git -C "$projeto" rev-parse HEAD)" || return 1
    publicacao_dependencias_conferir "$diretorio" "$projeto" "$bot" "$alvo" || return 1
    [[ ! -L "$projeto/vendor" && ( ! -e "$projeto/vendor" || -d "$projeto/vendor" ) \
        && ! -e "$diretorio/vendor.anterior" && ! -L "$diretorio/vendor.anterior" ]] || return 1
    if [[ -d "$projeto/vendor" ]]; then
        [[ "$(stat -c '%d' "$projeto/vendor")" == "$(stat -c '%d' "$diretorio")" ]] || return 1
        mv -T -- "$projeto/vendor" "$diretorio/vendor.anterior" || return 1
    fi
    if ! mv -T -- "$diretorio/vendor" "$projeto/vendor"; then
        # Rename no mesmo dispositivo não copia parcialmente; devolva o vendor anterior.
        if [[ -d "$diretorio/vendor.anterior" ]]; then
            mv -T -- "$diretorio/vendor.anterior" "$projeto/vendor" || {
                echo "Falha ao restaurar vendor anterior; preserve $diretorio e mantenha o runtime parado." >&2
                publicacao_dependencias_preservar=1
            }
        fi
        return 1
    fi
}

publicacao_limpar_dependencias() {
    local bot="${1:?}" projeto="${2:?}"
    if [[ -n "${publicacao_dependencias_dir:-}" && "${publicacao_dependencias_preservar:-0}" != 1 ]]; then
        publicacao_dependencias_validar_diretorio "$publicacao_dependencias_dir" "$bot" || return 1
        if [[ -d "$publicacao_dependencias_dir/vendor.anterior" && ! -d "$projeto/vendor" ]]; then
            echo "Vendor anterior retido em $publicacao_dependencias_dir; recuperação manual necessária." >&2
            return 1
        fi
        rm -rf -- "$publicacao_dependencias_dir"
    fi
}
