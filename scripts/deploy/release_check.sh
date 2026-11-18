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
        /bin/bash -p "$0" "$@" || exit 126
fi

# Prontidão para publicar (`composer release:check`).
#
# Não substitui `composer check:all` (tipos, estilo, testes e portões locais)
# nem `composer check:ci-local` (inclui auditorias online). Verifica se a publicação teria um
# IDENTIFICADOR, e se o que seria publicado é exatamente o que está gravado.
# Um deploy sem commit resolvível, com árvore suja ou com `composer.lock`
# defasado produz um artefato que ninguém consegue nomear depois — e o que não
# se nomeia não se verifica nem se reverte.
#
# Roda na máquina de quem publica, antes de subir para o host. Só lê.

set -euo pipefail

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
bot_slug="dealnibot"
branch_de_release="main"
# --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"

# O Git exporta variáveis locais do repositório para os hooks, e quem chama
# também pode deixá-las no ambiente. `-C` sozinho não neutraliza GIT_DIR nem um
# índice/depósito de objetos alternativo, logo identidade da release e árvore
# limpa precisam vir sempre deste checkout.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY \
    GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_COMMON_DIR GIT_NAMESPACE \
    GIT_CEILING_DIRECTORIES GIT_PREFIX

permitir_sujo=0
permitir_branch=0

for argumento in "$@"; do
    case "$argumento" in
        --permitir-arvore-suja) permitir_sujo=1 ;;
        --permitir-outra-branch) permitir_branch=1 ;;
        -h|--help)
            cat >&2 <<USO
Uso: composer release:check -- [opções]

  --permitir-arvore-suja    reporta a árvore suja sem reprovar (ensaio local)
  --permitir-outra-branch   reporta branch diferente de $branch_de_release sem reprovar
USO
            exit 0
            ;;
        *) echo "Opção desconhecida: $argumento" >&2; exit 2 ;;
    esac
done

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

echo "Prontidão de release — $bot_slug"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "Não é um checkout Git; não há identificador de release para conferir." >&2
    exit 1
fi
checkout_root="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [[ "$checkout_root" != "$project_dir" ]]; then
    echo "O diretório do projeto não é um checkout Git próprio: $checkout_root" >&2
    echo "Não se usa o histórico de um repositório-pai para identificar release." >&2
    exit 1
fi

commit="$(git rev-parse --verify HEAD)"
commit_curto="$(git rev-parse --verify --short HEAD)"
branch="$(git branch --show-current)"
referencia="$(git describe --tags --always --dirty)"

echo "  commit:      $commit"
echo "  abreviado:   $commit_curto"
echo "  branch:      ${branch:-<detached>}"
echo "  referência:  $referencia"

if [[ -n "$(git --no-optional-locks status --porcelain=v1 --untracked-files=normal)" ]]; then
    if ((permitir_sujo == 1)); then
        avisar "árvore suja (tolerado por --permitir-arvore-suja); o deploy no host recusaria"
    else
        reprovar "árvore suja: o commit publicado não descreveria o código publicado"
    fi
else
    aprovar "árvore limpa"
fi

if [[ "$branch" != "$branch_de_release" ]]; then
    if ((permitir_branch == 1)); then
        avisar "branch é '${branch:-<detached>}' e não '$branch_de_release' (tolerado)"
    else
        reprovar "branch é '${branch:-<detached>}'; o deploy no host só aceita '$branch_de_release'"
    fi
else
    aprovar "branch de release ($branch_de_release)"
fi

# Tag é opcional aqui de propósito: estes bots são serviços continuamente
# implantados, sem pacote nem binário publicado, e o par (commit, carimbo de
# publicação) já identifica a publicação. Quando existir tag apontando para o
# HEAD, TODAS elas precisam ser SemVer com prefixo `v` — senão vira dois
# esquemas de versão convivendo. Não escolha uma tag com `head -n 1`: um commit
# pode ter várias tags, e aceitar apenas a primeira tornaria o resultado
# dependente da ordenação do Git e deixaria uma tag inválida passar
# despercebida. A listagem em si também pode falhar, e falha de listagem não é
# ausência de tag: nesse caso reprova em vez de aprovar por silêncio.
tags_no_head=()
tags_listadas=0
if tags_do_head="$(git tag --points-at HEAD)"; then
    tags_listadas=1
    while IFS= read -r tag_no_head; do
        [[ -n "$tag_no_head" ]] && tags_no_head+=("$tag_no_head")
    done <<< "$tags_do_head"
else
    reprovar "não foi possível listar as tags apontadas pelo HEAD"
fi

if ((tags_listadas == 1 && ${#tags_no_head[@]} == 0)); then
    aprovar "sem tag no HEAD; a publicação é identificada por SHA (serviço continuamente implantado)"
elif ((tags_listadas == 1)); then
    tags_invalidas=()
    for tag_no_head in "${tags_no_head[@]}"; do
        if ! [[ "$tag_no_head" =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$ ]]; then
            tags_invalidas+=("$tag_no_head")
        fi
    done
    if ((${#tags_invalidas[@]} > 0)); then
        reprovar "tag(s) no HEAD fora de SemVer com prefixo v: ${tags_invalidas[*]}"
    elif ((${#tags_no_head[@]} > 1)); then
        reprovar "mais de uma tag SemVer aponta para o HEAD: ${tags_no_head[*]} (a publicação ficaria ambígua)"
    else
        aprovar "tag SemVer no HEAD: ${tags_no_head[0]}"
    fi
fi

# Uma chamada confere manifesto e lock pelo código de saída do Composer.
if composer validate --strict --check-lock --no-check-publish --no-interaction; then
    aprovar "composer.json e composer.lock válidos (--strict --check-lock)"
else
    reprovar "composer validate --strict --check-lock reprovou"
fi

if [[ -d vendor ]]; then
    if composer check-platform-reqs --no-interaction >/dev/null 2>&1; then
        aprovar "requisitos de plataforma satisfeitos pelo runtime local"
    else
        reprovar "composer check-platform-reqs reprovou no runtime local"
    fi
else
    avisar "vendor/ ausente: requisitos de plataforma não foram conferidos aqui"
fi

if [[ -f .env.example ]]; then
    aprovar ".env.example presente (contrato do ambiente do host)"
else
    reprovar ".env.example ausente: o host não teria contrato de ambiente"
fi

if [[ -f database/schema.sql ]]; then
    aprovar "database/schema.sql presente (contrato de tabelas do db:restore:test)"
else
    reprovar "database/schema.sql ausente: a restauração não teria contra o que conferir"
fi

echo
if ((problemas > 0)); then
    echo "Release NÃO liberado: $problemas problema(s)." >&2
    exit 1
fi

echo "Release liberado para publicação: $commit_curto ($referencia)."
echo "Falta ainda, e nada aqui substitui: composer check:all verde e, no host,"
echo "como root, DEPLOY_CONFIRM=$bot_slug bash scripts/deploy/publicar.sh --esperado=$commit."
