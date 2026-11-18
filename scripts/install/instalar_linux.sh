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

# Script para instalar as dependencias do bot no Linux
#
# `set -euo pipefail` não é enfeite: sem ele um apt, um composer ou um PostgreSQL
# que falhasse no meio deixava o script seguir até o "Instalação concluída!"
# final, que anunciava sucesso sobre uma máquina pela metade. Onde a falha é
# tolerável de propósito, o comando traz um `|| true` à vista.

set -euo pipefail

fpm_service="php8.5-fpm"
if [[ -f /etc/systemd/system/php8.5-fpm-dealnibot.service ]]; then
    fpm_service="php8.5-fpm-dealnibot"
fi

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"

if [[ "$(id -u)" -eq 0 ]]; then
    sudo_cmd=()
else
    sudo_cmd=(sudo)
fi

verificar_coordenacao_backup() {
  if ! python3 -I - <<'PY_BACKUP_COORDINATION'
import os, stat, sys
try:
    for path, mode in [('/usr/local/libexec/vps-backup-maintenance.py', 0o755),
                       ('/run/lock/vps-backup-maintenance.lock', 0o644)]:
        info = os.lstat(path)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0
                or info.st_gid != 0 or stat.S_IMODE(info.st_mode) != mode
                or info.st_nlink != 1):
            raise ValueError('arquivo sem proteção esperada')
except (OSError, ValueError):
    sys.exit(1)
PY_BACKUP_COORDINATION
  then
    echo 'Instale primeiro a infraestrutura de coordenação de backups: supervisor root:root 0755 e lock regular root:root 0644, sem symlinks.' >&2
    return 1
  fi
}

verificar_coordenacao_backup || exit 1

# Na VPS o runtime pertence exclusivamente ao bot; fora dela usa o usuário local.
runtime_user="${DEALNI_RUNTIME_USER:-}"
if [[ -z "$runtime_user" ]]; then
    if [[ "$project_dir" == /var/www/* ]]; then
        runtime_user=dealnibot
    else
        runtime_user="${SUDO_USER:-$(id -un)}"
    fi
fi
if [[ "$runtime_user" == dealnibot ]] && ! id "$runtime_user" >/dev/null 2>&1; then
    "${sudo_cmd[@]}" useradd --system --user-group --home-dir "$project_dir" --no-create-home --shell /usr/sbin/nologin "$runtime_user"
fi
runtime_group="${DEALNI_RUNTIME_GROUP:-$(id -gn "$runtime_user")}"

echo "Iniciando instalação das dependências necessárias para o bot em PHP no Linux..."
echo "Projeto: ${project_dir}"

# Atualização do sistema
echo "Atualizando sistema..."
"${sudo_cmd[@]}" apt-get update > /dev/null
"${sudo_cmd[@]}" apt-get upgrade -y > /dev/null
echo "Atualização do sistema concluída!"

# Instalando dependências básicas do sistema
echo "Instalando dependências básicas do sistema..."
"${sudo_cmd[@]}" apt-get install -y --no-install-recommends \
    screen postgresql redis-server nginx curl wget unzip iproute2 > /dev/null
echo "Dependências básicas instaladas!"

# Instalando PHP 8.5 e extensões. O PHP 8.5 é o ÚNICO runtime suportado
# (composer.json exige ">=8.5.12 <8.6"); o repositório ondrej/php fornece a
# linha 8.5 nas versões de Ubuntu/Debian que ainda não a empacotam.
echo "Instalando PHP 8.5 e extensões necessárias..."
if ! apt-cache policy php8.5-cli 2>/dev/null | grep -q 'Candidate: [0-9]'; then
    echo "Adicionando o repositório ppa:ondrej/php (fornece PHP 8.5)..."
    "${sudo_cmd[@]}" apt-get install -y --no-install-recommends \
        software-properties-common > /dev/null
    "${sudo_cmd[@]}" add-apt-repository -y ppa:ondrej/php > /dev/null
fi
"${sudo_cmd[@]}" apt-get update > /dev/null
"${sudo_cmd[@]}" apt-get install -y --no-install-recommends \
    php8.5 php8.5-cli php8.5-fpm php8.5-curl php8.5-pgsql php8.5-mbstring php8.5-xml \
    php8.5-redis > /dev/null

# Garante que o binário padrão do sistema é o 8.5.
if command -v update-alternatives > /dev/null 2>&1 && [ -x /usr/bin/php8.5 ]; then
    "${sudo_cmd[@]}" update-alternatives --set php /usr/bin/php8.5 > /dev/null 2>&1 || true
fi
echo "PHP 8.5 e extensões instaladas!"

# Gate de versão: a instalação não pode seguir com um runtime fora da linha 8.5.
# Sem isso o primeiro `composer install` já falharia no platform-check.
if ! php -r 'exit(PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600 ? 0 : 1);' 2>/dev/null; then
    echo "❌ PHP 8.5.12 ou superior na linha 8.5 é obrigatório. Encontrado: $(php --version 2>/dev/null | head -n1)" >&2
    exit 1
fi
echo "✅ PHP 8.5 confirmado: $(php --version | head -n1)"

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
"${sudo_cmd[@]}" bash "$project_dir/scripts/install/instalar_redis.sh"
"${sudo_cmd[@]}" systemctl enable --now "$fpm_service" nginx
echo "Redis, PHP-FPM e Nginx iniciados com sucesso!"

# Verificar se o Composer está instalado
if ! command -v composer > /dev/null 2>&1; then
    echo "Composer não encontrado. Instalando Composer..."
    # A assinatura é conferida ANTES de executar o instalador: baixar um script
    # remoto e canalizá-lo direto para o php é o vetor clássico de comprometimento.
    instalador="$(mktemp)"
    trap 'rm -f "$instalador"' EXIT
    assinatura_esperada="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o "$instalador"
    assinatura_obtida="$(php -r "echo hash_file('sha384', '$instalador');")"
    if [ "$assinatura_esperada" != "$assinatura_obtida" ]; then
        echo "❌ Assinatura do instalador do Composer não confere." >&2
        exit 1
    fi
    php "$instalador" --quiet --version=2.10.3 --filename=composer.phar
    "${sudo_cmd[@]}" install -o root -g root -m 0755 composer.phar /usr/local/bin/composer
    rm -f composer.phar "$instalador"
    trap - EXIT
    echo "Composer instalado com sucesso!"
else
    echo "Composer já está instalado!"
fi

# Não substituir automaticamente um Composer preexistente nem criar backups.
# O mesmo pin dos workflows é requisito antes de instalar dependências.
composer_version="$(composer --no-plugins --no-ansi --version | awk 'NR == 1 {print $3}')"
if [[ "$composer_version" != "2.10.3" ]]; then
    echo "Composer 2.10.3 exigido; encontrado ${composer_version:-desconhecido}. Configure o binário oficial verificado no PATH." >&2
    exit 1
fi

# Configurando horário do servidor para São Paulo
if command -v timedatectl > /dev/null 2>&1; then
    echo "Configurando horário do servidor para São Paulo..."
    "${sudo_cmd[@]}" timedatectl set-timezone America/Sao_Paulo
    current_tz="$(timedatectl show --property=Timezone --value)"
    echo "Timezone configurado para: $current_tz"
else
    echo "timedatectl indisponível: timezone não alterada." >&2
fi

# Banco PostgreSQL: configure o DSN e aplique as migrations (docs/DADOS.md).

# Instalar as dependências travadas no composer.lock. No host o instalador roda
# como root: COMPOSER_ALLOW_SUPERUSER=1 só com --no-plugins --no-scripts, para
# nenhum código de pacote rodar como root (o mesmo install do deploy).
if [ -f "composer.json" ]; then
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader \
        --no-interaction --no-plugins --no-scripts
fi

# Estado do host fora do checkout: /var/lib/dealnibot guarda o estado do
# monitor de saúde (padrão de DEALNI_MONITOR_STATE_DIR), em cron/ os locks
# dos jobs e, em redis/, os dados do Redis (criados por instalar_redis.sh). Os jobs rodam como o usuário do bot, então a árvore é dele e 0700:
# criada só com `install -d .../cron`, a raiz nasceria root:root 0755 e o
# monitor falharia a cada execução ao gravar monitor-saude.estado. Os locks
# continuam arquivos regulares 0600; symlink é recusado, nunca seguido.
if [[ "$project_dir" == /var/www/* ]]; then
    estado_dir="/var/lib/dealnibot"
    for caminho in "$estado_dir" "$estado_dir/cron"; do
        if [[ -L "$caminho" || ( -e "$caminho" && ! -d "$caminho" ) ]]; then
            echo "❌ Estado do host precisa ser diretório regular, sem symlink: ${caminho}" >&2
            exit 1
        fi
    done
    "${sudo_cmd[@]}" install -d -o "$runtime_user" -g "$runtime_group" -m 0700 \
        "$estado_dir" "$estado_dir/cron"
    # Explícito para o diretório criado à mão em host antigo (root:root 0755).
    "${sudo_cmd[@]}" chown "$runtime_user:$runtime_group" "$estado_dir" "$estado_dir/cron"
    "${sudo_cmd[@]}" chmod 0700 "$estado_dir" "$estado_dir/cron"
    for nome_lock in webhook-inbox.lock snapshot.lock; do
        caminho_lock="$estado_dir/cron/$nome_lock"
        if [[ -L "$caminho_lock" || ( -e "$caminho_lock" && ! -f "$caminho_lock" ) ]]; then
            echo "❌ Lock do cron precisa ser arquivo regular, sem symlink: ${caminho_lock}" >&2
            exit 1
        fi
        if [[ ! -e "$caminho_lock" ]]; then
            "${sudo_cmd[@]}" install -o "$runtime_user" -g "$runtime_group" -m 0600 /dev/null "$caminho_lock"
        else
            "${sudo_cmd[@]}" chown "$runtime_user:$runtime_group" "$caminho_lock"
            "${sudo_cmd[@]}" chmod 0600 "$caminho_lock"
        fi
    done
fi

# Configurando permissões dos arquivos
echo "Configurando permissões dos arquivos..."
"${sudo_cmd[@]}" chmod 755 ./*.sh scripts/install/*.sh scripts/operacao/*.sh scripts/quality/*.sh 2>/dev/null || true
"${sudo_cmd[@]}" chmod 644 ./*.php comandos/*.php 2>/dev/null || true
# Nunca ampliar temporariamente a leitura das credenciais durante reinstalação.
for arquivo_php in inclusos/*.php; do
    [ "$arquivo_php" = "inclusos/config.php" ] && continue
    "${sudo_cmd[@]}" chmod 644 "$arquivo_php" 2>/dev/null || true
done
"${sudo_cmd[@]}" chmod 644 ./*.json ./*.txt 2>/dev/null || true
"${sudo_cmd[@]}" chmod 755 .

# Na VPS o runtime lê o código, mas não pode substituir manifestos, executáveis
# ou código: a árvore inteira, a raiz inclusive, fica com root.
arquivo=inclusos/config.php
if [[ "$project_dir" == /var/www/* ]]; then
    "${sudo_cmd[@]}" chown -R root:"$runtime_group" "$project_dir"
    "${sudo_cmd[@]}" chmod -R g-w,o-rwx "$project_dir"
    "${sudo_cmd[@]}" chmod -R g+rX "$project_dir"
    "${sudo_cmd[@]}" chmod 0751 "$project_dir"
    # config.php é PHP incluído em toda requisição: o runtime só o lê.
    if [[ -f "$arquivo" ]]; then
        "${sudo_cmd[@]}" chown root:"$runtime_group" "$arquivo"
        "${sudo_cmd[@]}" chmod 0640 "$arquivo"
    fi
elif [[ -f "$arquivo" ]]; then
    "${sudo_cmd[@]}" chown "$runtime_user:$runtime_group" "$arquivo"
    "${sudo_cmd[@]}" chmod 0600 "$arquivo"
fi
# Estado gravável só em pastas dedicadas do runtime. Um symlink nelas seria
# herança da época em que o runtime era dono da raiz: recusar, não seguir.
snapshots_dir="${DEALNI_SNAPSHOTS_DIR:-$project_dir/snapshots}"
for pasta in "$project_dir/logs" "$snapshots_dir"; do
    if [[ -L "$pasta" ]]; then
        echo "❌ Recusando symlink no estado do runtime: ${pasta}" >&2
        exit 1
    fi
    "${sudo_cmd[@]}" install -d -o "$runtime_user" -g "$runtime_group" -m 0700 "$pasta"
    "${sudo_cmd[@]}" find "$pasta" -maxdepth 1 -type f \
        -exec chown "$runtime_user:$runtime_group" {} + -exec chmod 0600 {} +
done

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
if redis-cli -h 127.0.0.1 -p 6380 ping > /dev/null 2>&1; then
    echo "✅ Redis: Conectado e funcionando"
else
    echo "❌ Redis: Erro de conexão"
fi

# Mensagem final
echo ""
echo "=========================================="
echo "Instalação concluída no Linux!"
echo "=========================================="
echo ""
echo "Serviços iniciados:"
echo "- Redis: $(systemctl is-active redis-dealnibot || true)"
echo "- PHP-FPM: $(systemctl is-active "$fpm_service" || true)"
echo "- Nginx: $(systemctl is-active nginx || true)"
echo ""
echo "Runtime: ${runtime_user}:${runtime_group}"
echo "Snapshots: ${snapshots_dir} (0700, dono ${runtime_user})"
echo "Lembre-se de configurar os dados do bot em 'inclusos/dados_bot.php'"
echo "Depois instale deploy/php-fpm/dealnibot.conf e inclua deploy/nginx/dealnibot.conf conforme docs/OPERACAO.md."
echo ""
