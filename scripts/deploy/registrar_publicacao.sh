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
        /bin/bash -p "$0" "$@" || exit 126
fi

# Grava o carimbo da publicação — o identificador nativo desta plataforma.
#
# Numa VPS o artefato publicado é o próprio diretório, e diretório não tem
# deployment ID como no Vercel nem version ID no Cloudflare. Este arquivo é o
# substituto honesto: registra QUAL commit foi publicado, QUANDO, por QUEM e
# sobre qual `composer.lock`. É o que `scripts/deploy/deploy_verify.sh` confronta com o
# disco e com o instante em que os serviços subiram.
#
# Fica FORA do controle de versão (o .gitignore o cobre) de propósito: se
# entrasse na árvore, cada publicação sujaria o checkout e o próprio deploy
# passaria a recusar a publicação seguinte.
#
# Chamado por deploy.sh e rollback.sh; não é um comando do contrato.
#
# Uso: scripts/deploy/registrar_publicacao.sh <arquivo> <deploy|rollback>

set -euo pipefail

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
expected_dir="/var/www/dealnibot"
runtime_group="dealnibot"
# --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

# O override é usado pelo rollback: o destino pode ser anterior à existência
# deste helper, então ele executa uma cópia temporária capturada antes do switch.
project_dir="${RELEASE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
cd "$project_dir"

# `git -C` escolhe o diretório de trabalho, mas GIT_DIR e as variáveis de
# índice/objetos alternativos continuam sobrepondo a descoberta do repositório.
# O carimbo de publicação precisa atestar ESTE checkout, nunca um repositório
# fornecido pelo ambiente de quem chamou.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY \
    GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_COMMON_DIR GIT_NAMESPACE \
    GIT_CEILING_DIRECTORIES GIT_PREFIX

checkout_root="$(git -C "$project_dir" rev-parse --show-toplevel 2>/dev/null || true)"
if [[ "$checkout_root" != "$project_dir" ]]; then
    echo "O diretório da publicação não é um checkout Git próprio: $checkout_root" >&2
    exit 1
fi

# Um SHA só descreve o conteúdo publicado quando o checkout está limpo.
# A recusa precede inclusive o temporário: um carimbo anterior fica intacto.
if ! arvore_status="$(git --no-optional-locks -C "$project_dir" status --porcelain=v1 --untracked-files=normal)"; then
    echo "Não foi possível provar que a árvore da publicação está limpa." >&2
    exit 1
elif [[ -n "$arvore_status" ]]; then
    echo "Árvore suja: não gravarei um carimbo que apresente HEAD como conteúdo exato." >&2
    exit 1
fi

destino="${1:?arquivo do carimbo obrigatório}"
origem="${2:?origem obrigatória (deploy|rollback)}"

# O carimbo é uma prova do que o checkout publicou. Nunca siga um link
# fornecido no destino, nem transforme um diretório/dispositivo em arquivo.
if [[ -L "$destino" ]]; then
    echo "Destino do carimbo não pode ser symlink: $destino" >&2
    exit 1
fi
if [[ -e "$destino" && ! -f "$destino" ]]; then
    echo "Destino do carimbo não é um arquivo regular: $destino" >&2
    exit 1
fi
destino_dir="$(dirname "$destino")"
if [[ ! -d "$destino_dir" || -L "$destino_dir" ]]; then
    echo "Diretório do carimbo ausente ou symlink: $destino_dir" >&2
    exit 1
fi

# Escrever em um arquivo criado exclusivamente pelo mktemp e renomeá-lo no
# mesmo diretório torna a publicação do JSON atômica. O trap também remove o
# temporário se o PHP, chmod ou mv falhar.
temporario=""
limpar_temporario() {
    if [[ -n "$temporario" ]]; then
        rm -f -- "$temporario"
    fi
}
trap limpar_temporario EXIT
temporario="$(mktemp "${destino}.tmp.XXXXXX")"
chmod 0600 "$temporario"

sha256_de() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

# O commit anterior é lido do carimbo que está sendo substituído: é ele que dá
# destino padrão ao rollback. Sem isso, voltar exigiria alguém lembrar de cor
# qual era o commit de antes — exatamente no momento em que ninguém lembra.
commit="$(git -C "$project_dir" rev-parse HEAD)"
commit_anterior=""
if [[ -f "$destino" ]]; then
    # Republicar o mesmo SHA mantém o último destino diferente. Nunca apague
    # a rota de recuperação apenas por repetir a preparação da publicação.
    # shellcheck disable=SC2016
    commit_anterior="$(php -r '
        $dados = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($dados)) { exit(1); }
        $anterior = ($dados["commit"] ?? null) === $argv[2]
            ? ($dados["commit_anterior"] ?? "") : ($dados["commit"] ?? "");
        if (!is_string($anterior) || ($anterior !== "" && preg_match("/\\A[0-9a-f]{40,64}\\z/", $anterior) !== 1)) { exit(1); }
        echo $anterior;
    ' "$destino" "$commit")"
fi

commit_curto="$(git -C "$project_dir" rev-parse --short HEAD)"
tag="$(git -C "$project_dir" describe --tags --exact-match 2>/dev/null || echo '')"
referencia="$(git -C "$project_dir" describe --tags --always 2>/dev/null || echo "$commit_curto")"
lock_sha="$(sha256_de composer.lock)"
publicado_em="$(php -r 'echo (new DateTimeImmutable("now", new DateTimeZone("UTC")))->format("Y-m-d\\TH:i:s.u\\Z");')"
publicado_por="${SUDO_USER:-$(id -un)}"

# php monta o JSON para nenhum valor precisar de escape manual: nome de tag, de
# host e de usuário passam por aspas e barras sem risco de produzir arquivo
# inválido. O corpo entre aspas simples é código PHP — $argv é variável DO PHP e
# tem de chegar ao interpretador sem expansão do shell.
# shellcheck disable=SC2016
php -r '
    $dados = [
        "bot" => $argv[1],
        "origem" => $argv[2],
        "commit" => $argv[3],
        "commit_curto" => $argv[4],
        "tag" => $argv[5],
        "referencia" => $argv[6],
        "commit_anterior" => $argv[7],
        "checkout" => $argv[8],
        "composer_lock_sha256" => $argv[9],
        "publicado_em" => $argv[10],
        "publicado_por" => $argv[11],
        "host" => (string) gethostname(),
        "php" => PHP_VERSION,
    ];
    $json = json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($argv[12], $json . PHP_EOL) === false) {
        fwrite(STDERR, "Não foi possível gravar o carimbo em " . $argv[12] . PHP_EOL);
        exit(1);
    }
' "$(basename "$project_dir")" "$origem" "$commit" "$commit_curto" "$tag" "$referencia" \
    "$commit_anterior" "$project_dir" "$lock_sha" "$publicado_em" "$publicado_por" "$temporario"

# O rename troca também o inode/dono antigo. Prepare a leitura do FPM em
# CADA publicação, inclusive a primeira e o rollback, antes da troca atômica.
if [[ "$project_dir" == "$expected_dir" ]]; then
    chgrp "$runtime_group" "$temporario"
    chmod 0640 "$temporario"
else
    chmod 0600 "$temporario"
fi
mv -f -- "$temporario" "$destino"
temporario=""
trap - EXIT

echo "Carimbo de publicação gravado em $destino ($origem, commit $commit)."
