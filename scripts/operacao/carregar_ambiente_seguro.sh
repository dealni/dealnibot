#!/usr/bin/env bash

# Carrega um EnvironmentFile sem o interpretar como código de shell.
#
# A sintaxe é a documentada por systemd.exec: comentários ocupam uma linha
# inteira, o nome fica à esquerda do primeiro `=`, espaços externos do valor
# são descartados, aspas simples preservam o conteúdo e aspas duplas aceitam
# apenas os escapes especiais do systemd. Em valores sem aspas, `\\` escapa o
# caractere seguinte. Linhas inválidas são recusadas em vez de executadas;
# variável fora do contrato é ignorada com aviso.

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) ---------
# Sobrescritas operacionais do monitorar_saude.sh, que não passam pelo schema
# porque só o cron as lê (vazio onde o bot não tem o monitor por cron).
VARIAVEIS_DO_MONITOR=(DEALNI_MONITOR_STATE_DIR DEALNI_MONITOR_ALERT_INTERVAL_SECONDS)
# Variáveis prefixadas por bot (e credenciais de produto) que o arquivo pode
# trazer: são zeradas antes da leitura para que um valor herdado não
# sobreviva à ausência da chave no arquivo.
VARIAVEIS_DESTE_BOT=(
    DEALNI_DB_DSN DEALNI_DB_USER DEALNI_DB_PASSWORD
    DEALNI_PUBLIC_BASE_URL DEALNI_BOT_USERNAME DEALNI_ALERTA_CHAT_ID
    DEALNI_SNAPSHOTS_DIR DEALNI_SNAPSHOTS_MANTER
    DEALNI_MONITOR_STATE_DIR DEALNI_MONITOR_ALERT_INTERVAL_SECONDS
    DEALNI_RELEASE_FILE DEALNI_LOGS_DIR DEALNI_UPDATES_DIR
    LUNA_OPENROUTER_API_KEY LUNA_OPENROUTER_MODEL OPENROUTER_API_KEY
)
# --- FIM DOS DADOS; daqui para baixo o código é canônico ---------------------

# Variáveis que o EnvironmentFile pode definir: as chaves de
# config/env.schema.php (a fonte única do contrato de ambiente, conferida por
# `composer check:env`) mais as sobrescritas do bloco de dados. A lista é
# derivada do schema em vez de copiada, para que uma variável nova não precise
# ser lembrada aqui — e AmbienteSeguroTest prova que a derivação enxerga toda
# chave do PHP.
variaveis_permitidas_do_ambiente() {
    local schema="${1:-}"

    if [[ -z "$schema" || ! -f "$schema" || -L "$schema" ]]; then
        echo "Schema de ambiente ausente, não regular ou symlink: ${schema:-<vazio>}" >&2
        return 1
    fi
    # Cada chave do schema é um literal `'NOME' => [...]` no início da linha;
    # sed só extrai texto, nada do arquivo é executado.
    sed -nE "s/^[[:space:]]*'([A-Za-z_][A-Za-z0-9_]*)'[[:space:]]*=>.*/\1/p" "$schema"
    if ((${#VARIAVEIS_DO_MONITOR[@]} > 0)); then
        printf '%s\n' "${VARIAVEIS_DO_MONITOR[@]}"
    fi
}

carregar_ambiente_seguro() {
    local arquivo="${1:-}"
    local linha numero=0 nome valor cru
    local decodificado caractere seguinte
    local indice tamanho
    local schema permitidas permitida

    if [[ -z "$arquivo" || ! -r "$arquivo" ]]; then
        echo "EnvironmentFile ausente ou ilegível: ${arquivo:-<vazio>}" >&2
        return 1
    fi

    # O caminho de ferramentas não vem do operador que chamou o wrapper nem
    # do arquivo de ambiente. Assim, até o `sed` usado para decodificar os
    # valores é resolvido por um PATH conhecido.
    # Inclua os prefixos Homebrew conhecidos para manter a portabilidade no
    # macOS Apple Silicon/Intel, sem jamais reutilizar PATH herdado do caller.
    PATH='/usr/local/sbin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
    export PATH

    schema="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/config/env.schema.php"
    permitidas=" "
    while IFS= read -r permitida; do
        permitidas+="$permitida "
    done < <(variaveis_permitidas_do_ambiente "$schema")
    if [[ "$permitidas" == " " ]]; then
        echo "Nenhuma variável permitida lida de $schema; o EnvironmentFile não pode ser interpretado." >&2
        return 1
    fi

    # O arquivo é a fonte de verdade. Não deixe um valor herdado sobreviver à
    # ausência de uma chave opcional do arquivo.
    # `${arr[@]+"${arr[@]}"}` é a forma que sobrevive a `set -u` com array
    # vazio no Bash 3.2 do macOS.
    for nome in \
        ${VARIAVEIS_DESTE_BOT[@]+"${VARIAVEIS_DESTE_BOT[@]}"} \
        PHP85_BIN REDIS_HOST REDIS_PORT REDIS_PASSWORD CHATBOTS_RELEASE_FILE \
        APP_ENV CHATBOTS_TESTING CHATBOTS_NETWORK_DISABLED CHATBOTS_OS_NETWORK_SANDBOX \
        CHATBOTS_SEM_ESPERA BASH_ENV ENV SHELLOPTS BASHOPTS PHPRC PHP_INI_SCAN_DIR \
        LD_PRELOAD LD_LIBRARY_PATH \
        GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY GIT_COMMON_DIR \
        GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_NAMESPACE COMPOSER_HOME COMPOSER_CONFIG \
        COMPOSER_AUTH; do
        unset "$nome" 2>/dev/null || true
    done

    while IFS= read -r linha || [[ -n "$linha" ]]; do
        numero=$((numero + 1))
        # Arquivos administrados em Linux podem ser copiados com CRLF.
        linha="${linha%$'\r'}"

        [[ "$linha" =~ ^[[:space:]]*$ ]] && continue
        if [[ "$linha" =~ ^[[:space:]]*# ]] || [[ "$linha" =~ ^[[:space:]]*';' ]]; then
            continue
        fi
        if [[ ! "$linha" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=(.*)$ ]]; then
            echo "EnvironmentFile inválido na linha $numero: atribuição esperada." >&2
            return 1
        fi

        nome="${BASH_REMATCH[1]}"
        cru="${BASH_REMATCH[2]}"
        # Variável fora do contrato não entra no ambiente (é o que impede
        # BASH_ENV, LD_PRELOAD e afins de virarem injeção), mas também não
        # derruba o job: uma chave extra no arquivo é aviso, não falha fatal
        # de todo o cron.
        if [[ "$permitidas" != *" $nome "* ]]; then
            echo "Aviso: EnvironmentFile linha $numero: variável não prevista ($nome) ignorada." >&2
            unset "$nome" 2>/dev/null || true
            continue
        fi
        # systemd ignora espaços externos do valor antes de decidir se ele é
        # quoted; sed não interpreta nenhum conteúdo da linha.
        valor="$(printf '%s' "$cru" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"

        if [[ "$valor" == "'"* ]]; then
            if (( ${#valor} < 2 )) || [[ "${valor: -1}" != "'" ]]; then
                echo "EnvironmentFile inválido na linha $numero: aspas simples não fechadas." >&2
                return 1
            fi
            valor="${valor:1:${#valor}-2}"
        elif [[ "$valor" == '"'* ]]; then
            if (( ${#valor} < 2 )) || [[ "${valor: -1}" != '"' ]]; then
                echo "EnvironmentFile inválido na linha $numero: aspas duplas não fechadas." >&2
                return 1
            fi
            cru="${valor:1:${#valor}-2}"
            decodificado=""
            indice=0
            tamanho="${#cru}"
            while ((indice < tamanho)); do
                caractere="${cru:indice:1}"
                if [[ "$caractere" == "\\" && $((indice + 1)) -lt tamanho ]]; then
                    seguinte="${cru:indice+1:1}"
                    # shellcheck disable=SC1003
                    case "$seguinte" in
                        '"'|\\|'$'|'`') decodificado+="$seguinte"; ((indice += 2)) ;;
                        *) decodificado+="\\$seguinte"; ((indice += 2)) ;;
                    esac
                else
                    decodificado+="$caractere"
                    ((indice += 1))
                fi
            done
            valor="$decodificado"
        else
            # Em valor não quoted, systemd mantém espaços internos e usa a
            # barra invertida para escapar o caractere seguinte.
            cru="$valor"
            decodificado=""
            indice=0
            tamanho="${#cru}"
            while ((indice < tamanho)); do
                caractere="${cru:indice:1}"
                if [[ "$caractere" == "\\" ]]; then
                    if ((indice + 1 >= tamanho)); then
                        echo "EnvironmentFile inválido na linha $numero: barra invertida final." >&2
                        return 1
                    fi
                    decodificado+="${cru:indice+1:1}"
                    ((indice += 2))
                else
                    decodificado+="$caractere"
                    ((indice += 1))
                fi
            done
            valor="$decodificado"
        fi

        # O nome veio de uma regex de identificador; printf -v e export não
        # fazem expansão de comandos nem reinterpretação do valor.
        printf -v "$nome" '%s' "$valor"
        # shellcheck disable=SC2163
        export "$nome"
    done < "$arquivo"
}
