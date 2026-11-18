#!/usr/bin/env bash

# Linter dos arquivos que NÃO são PHP e por isso não passam por PHPStan nem por
# php-cs-fixer: os scripts de shell do repositório, rastreados ou apenas não
# ignorados, incluindo os hooks.
#
# O hook entrou para esta lista quando virou bash de verdade — array, regex,
# `set -uo pipefail`. Até então a única verificação que ele tinha era `bash -n`,
# que só pega erro de sintaxe.
#
# Ferramenta ausente reprova: este script faz parte de `composer check` e não
# pode devolver verde sem ter executado todos os linters declarados.

set -uo pipefail

cd "$(dirname "$0")/../.." || exit 2

# Hooks e chamadores podem exportar variáveis locais do Git. O inventário é, de
# propósito, o índice e a árvore de trabalho DESTE checkout, e não um índice ou
# depósito de objetos alternativo.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY \
    GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_COMMON_DIR GIT_NAMESPACE \
    GIT_CEILING_DIRECTORIES GIT_PREFIX

falhou=0

eh_script_shell() {
    local script="$1"
    local shebang=""

    # A extensão cobre o conjunto histórico. O shebang também é necessário:
    # hooks de ferramentas externas são executáveis e não precisam terminar
    # em `.sh`, e sem esta segunda regra ficariam fora do lint.
    # O inventário do Git também contém arquivos excluídos da árvore local.
    [ -f "$script" ] || return 1
    case "$script" in
        *.sh) return 0 ;;
    esac
    IFS= read -r shebang < "$script" || true
    [[ "$shebang" =~ (^|[[:space:]/])(sh|bash|dash|zsh|ksh|ash|busybox)([[:space:]]|$) ]]
}

possui_script() {
    local candidato="$1"
    local existente

    [ ${#scripts[@]} -eq 0 ] && return 1
    for existente in "${scripts[@]}"; do
        if [ "$existente" = "$candidato" ]; then
            return 0
        fi
    done
    return 1
}

# --- shellcheck --------------------------------------------------------------
if command -v shellcheck >/dev/null 2>&1; then
    # `git ls-files` em vez de `find`: combina o índice com arquivos não
    # rastreados e não ignorados, respeitando .gitignore. `-z` mantém nomes com
    # newline/aspas intactos até o array, e o laço `while read` existe no lugar
    # de `mapfile` porque o /bin/bash do macOS é 3.2, e mapfile só chegou no 4.0
    # — com ele o script achava zero arquivo e passava sem verificar nada.
    scripts=()
    while IFS= read -r -d '' script; do
        if eh_script_shell "$script" && ! possui_script "$script"; then
            scripts+=("$script")
        fi
    done < <(git ls-files --cached --others --exclude-standard -z)

    if [ ${#scripts[@]} -eq 0 ]; then
        echo "shellcheck: nenhum script para verificar."
    else
        echo "shellcheck: ${#scripts[@]} arquivo(s)."
        # --severity=style é o nível mais estrito. `--norc` e a remoção de
        # SHELLCHECK_OPTS impedem que configuração pessoal em $HOME mude o
        # resultado: CI e máquina do mantenedor passam pelas mesmas opções, e o
        # dialeto vem do shebang. Achado que for decisão consciente leva um
        # `# shellcheck disable=SCxxxx` com o motivo na linha de cima, no
        # arquivo — visível na revisão, não escondido numa flag aqui.
        if ! env -u SHELLCHECK_OPTS shellcheck --norc --severity=style --color=never "${scripts[@]}"; then
            falhou=1
        fi
    fi
else
    echo "shellcheck não instalado — os .sh NÃO foram verificados." >&2
    echo "    brew install shellcheck (macOS) ou apt install shellcheck." >&2
    falhou=1
fi

exit "$falhou"
