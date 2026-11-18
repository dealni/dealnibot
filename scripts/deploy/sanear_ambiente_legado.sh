#!/usr/bin/env bash

# Remove do EnvironmentFile do host as chaves que já saíram do contrato.
#
# O contrato de ambiente é `config/env.schema.php`, e `check_env.php --file=`
# reprova chave que não está nele. Isso é certo para o arquivo versionado e
# cruel para o host antigo: as variáveis do backup externo que nunca existiu
# continuam em `/etc/.../environment` e travariam a publicação seguinte na
# primeira linha do deploy, antes de qualquer backup ou migration. Este script
# roda ANTES do validador e tira só essas linhas.
#
# O saneamento é deliberadamente conservador: sai apenas a linha cuja chave é
# EXATAMENTE um dos nomes do bloco de dados abaixo — comentário (mesmo citando
# o nome), espaçamento, ordem e qualquer outra chave ficam byte a byte como
# estavam. Sem chave legada no arquivo, nada é escrito: rodar duas vezes é
# inofensivo e a segunda execução não gera cópia nova.

set -euo pipefail

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) ---------
environment_file="/etc/dealnibot/environment"
# Chaves que este repositório JÁ removeu do schema. A lista é fixa e histórica:
# ela descreve hosts antigos, não o contrato atual. Um nome entra aqui no mesmo
# commit em que sai do schema, nunca antes — apagar do host uma chave que ainda
# tem leitor é perder configuração.
CHAVES_LEGADAS=(
    DEALNI_BACKUP_RCLONE_REMOTE
    DEALNI_BACKUP_RCLONE_FOLDER
    DEALNI_BACKUP_RCLONE_MANTER
    DEALNI_BACKUP_OFFSITE_DIR
)
# --- FIM DOS DADOS; daqui para baixo o código é canônico ---------------------

arquivo="$environment_file"
for argumento in "$@"; do
    case "$argumento" in
        --file=*) arquivo="${argumento#*=}" ;;
        *) echo "Uso: sanear_ambiente_legado.sh [--file=/caminho/environment]" >&2; exit 2 ;;
    esac
done
if [[ "$arquivo" != /* ]]; then
    echo "Saneamento exige o caminho absoluto do EnvironmentFile." >&2
    exit 2
fi
if [[ ! -f "$arquivo" || -L "$arquivo" ]]; then
    echo "Saneamento exige arquivo regular sem symlink: $arquivo" >&2
    exit 1
fi

mantidas=()
removidas=()
while IFS= read -r linha || [[ -n "$linha" ]]; do
    nome=""
    # Comentário nunca é atribuição; fora dele, a chave é o identificador à
    # esquerda do primeiro `=`, com o `export` opcional que o validador aceita.
    if [[ ! "$linha" =~ ^[[:space:]]*# ]] \
        && [[ "$linha" =~ ^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*= ]]; then
        nome="${BASH_REMATCH[2]}"
    fi
    legada=0
    for chave in "${CHAVES_LEGADAS[@]}"; do
        if [[ -n "$nome" && "$nome" == "$chave" ]]; then
            legada=1
            break
        fi
    done
    if ((legada)); then
        removidas+=("$nome")
        continue
    fi
    mantidas+=("$linha")
done < "$arquivo"

if ((${#removidas[@]} == 0)); then
    echo "Ambiente sem chave legada; $arquivo não foi alterado."
    exit 0
fi

diretorio="$(dirname "$arquivo")"
if [[ ! -w "$arquivo" || ! -w "$diretorio" ]]; then
    echo "Sem permissão de escrita em $arquivo (dono root, modo 0640)." >&2
    echo "Publique pelo usuário que já prepara o ambiente FPM, ou apague à mão as linhas:" >&2
    echo "  ${CHAVES_LEGADAS[*]}" >&2
    exit 1
fi

copia="$arquivo.legado-$(date -u +%Y%m%d%H%M%S)"
if [[ -e "$copia" || -L "$copia" ]]; then
    echo "Cópia de segurança já existe: $copia" >&2
    exit 1
fi
# `cp -p` leva modo e dono do original para a cópia e para o temporário; a
# troca atômica no fim preserva as permissões do arquivo que o host já tinha.
cp -p -- "$arquivo" "$copia"
temporario="$(mktemp "$diretorio/.environment-saneado.XXXXXX")"
trap 'rm -f -- "$temporario"' EXIT
cp -p -- "$arquivo" "$temporario"
if ((${#mantidas[@]} == 0)); then
    : > "$temporario"
else
    printf '%s\n' "${mantidas[@]}" > "$temporario"
fi
mv -f -- "$temporario" "$arquivo"
trap - EXIT

echo "Chaves legadas removidas de $arquivo: ${removidas[*]}."
echo "Conteúdo anterior preservado em $copia."
