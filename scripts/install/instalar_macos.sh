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

# Script para instalar as dependencias do bot no macOS
#
# `set -euo pipefail` não é enfeite: sem ele um brew, um pecl ou um composer que
# falhasse no meio deixava o script seguir até o "Instalação concluída!" final,
# que anunciava sucesso sobre uma máquina pela metade. Onde a falha é tolerável
# de propósito, o comando traz um `|| true` à vista.

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"

echo "Iniciando instalação das dependências necessárias para o bot em PHP no macOS..."
echo "Projeto: ${project_dir}"

# Verificar se o Homebrew está instalado
if ! command -v brew > /dev/null 2>&1; then
    echo "Homebrew não encontrado. Instalando Homebrew..."
    # Baixar para arquivo e conferir que veio algo, em vez de canalizar o corpo
    # da resposta direto para o bash: com `set -o pipefail` um curl que falhasse
    # no meio ainda deixava um `bash -c ""` sair com 0 e anunciar sucesso.
    instalador_brew="$(mktemp)"
    trap 'rm -f "$instalador_brew"' EXIT
    curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh \
        -o "$instalador_brew"
    /bin/bash "$instalador_brew"
    rm -f "$instalador_brew"
    trap - EXIT

    # O instalador não mexe no PATH da sessão atual: em Apple Silicon o brew fica
    # em /opt/homebrew, que não está no PATH padrão, e o resto do script morria
    # com "command not found" logo depois de instalar.
    for candidato in /opt/homebrew/bin/brew /usr/local/bin/brew; do
        if [ -x "$candidato" ]; then
            eval "$("$candidato" shellenv)"
            break
        fi
    done

    if ! command -v brew > /dev/null 2>&1; then
        echo "❌ Homebrew instalado mas fora do PATH." >&2
        exit 1
    fi
    echo "Homebrew instalado com sucesso!"
else
    echo "Homebrew já está instalado."
fi

# Atualização do sistema
echo "Atualizando Homebrew..."
brew update > /dev/null
echo "Atualização do Homebrew concluída!"

# Instalando dependências básicas do sistema. A fórmula `php@8.5` do Homebrew mantém a
# linha 8.5, o ÚNICO runtime suportado por este projeto
# (composer.json exige ">=8.5.12 <8.6").
echo "Instalando dependências básicas do sistema..."
# `node@24`, e não a fórmula `node`: o package.json exige ">=24.22.1 <25" e a
# fórmula sem sufixo já está na linha 26, o que faria o `npm ci` mais abaixo
# reprovar por engine. Ela é keg-only, daí o PATH explícito na linha seguinte.
# Perfil local de testes: o helper with_postgres.sh seleciona PostgreSQL 17.
# A fórmula versionada não migra nem troca o cluster PostgreSQL 16 da VPS.
brew install php@8.5 postgresql@17 redis screen node@24 > /dev/null
POSTGRES_PREFIX="$(brew --prefix postgresql@17)"
export PATH="$POSTGRES_PREFIX/bin:$PATH"
NODE_PREFIX="$(brew --prefix node@24)"
export PATH="$NODE_PREFIX/bin:$PATH"
echo "Dependências básicas instaladas!"

# Configurando PHP como padrão
echo "Configurando PHP como padrão..."
# `brew link` sai com erro quando a fórmula já está linkada — situação normal na
# segunda execução, e não motivo para abortar a instalação.
brew link --force php@8.5 > /dev/null 2>&1 || true
PHP_PREFIX="$(brew --prefix php@8.5)"
export PATH="$PHP_PREFIX/bin:$PHP_PREFIX/sbin:$PATH"
echo "PHP configurado como padrão!"

# O PATH acima já resolve o php desta sessão. Carregar o ~/.zshrc do usuário
# aqui dentro não resolvia nada e era perigoso: é configuração de zsh lida por
# um bash com `set -u`, então uma variável não definida na config pessoal
# derrubava o instalador.

# Gate de versão: a instalação não pode seguir com um runtime fora da linha 8.5.
if ! php -r 'exit(PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600 ? 0 : 1);' 2>/dev/null; then
    echo "❌ PHP 8.5.12 ou superior na linha 8.5 é obrigatório. Encontrado: $(php --version 2>/dev/null | head -n1)" >&2
    echo "   Instale com: brew install php@8.5 && brew link --overwrite --force php@8.5" >&2
    exit 1
fi
echo "✅ PHP 8.5 confirmado: $(php --version | head -n1)"

# Instalando extensão Redis via PECL
echo "Instalando extensão Redis via PECL..."
if ! php -r "exit(extension_loaded('redis') ? 0 : 1);"; then
    if echo "" | pecl install redis > /dev/null 2>&1; then
        echo "Extensão Redis instalada via PECL!"
    else
        echo "❌ Falha ao instalar a extensão redis via PECL." >&2
        exit 1
    fi
else
    echo "Extensão Redis já está instalada!"
fi

# Verificando as extensões PHP exigidas pelo composer.json.
echo "Verificando extensões PHP necessárias..."
faltando=()
for extensao in curl pdo pdo_pgsql redis json mbstring ctype filter; do
    if php -r "exit(extension_loaded('${extensao}') ? 0 : 1);"; then
        echo "✅ Extensão ${extensao}: OK"
    else
        echo "❌ Extensão ${extensao}: não encontrada"
        faltando+=("$extensao")
    fi
done
if [ ${#faltando[@]} -ne 0 ]; then
    echo "❌ Extensões PHP ausentes: ${faltando[*]}" >&2
    exit 1
fi
echo "Extensões verificadas!"

# Iniciando serviços
echo "Iniciando serviços..."
bash "$project_dir/scripts/install/iniciar_redis_macos.sh"
echo "Redis iniciado com sucesso!"

# Selecionar somente o PHAR fixado, sem abrir o PATH higienizado do instalador.
composer_path="${HOME}/.local/share/composer/2.10.3/composer.phar"
if [[ ! -f "$composer_path" ]]; then
    if ! command -v composer > /dev/null 2>&1; then
        echo "Composer não encontrado. Instalando Composer..."
        brew install composer > /dev/null
    fi
    composer_path="$(command -v composer)"
fi

# O Homebrew pode oferecer outra versão; preservar sua instalação e conferir
# o pin antes de instalar dependências, usando o mesmo PHP selecionado acima.
composer_version="$(php "$composer_path" --no-plugins --no-ansi --version | awk 'NR == 1 {print $3}')"
if [[ "$composer_version" != "2.10.3" ]]; then
    echo "Composer 2.10.3 exigido; encontrado ${composer_version:-desconhecido}. Instale o PHAR oficial verificado em ~/.local/share/composer/2.10.3/composer.phar." >&2
    exit 1
fi

# Configurando horário do sistema (macOS)
echo "Verificando configuração de horário..."
# No macOS o timezone costuma já vir configurado; aqui só reportamos.
current_tz="$(readlink /etc/localtime 2>/dev/null | sed 's#.*/zoneinfo/##' || true)"
echo "Timezone atual: ${current_tz:-desconhecido}"

# Banco PostgreSQL: configure o DSN e aplique composer db:migrate:deploy.

# Instalar as dependências travadas no composer.lock
if [ -f "composer.json" ]; then
    php "$composer_path" install --no-interaction
    php "$composer_path" install -d tools/phpcpd --no-interaction
fi

# Ferramental de commit (lefthook + commitlint). Fica atrás de duas guardas:
# `package.json` porque o pacote é opcional para RODAR o bot, e `.git` porque
# `lefthook install` sai com 128 fora de um worktree — é o caso de quem instala
# a partir de um snapshot baixado em vez de um clone. O `prepare` do
# package.json é quem de fato instala os hooks; aqui só garantimos o `npm ci`.
# `.git` pode ser arquivo em um linked worktree; rev-parse confirma o checkout
# real e também evita instalar hooks num snapshot que apenas vive sob outro repo.
git_checkout_root="$(env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE \
    -u GIT_OBJECT_DIRECTORY -u GIT_ALTERNATE_OBJECT_DIRECTORIES -u GIT_COMMON_DIR \
    -u GIT_NAMESPACE -u GIT_CEILING_DIRECTORIES -u GIT_PREFIX \
    git -C "$project_dir" rev-parse --show-toplevel 2>/dev/null || true)"
if [ -f "package.json" ] && [ "$git_checkout_root" = "$project_dir" ]; then
    echo "Instalando ferramental de commit (lefthook + commitlint)..."
    # shellcheck disable=SC1091 # o helper é resolvido a partir da raiz do checkout.
    source scripts/install/verificar_node_runtime.sh --fix-npm
    npm ci
    echo "Hooks de commit instalados!"
else
    echo "Sem package.json ou fora de um clone Git: hooks de commit ignorados."
fi

# Configurando permissões dos arquivos
echo "Configurando permissões dos arquivos..."
chmod 755 ./*.sh scripts/install/*.sh scripts/quality/*.sh 2>/dev/null || true
chmod 644 ./*.php comandos/*.php 2>/dev/null || true
# Nunca ampliar temporariamente a leitura das credenciais durante reinstalação.
for arquivo_php in inclusos/*.php; do
    [ "$arquivo_php" = "inclusos/config.php" ] && continue
    chmod 644 "$arquivo_php" 2>/dev/null || true
done
chmod 644 ./*.json ./*.txt 2>/dev/null || true
chmod 755 .

# Estado sensível não pode seguir as permissões genéricas acima.
[ ! -f "inclusos/config.php" ] || chmod 600 "inclusos/config.php"
if [ -d "logs" ]; then
    chmod 700 "logs"
    find "logs" -maxdepth 1 -type f -exec chmod 600 {} + 2>/dev/null || true
fi

# Teste final das funcionalidades
echo ""
echo "=========================================="
echo "Testando funcionalidades..."
echo "=========================================="
echo ""

# Testar PHP
if php --version > /dev/null 2>&1; then
    echo "✅ PHP: $(php --version | head -n1)"
else
    echo "❌ PHP: Erro - comando não encontrado"
fi

# Testar PostgreSQL
if psql --version > /dev/null 2>&1; then
    echo "✅ PostgreSQL: $(psql --version)"
else
    echo "❌ PostgreSQL: Erro - comando não encontrado"
fi

# Testar Redis
if [[ "$(redis-cli -h 127.0.0.1 -p 6380 --raw PING 2>/dev/null)" == PONG ]]; then
    echo "✅ Redis: Conectado e funcionando"
else
    echo "❌ Redis: Erro de conexão"
fi

# Mensagem final
echo ""
echo "=========================================="
echo "Instalação concluída no macOS!"
echo "=========================================="
echo ""
echo "Serviços iniciados:"
echo "- Redis exclusivo: 127.0.0.1:6380"
echo ""
echo "Lembre-se de configurar os dados do bot em 'inclusos/dados_bot.php'"
echo ""
