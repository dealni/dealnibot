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
        ROLLBACK_CONFIRM="${ROLLBACK_CONFIRM:-}" \
        /bin/bash -p "$0" "$@" || exit 126
fi

# Rollback de CÓDIGO, no host e como root:
# `ROLLBACK_CONFIRM=<bot> scripts/deploy/rollback.sh`. Chame o script direto:
# como root, o Composer recusa o alias do composer.json.
#
# Rollback de código e rollback de dados são operações diferentes e este script
# faz **só a primeira**: volta o checkout publicado para um commit anterior e
# reinstala as dependências daquele commit. O banco NÃO será tocado — nem
# `bot.db`, nem migration aplicada, nem snapshot. Misturar as duas coisas num
# comando só é como se perde dado achando que se está revertendo deploy.
#
# Rollback de DADOS é `scripts/deploy/db_restore.sh`, com token próprio, writers parados e
# o runbook de restauração do banco em docs/OPERACAO.md ao lado (o título do
# runbook deste bot está no bloco de dados abaixo).
#
# A confirmação é ROLLBACK_CONFIRM, deliberadamente DIFERENTE de DEPLOY_CONFIRM:
# quem acabou de publicar tem DEPLOY_CONFIRM exportado no shell, e um rollback
# digitado por engano nesse estado rodaria sozinho. Reverter não pode herdar a
# confirmação de quem publicou.

set -euo pipefail

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
bot_slug="dealnibot"
expected_dir="/var/www/dealnibot"
fpm_service="php8.5-fpm"
# Instalações isoladas mantêm o socket do pool e têm mestre próprio.
if [[ -f "/etc/systemd/system/php8.5-fpm-${bot_slug}.service" ]]; then
    fpm_service="php8.5-fpm-${bot_slug}"
fi
# Runbook de DADOS deste bot: "Voltar os dados (corrupção ou migração ruim)".
# Scripts PHP que o composer.json liga aos eventos do install; o install roda
# com --no-scripts, então o rollback os chama explicitamente, nesta ordem.
passos_pos_install=()
servicos_extras=()
publicacao_timers=("dealnibot-snapshot-recencia.timer" "dealnibot-backup-local.timer")
publicacao_timers_opcionais=("dealnibot-snapshot-recencia.timer" "dealnibot-backup-local.timer")
publicacao_exige_cron=1
# --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ -n "${RELEASE_PROJECT_DIR:-}" ]]; then project_dir="$RELEASE_PROJECT_DIR"; fi
publicacao_ferramentas_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
project_name="$(basename "$project_dir")"
release_file="$project_dir/.release.json"

# A guarda de confirmação roda antes deste ponto; uma vez autorizado, todo
# acesso ao Git ainda precisa ficar preso a este checkout, e não a valores de
# GIT_DIR/GIT_INDEX_FILE/GIT_OBJECT_DIRECTORY fornecidos por quem chamou.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY \
    GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_COMMON_DIR GIT_NAMESPACE \
    GIT_CEILING_DIRECTORIES GIT_PREFIX

# Guarda de confirmação ANTES de qualquer leitura de estado: um rollback
# digitado por engano na máquina errada não pode chegar a tocar em git nem em
# vendor/.
if [[ "${ROLLBACK_CONFIRM:-}" != "$project_name" ]]; then
    echo "Rollback não iniciado. No host, como root: ROLLBACK_CONFIRM=$project_name scripts/deploy/rollback.sh" >&2
    echo "O token é próprio de propósito: DEPLOY_CONFIRM não serve aqui." >&2
    echo "Rollback de DADOS não é este comando: use scripts/deploy/db_restore.sh." >&2
    exit 2
fi

cd "$project_dir"

checkout_root="$(git -C "$project_dir" rev-parse --show-toplevel 2>/dev/null || true)"
if [[ "$checkout_root" != "$project_dir" ]]; then
    echo "O diretório do projeto não é um checkout Git próprio: $checkout_root" >&2
    exit 1
fi

alvo=""
ensaio=0
aceito_schema=0
retomar_incompleto=0

for argumento in "$@"; do
    case "$argumento" in
        --para=*) alvo="${argumento#*=}" ;;
        --ensaio) ensaio=1 ;;
        --retomar-incompleto) retomar_incompleto=1 ;;
        --aceito-schema-divergente) aceito_schema=1 ;;
        -h|--help)
            cat >&2 <<USO
Uso: ROLLBACK_CONFIRM=$project_name scripts/deploy/rollback.sh [opções]

  --para=REF                   commit ou tag de destino (padrão: commit_anterior do carimbo)
  --ensaio                     mostra o que faria e não altera nada
  --aceito-schema-divergente   segue mesmo com migration mais nova que o alvo
USO
            exit 0
            ;;
        *) echo "Opção desconhecida: $argumento" >&2; exit 2 ;;
    esac
done

if [[ "$project_dir" != "$expected_dir" ]] && ((ensaio == 0)); then
    echo "Checkout inesperado: $project_dir (esperado: $expected_dir)." >&2
    echo "Use --ensaio para experimentar fora do host." >&2
    exit 1
fi

if ((ensaio == 0)); then
    # Exclusão comum com publicar.sh/deploy.sh. As funções ficam carregadas no
    # shell atual durante o switch, mesmo se o destino tiver um helper antigo.
    # shellcheck source=scripts/deploy/publicacao_operacao.sh
    source "$publicacao_ferramentas_dir/publicacao_operacao.sh"
    publicacao_adquirir_lock "$bot_slug"
    if ((${#servicos_extras[@]} > 0)); then
        publicacao_definir_servicos "$bot_slug" "${servicos_extras[@]}"
    else
        publicacao_definir_servicos "$bot_slug"
    fi
fi

if [[ -L "$release_file" ]]; then
    echo "O carimbo em $release_file não pode ser um symlink." >&2
    exit 1
fi

if [[ -z "$alvo" ]]; then
    if [[ ! -f "$release_file" ]]; then
        echo "Sem carimbo em $release_file e sem --para=REF: não há destino." >&2
        exit 1
    fi
    # shellcheck disable=SC2016
    alvo="$(php -r '
        $dados = json_decode((string) file_get_contents($argv[1]), true);
        echo is_array($dados) && isset($dados["commit_anterior"]) && is_string($dados["commit_anterior"])
            ? $dados["commit_anterior"]
            : "";
    ' "$release_file")"
fi

if [[ -z "$alvo" ]]; then
    echo "O carimbo não registra commit anterior (primeira publicação?)." >&2
    echo "Informe o destino com --para=REF." >&2
    exit 1
fi

if ! git rev-parse --verify --end-of-options "$alvo^{commit}" >/dev/null 2>&1; then
    echo "Destino inexistente neste checkout: $alvo" >&2
    exit 1
fi

atual="$(git rev-parse --verify HEAD)"
destino="$(git rev-parse --verify --end-of-options "$alvo^{commit}")"

echo "Rollback de CÓDIGO — $bot_slug"
echo "  de:    $atual"
echo "  para:  $destino ($alvo)"
echo "  dados: intocados. O banco NÃO será tocado por este comando."

if ((retomar_incompleto == 1)); then
    ((ensaio == 0)) && publicacao_validar_retomada || exit 1
fi

if [[ "$atual" == "$destino" ]] && ((retomar_incompleto == 0)); then
    echo "Nada a fazer: o checkout já está no commit de destino." >&2
    exit 1
fi

if [[ -n "$(git --no-optional-locks status --porcelain=v1 --untracked-files=normal)" ]]; then
    echo "Árvore suja: resolva antes, para o rollback não misturar edição local com reversão." >&2
    exit 1
fi

# Migration aplicada não volta sozinha. Se o alvo não conhece uma migration que
# já está no disco (e provavelmente já rodou no banco), o código antigo pode
# encontrar um schema mais novo do que ele espera. O script não tenta adivinhar:
# reprova e obriga a decisão.
# A fronteira de backend não é uma divergência comum de schema: voltar ao
# código SQLite pode reabrir a origem antiga, mesmo com o DSN PostgreSQL no host.
# Conservador também no ensaio: nunca remover o suporte já publicado.
if [[ -f database/postgresql/001_inicial.sql ]]; then
    for contrato in database/postgresql/001_inicial.sql inclusos/banco.php inclusos/BancoPostgres.php; do
        if ! git cat-file -e "$destino:$contrato" 2>/dev/null; then
            echo "Rollback recusado: o destino remove o suporte PostgreSQL. Migração reversa exige procedimento próprio." >&2
            exit 1
        fi
    done
fi

migrations_novas=()
if ! migrations_do_destino_atual="$(git diff --no-ext-diff --no-renames --name-only --diff-filter=AM "$destino" "$atual" -- database/migrations/ database/postgresql/)"; then
    echo "Não foi possível comparar migrations entre o destino e o checkout atual; rollback recusado." >&2
    exit 1
fi
while IFS= read -r migration; do
    [[ -n "$migration" ]] && migrations_novas+=("$migration")
done <<< "$migrations_do_destino_atual"

if ((${#migrations_novas[@]} > 0)); then
    echo
    echo "Migrations novas ou alteradas em $atual em relação a $destino:"
    for migration in "${migrations_novas[@]}"; do
        echo "  - $migration"
    done
    if ((aceito_schema == 0)); then
        echo >&2
        echo "Rollback recusado: o código antigo voltaria contra um schema mais novo." >&2
        echo "Não há reversão automática de migration incompatível. Decida e repita com" >&2
        echo "--aceito-schema-divergente, ou volte os DADOS com scripts/deploy/db_restore.sh." >&2
        exit 1
    fi
    echo "  (seguindo assim mesmo por --aceito-schema-divergente)"
fi


# O ambiente do mestre precisa seguir o contrato do código de destino. Sem
# gerador nesse destino, não há como provar uma reversão operacional segura.
if ! git cat-file -e "$destino:scripts/deploy/preparar_fpm.sh" 2>/dev/null; then
    echo "Rollback recusado: o destino não permite regenerar o ambiente FPM." >&2
    exit 1
fi

if ((ensaio == 0)); then
    publicacao_validar_monitor_snapshot_no_destino "$bot_slug" "$destino"
fi

if ((ensaio == 1)); then
    echo
    echo "Ensaio: nada foi alterado. O que seria feito, nesta ordem:"
    echo "  1. git switch --detach $destino"
    echo "  2. composer install --no-dev --optimize-autoloader --no-plugins --no-scripts, como root"
    echo "  3. regenerar fpm.environment conforme o contrato de destino"
    echo "  4. regravar $release_file com origem=rollback"
    echo "  5. reiniciar $fpm_service e os workers e verificar a publicação sob o mesmo lock"
    exit 0
fi



registrador_temporario=""
verificacao_temporaria=""
limpar_registrador() {
    local codigo=$?
    if [[ -n "$registrador_temporario" ]]; then rm -f -- "$registrador_temporario"; fi
    if [[ -n "$verificacao_temporaria" ]]; then rm -rf -- "$verificacao_temporaria"; fi
    publicacao_ao_sair "$codigo"
}
trap limpar_registrador EXIT
registrador_temporario="$(mktemp "${TMPDIR:-/tmp}/$bot_slug-registrar-publicacao-XXXXXX")"
verificacao_temporaria="$(mktemp -d "${TMPDIR:-/tmp}/$bot_slug-verificar-publicacao-XXXXXX")"
cp "$publicacao_ferramentas_dir/registrar_publicacao.sh" "$registrador_temporario"
mkdir -p "$verificacao_temporaria/scripts/deploy" "$verificacao_temporaria/scripts/quality"
cp "$publicacao_ferramentas_dir/deploy_verify.sh" "$verificacao_temporaria/scripts/deploy/deploy_verify.sh"
cp "$publicacao_ferramentas_dir/../quality/validar_sonda_publicada.php" "$verificacao_temporaria/scripts/quality/validar_sonda_publicada.php"
chmod 0700 "$registrador_temporario" "$verificacao_temporaria/scripts/deploy/deploy_verify.sh"
echo "Ferramentas de publicação e verificação preservadas do commit $atual."

if ((retomar_incompleto == 0)); then publicacao_exigir_bundle_disponivel; fi
publicacao_parar_servicos
if ((retomar_incompleto == 0)); then publicacao_criar_bundle "$destino" "$atual" rollback; fi
publicacao_mudou=1
if [[ "$atual" != "$destino" ]]; then git switch --detach "$destino"; fi
# A retomada de publicação avança somente o schema previsto pelo alvo;
# recuperação de rollback continua sem escrever no banco.
if ((retomar_incompleto == 1)) && [[ "$publicacao_recuperacao_operacao" == deploy ]]; then
    bash scripts/deploy/db_backup.sh --rotulo=pre-deploy
    scripts/operacao/executar_com_ambiente.sh --php scripts/deploy/migrate.php
fi
# O checkout é do root e o rollback roda como root. COMPOSER_ALLOW_SUPERUSER=1
# só vale com --no-plugins --no-scripts: nenhum código de pacote roda como root.
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader \
    --no-interaction --no-progress --no-plugins --no-scripts
# Um destino antigo pode não ter o script do passo; sem ele não há o que rodar.
if [[ ${#passos_pos_install[@]} -gt 0 ]]; then
    for passo in "${passos_pos_install[@]}"; do
        if [[ -f "$passo" ]]; then
            php "$passo"
        fi
    done
fi

DEPLOY_CONFIRM="$bot_slug" bash scripts/deploy/preparar_fpm.sh

# O destino pode ser anterior à criação dos scripts operacionais. Executar a
# cópia capturada ANTES do switch evita deixar o checkout revertido sem carimbo.
# Chamado pelo shebang, sem `bash` na frente: o registrador se reexecuta sob
# `env -i` e usa BASH_VERSION para saber se já é a segunda passada. Invocado por
# `bash`, ele lê a variável do interpretador de fora, conclui que a limpeza já
# aconteceu e grava o carimbo com o ambiente sujo do chamador.
RELEASE_PROJECT_DIR="$project_dir" "$registrador_temporario" "$release_file" rollback

publicacao_iniciar_servicos
RELEASE_PROJECT_DIR="$project_dir" "$verificacao_temporaria/scripts/deploy/deploy_verify.sh" "--esperado=$destino" --smoke
publicacao_restaurar_agendamentos
publicacao_marcar_bundle concluida
publicacao_concluida=1

echo
echo "Código revertido, serviços reiniciados e publicação verificada: $destino."
echo

echo "Nenhum banco foi restaurado. Se o incidente exige voltar DADOS, isso é"
echo "outra operação: DB_RESTORE_CONFIRM=$project_name scripts/deploy/db_restore.sh."
