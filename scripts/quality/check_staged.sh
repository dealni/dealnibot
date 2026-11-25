#!/usr/bin/env bash

# Valida os arquivos no stage; executado pelo Lefthook no pre-commit.
#
# Três grupos de travas rápidas, nesta ordem:
#
#   1. CAMINHO — arquivos que nunca podem entrar neste repositório. Todos já
#      estão no .gitignore, então só chegam ao índice por `git add -f` ou por
#      terem sido rastreados antes de a regra existir; é exatamente esse caso
#      que a trava pega. Não depende de ferramenta externa: roda sempre.
#      A lista vem em NUL (`git diff -z`) para que nomes com espaço, newline ou
#      aspas não sejam reinterpretados pelo shell.
#
#   2. SEGREDO — gitleaks sobre o que está no ÍNDICE (`--staged`). Sem gitleaks
#      instalado o hook REPROVA: um pre-commit que aprova por ausência de
#      ferramenta ensina que o verde aqui não significa nada, e o segredo já
#      commitado não sai mais do histórico com um `git restore`. Instalar é uma
#      linha; ver a mensagem da própria trava.
#
#   3. SINTAXE — PHP e shell alterados no índice, mais os gates de Composer e
#      de links locais, e só quando um arquivo do assunto foi alterado.
#
# Os portões de workflow e de composite actions rodam no CI e também podem
# ser executados com `composer check:workflows` e `npm run check:composite-actions`.
#
# Não há atalho para pular estas travas: o que o hook apontou se corrige antes
# de commitar.

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
# Cópias do ambiente e exportações do PostgreSQL, conforme o .gitignore.
proibidos_do_bot=('(^|/)\.env\.' '\.dump(\.age)?$')
# --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

set -uo pipefail

# O Git fornece estas variáveis ao invocar um hook; limpá-las faz toda leitura
# abaixo resolver o worktree atual (inclusive um worktree vinculado) e o seu
# índice indicado pelo Git. GIT_INDEX_FILE deve permanecer: commit --only
# e commit <paths> podem fornecer um índice transitório diferente do normal.
unset GIT_DIR GIT_WORK_TREE GIT_OBJECT_DIRECTORY \
    GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_COMMON_DIR GIT_NAMESPACE \
    GIT_CEILING_DIRECTORIES GIT_PREFIX

falhou=0
arquivos=()
while IFS= read -r -d '' arquivo; do
    arquivos+=("$arquivo")
done < <(git diff --cached --name-only --diff-filter=ACMR -z)

removidos=()
while IFS= read -r -d '' arquivo; do
    removidos+=("$arquivo")
done < <(git diff --cached --no-renames --name-only --diff-filter=D -z)

if [ ${#arquivos[@]} -eq 0 ] && [ ${#removidos[@]} -eq 0 ]; then
    exit 0
fi

# --- 1. Caminhos proibidos ---------------------------------------------------
# Mesma divisão usada no .gitignore, incluindo artefatos em subdiretórios para
# que `git add -f` não seja uma escotilha. O `.env` entra aqui porque é o
# arquivo que, por definição, carrega segredo em texto puro.
proibidos=(
    '(^|/)config\.php$'
    '(^|/)\.env$'
    '(^|/)[^/]+\.db($|-wal$|-shm$|-journal$)'
    '(^|/)bot\.db\.backup-'
    '(^|/)logs/'
    '\.(pfx|p12|pem|key|crt)$'
    '(^|/)vendor/'
    '(^|/)node_modules/'
    '(^|/)\.artifacts/'
    '(^|/)nohup\.out$'
    '^snapshots/'
    '^\.release\.json$'
    '(^|/)\.claude/settings\.local\.json$'
)

if [ ${#proibidos_do_bot[@]} -gt 0 ]; then
    proibidos+=("${proibidos_do_bot[@]}")
fi

for padrao in "${proibidos[@]}"; do
    encontrados=()
    for arquivo in ${arquivos[@]+"${arquivos[@]}"}; do
        if [[ "$arquivo" != .env.example && "$arquivo" =~ $padrao ]]; then
            encontrados+=("$arquivo")
        fi
    done
    if [ ${#encontrados[@]} -gt 0 ]; then
        echo "pre-commit: arquivo proibido no índice (padrão: ${padrao})" >&2
        for arquivo in "${encontrados[@]}"; do
            echo "    ${arquivo}" >&2
        done
        falhou=1
    fi
done

if [ "$falhou" -ne 0 ]; then
    echo "pre-commit: tire do índice com 'git restore --staged <arquivo>'." >&2
fi

eh_script_shell_indexado() {
    local arquivo="$1"
    local shebang=""

    case "$arquivo" in
        *.sh) return 0 ;;
    esac
    shebang="$(git show ":${arquivo}" -- | sed -n '1p')" || return 1
    [[ "$shebang" =~ (^|[[:space:]/])(sh|bash|dash|zsh|ksh|ash|busybox)([[:space:]]|$) ]]
}

# --- 2. Segredo no que está sendo commitado ----------------------------------
if command -v gitleaks >/dev/null 2>&1; then
    if ! gitleaks git --staged --redact --no-banner --log-level warn .; then
        echo "pre-commit: o gitleaks encontrou segredo no índice." >&2
        echo "    Falso positivo se resolve com uma allowlist em .gitleaks.toml." >&2
        echo "    .gitleaksignore é outra coisa: só para o que JÁ vazou no histórico." >&2
        falhou=1
    fi
else
    echo "pre-commit: gitleaks não instalado — a varredura de segredo NÃO rodou." >&2
    echo "    Instale antes de commitar: brew install gitleaks (macOS)." >&2
    echo "    Versão usada por este repositório: 8.30.1." >&2
    echo "    No macOS com Apple Silicon o binário fica em /opt/homebrew/bin/gitleaks;" >&2
    echo "    se o comando não for encontrado, verifique se esse caminho está no PATH." >&2
    falhou=1
fi

# --- 3. Sintaxe dos arquivos alterados --------------------------------------
shell_alterado=0
for arquivo in ${arquivos[@]+"${arquivos[@]}"}; do
    case "$arquivo" in
        *.php)
            if ! git show ":${arquivo}" -- | php -l >/dev/null; then
                echo "pre-commit: PHP inválido no índice: ${arquivo}" >&2
                falhou=1
            fi
            ;;
        *)
            if ! eh_script_shell_indexado "$arquivo"; then
                continue
            fi
            if ! git show ":${arquivo}" -- | bash -n; then
                echo "pre-commit: shell inválido no índice: ${arquivo}" >&2
                falhou=1
            fi
            shell_alterado=1
            ;;
    esac
done

# O stdin isolado perde o grafo de `source`. Materialize os scripts do ÍNDICE
# com os caminhos originais e analise juntos, como no check_shell.sh, sem usar
# helpers do working tree. O subshell mantém seu trap separado do chamador.
shellcheck_do_indice() (
    umask 077
    local temporario
    local script
    local scripts=()
    temporario="$(mktemp -d "${TMPDIR:-/tmp}/bot-staged-shell.XXXXXX")" || exit 1
    trap 'rm -rf -- "$temporario"' EXIT
    mkdir -p "$temporario/arvore" || exit 1
    git ls-files --cached -z > "$temporario/lista" || exit 1
    while IFS= read -r -d '' script; do
        if ! eh_script_shell_indexado "$script"; then
            continue
        fi
        mkdir -p "$temporario/arvore/$(dirname "$script")" || exit 1
        git show ":${script}" -- > "$temporario/arvore/$script" || exit 1
        scripts+=("./$script")
    done < "$temporario/lista"
    [ ${#scripts[@]} -gt 0 ] || exit 1
    cd "$temporario/arvore" || exit 1
    env -u SHELLCHECK_OPTS shellcheck --norc --severity=style --color=never "${scripts[@]}"
)

if [ "$shell_alterado" -eq 1 ] && command -v shellcheck >/dev/null 2>&1; then
    if ! shellcheck_do_indice; then
        echo "pre-commit: shellcheck reprovou os scripts do índice." >&2
        falhou=1
    fi
fi

# --- 4. Gates globais rápidos, só quando o assunto foi alterado --------------
tem_arquivo() {
    local padrao="$1"
    for arquivo in ${arquivos[@]+"${arquivos[@]}"} ${removidos[@]+"${removidos[@]}"}; do
        [[ "$arquivo" =~ $padrao ]] && return 0
    done
    return 1
}

# Remover um destino também pode quebrar links sem alterar o Markdown.
conferir_links=0
if tem_arquivo '\.md$' || [ ${#removidos[@]} -gt 0 ]; then
    conferir_links=1
fi

# Composer e links precisam enxergar a mesma árvore que será commitada,
# inclusive em staging parcial ou no índice transitório de `git commit --only`.
# Materialize uma vez, sem stash, checkout ou escrita no índice original.
validar_globais_do_indice() (
    umask 077
    local temporario git_dir indice
    git_dir="$(git rev-parse --absolute-git-dir)" || exit 1
    indice="$(git rev-parse --git-path index)" || exit 1
    case "$indice" in
        /*) ;;
        *) indice="$PWD/$indice" ;;
    esac
    temporario="$(mktemp -d "${TMPDIR:-/tmp}/bot-staged-globals.XXXXXX")" || exit 1
    trap 'rm -rf -- "$temporario"' EXIT
    mkdir -p "$temporario/arvore" || exit 1
    git checkout-index --all --prefix="$temporario/arvore/" || exit 1
    cd "$temporario/arvore" || exit 1
    export GIT_DIR="$git_dir" GIT_WORK_TREE="$temporario/arvore" GIT_INDEX_FILE="$indice"

    local resultado=0
    if tem_arquivo '^composer\.(json|lock)$'; then
        composer validate --strict --check-lock --no-check-publish --no-interaction || resultado=1
    fi
    if tem_arquivo '^tools/phpcpd/composer\.(json|lock)$'; then
        composer validate --working-dir=tools/phpcpd --strict --check-lock --no-check-publish --no-interaction || resultado=1
    fi
    if [ "$conferir_links" -eq 1 ]; then
        php scripts/quality/check_doc_links.php || resultado=1
    fi
    exit "$resultado"
)

if tem_arquivo '(^|/)composer\.(json|lock)$' || [ "$conferir_links" -eq 1 ]; then
    if ! validar_globais_do_indice; then
        echo "pre-commit: Composer ou links inválidos na árvore do índice." >&2
        falhou=1
    fi
fi

exit "$falhou"
