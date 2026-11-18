# Operação — DealniBot

Configuração e diagnóstico para desenvolvimento e para instalações próprias.
Os modelos em `deploy/` precisam ser adaptados ao ambiente de quem opera o bot.
Portões e hooks estão em [TESTES.md](TESTES.md). Snapshots e migrations estão
em [DADOS.md](DADOS.md).

## Pré-requisitos

Use PHP `>=8.5.12 <8.6` com as extensões `ctype`, `curl`, `filter`,
`json`, `mbstring`, `pdo`, `pdo_pgsql` e `redis`. A instalação local também
precisa de Git, Composer 2.10.3, PostgreSQL 16+ e uma instância Redis dedicada.
O `setup:dev` e as verificações usam Node 24 e npm 12 conforme os pinos dos
manifestos. Consulte as [versões](REFERENCIAS.md#runtime-e-plataforma).

Confira `composer --no-plugins --version`. Se divergir, instale o PHAR 2.10.3
em caminho separado pelo [procedimento oficial](https://getcomposer.org/download/),
com verificação do instalador e `--version=2.10.3`; não faça downgrade da
fórmula Homebrew por inferência.

Para manter o pin separado do Homebrew, armazene o PHAR oficial verificado em
`~/.local/share/composer/2.10.3/composer.phar` e exponha-o como
`~/.local/bin/composer` no `PATH` da sessão de desenvolvimento. Confira
`command -v composer` e `composer --no-plugins --version` antes dos portões;
`COMPOSER85_BIN` sozinho não seleciona o Composer de todos os subprocessos.
O instalador macOS prefere esse PHAR pelo caminho exato e usa o PHP 8.5 já
selecionado, sem incluir todo `~/.local/bin` no ambiente higienizado. Na ausência
do PHAR, procura o Composer no seu `PATH` restrito e conserva a verificação de
versão. A instalação do Homebrew permanece separada, sem downgrade ou
`self-update`.

Confira `php -v` antes da instalação. Depois de instalar as dependências, use
`composer check-platform-reqs` para validar a plataforma.

Ferramentas fora do Composer, exigidas pelo `check:all` (ausência reprova):

| Ferramenta | Para quê |
|---|---|
| `shellcheck` | os `.sh` versionados |
| `semgrep` | regras de `tools/quality/semgrep/project/php.yaml` |
| PHPCPD (`tools/phpcpd/`) | detector de cópia, com manifesto próprio |
| `gitleaks` | segredo no índice e no histórico |
| `pg_dump` / `pg_restore` / `initdb` | backup e banco descartável dos testes |
| `redis-server` | Redis descartável da suíte |
| `actionlint` | `composer check:workflows`, fora do `check:all` |

```bash
brew install gitleaks shellcheck actionlint   # macOS
pipx install semgrep                          # Semgrep CE
composer install -d tools/phpcpd              # PHPCPD
```

gitleaks ausente reprova tanto no pre-commit quanto no `check:all`.

## Setup local

1. Instale os [pré-requisitos](#pré-requisitos). Use um checkout Git para o
   `setup:dev` e para os portões de qualidade que verificam o histórico e o
   índice. Se partiu de um ZIP, rode `git init -b main` na raiz extraída antes
   de instalar as dependências de desenvolvimento. Use `dealnibot` como nome
   do diretório para os contratos dos scripts de operação. Para rodar o
   `check:all`, crie um commit inicial com os arquivos revisados em seu Git
   local. O `.gitignore` cobre os arquivos de credenciais e os artefatos locais.

2. Crie um bot próprio no Telegram pelo BotFather e obtenha uma chave do
   OpenRouter para essa instância. Guarde ambos em `inclusos/config.php`.
   Gere `TELEGRAM_WEBHOOK_SECRET` com `openssl rand -hex 32` e guarde no
   mesmo arquivo. O carregador valida as três constantes, inclusive em CLI.

   ```bash
   composer setup:dev
   bash scripts/install/configurar_credenciais.sh
   "${EDITOR:-vi}" inclusos/config.php
   ```

   Para executar o bot com apenas as dependências de runtime, use
   `composer install --no-dev --no-plugins --no-scripts` no lugar de
   `composer setup:dev`. Esta opção funciona a partir dos arquivos extraídos
   e dispensa o Git para a instalação.

3. Com PostgreSQL local disponível, crie uma base vazia e dê acesso a um
   papel de desenvolvimento que possa criar tabelas. Com um usuário
   administrador do PostgreSQL, por exemplo, rode `createuser --pwprompt
   dealnibot_dev` e `createdb --owner=dealnibot_dev dealnibot_dev`. Na sessão
   da aplicação, exporte a senha em `DEALNI_DB_PASSWORD`, fora do DSN.
   Ajuste os nomes do banco e do papel se já tiver um ambiente local.

   ```bash
   export DEALNI_DB_DSN='pgsql:host=127.0.0.1;port=5432;dbname=dealnibot_dev'
   export DEALNI_DB_USER='dealnibot_dev'
   composer db:migrate:deploy
   composer db:migrate:status
   ```

4. Inicie uma instância Redis dedicada em loopback. No macOS, rode
   `bash scripts/install/iniciar_redis_macos.sh` para criar a instância
   local em `127.0.0.1:6380`. Em outro ambiente, configure o Redis
   existente ou inicie uma instância de desenvolvimento em um terminal
   separado com `redis-server --bind 127.0.0.1 --protected-mode yes --port 6380`.
   Se trocar o endereço ou ativar senha, configure `REDIS_HOST`,
   `REDIS_PORT` e `REDIS_PASSWORD` no ambiente do bot.

   ```bash
   redis-cli -h 127.0.0.1 -p 6380 PING
   ```

5. Na mesma sessão, exporte `DEALNI_BOT_USERNAME` com o nome de usuário
   do bot, sem @, para que ele reconheça menções a esse nome. Inicie
   `php bot.php` com as variáveis do banco e do Redis. Use uma única instância de polling por token. Se o bot de teste
   já tem webhook, primeiro remova-o com `php config/config_remover.php`
   usando as credenciais dele. Polling dispensa `DEALNI_PUBLIC_BASE_URL`.

O `setup:dev` instala as dependências do checkout e os hooks. Banco e
serviços locais são preparados separadamente. `bot.sh` reexecuta `php bot.php`
quando ele termina e destina-se ao desenvolvimento.

## Variáveis de ambiente

Os segredos do bot ficam em `inclusos/config.php` (modelo
`inclusos/config.example.php`): `TOKEN_BOT`, `OPENROUTER_API_KEY` e
`TELEGRAM_WEBHOOK_SECRET`, os três obrigatórios em produção
([inventário](SEGURANCA.md#inventário-de-segredos)).

O ambiente do host segue `.env.example`, instalado como
`/etc/dealnibot/environment` e validado por `config/env.schema.php`. O pool
recebe os valores pelo [ambiente isolado do PHP-FPM](#ambiente-isolado-do-php-fpm);
o cron, pelo wrapper `scripts/operacao/executar_com_ambiente.sh`, que recusa
arquivo relativo, symlink ou dono/modo fora de `root:root 0600` e
`root:dealnibot 0640`.

| Variável | Obrigatória | Padrão | Para quê |
|---|---|---|---|
| `DEALNI_DB_DSN` | sim | `pgsql:host=/var/run/postgresql;dbname=dealnibot` no modelo | DSN do PostgreSQL |
| `DEALNI_DB_USER` | sim | `dealnibot` no modelo | usuário do banco |
| `DEALNI_DB_PASSWORD` | não | vazia | senha, fora do DSN |
| `DEALNI_PUBLIC_BASE_URL` | para webhook e monitor (`https_url`) | vazio no runtime, `https://bot.example.com` no modelo | URL pública HTTPS da própria instalação, usada no registro do webhook e nas sondas |
| `DEALNI_BOT_USERNAME` | não | vazio | nome de usuário no Telegram, sem @, para menções e redirecionamento HTTP |
| `DEALNI_ALERTA_CHAT_ID` | não (`string`) | ausente (aviso desligado) | chat do Telegram (id numérico; grupo começa com `-100`) que recebe os avisos ao dono: falha de job do cron e conta recusada pelo OpenRouter ([aviso](#aviso-de-falha-no-telegram)) |
| `DEALNI_SNAPSHOTS_DIR` | sim (`absolute_path`) | `<checkout>/snapshots` | diretório dos snapshots, `0700` |
| `DEALNI_SNAPSHOTS_MANTER` | sim (`positive_integer`) | `7` | janela em dias do snapshot diário; o backup de pré-deploy tem retenção própria ([DADOS.md](DADOS.md#snapshot-e-rotação)) |
| `PHP85_BIN` | sim (`absolute_path`) | `/usr/bin/php8.5` no modelo | PHP dos jobs de cron e do `check:toolchain` |
| `REDIS_HOST` / `REDIS_PORT` | não | `127.0.0.1` / `6380` | endereço do Redis |
| `REDIS_PASSWORD` | não | vazia (o Redis local roda sem senha) | cliente Redis e sonda |
| `DEALNI_RETENCAO_CONTATOS_DIAS` | não | ausente | legada; não faz nada |
| `DEALNI_MONITOR_STATE_DIR` | não | `/var/lib/dealnibot` | estado do monitor de saúde |
| `DEALNI_MONITOR_ALERT_INTERVAL_SECONDS` | não | `3600` | intervalo mínimo entre avisos iguais |
| `DEALNI_LOGS_DIR` | não | `<checkout>/logs` | diretório do log; usado pela suíte |
| `APP_ENV` | não | `production` (valor desconhecido cai nele) | campo `env` do log |
| `CHATBOTS_RELEASE_FILE`, `CHATBOTS_ENV_FILE` | não | ausentes | sobrescritas de teste do carimbo e do EnvironmentFile |
| `CHATBOTS_PG_TEST_DSN`, `CHATBOTS_PG_BIN` | não | ausentes | banco descartável dos portões ([TESTES.md](TESTES.md#o-banco-de-teste)) |

O `environment` só aceita as chaves do schema e as `DEALNI_MONITOR_*`; outra
chave é ignorada com aviso. `DEALNI_LOGS_DIR`, `APP_ENV` e as `CHATBOTS_*` vêm
do processo e servem à suíte. O pool recebe as três `DEALNI_DB_*`,
`DEALNI_PUBLIC_BASE_URL`, `DEALNI_BOT_USERNAME`, `DEALNI_ALERTA_CHAT_ID`
e as `REDIS_*`. Para executar os jobs pela CLI, carregue o `environment`
com o wrapper `scripts/operacao/executar_com_ambiente.sh`.

## Comandos do dia a dia

```bash
php bot.php               # long polling
composer check            # validação de base
composer check:all        # tudo o que é offline, antes de publicar
bash scripts/quality/with_local_config.sh composer check:ci-local  # alternativa com auditorias online
composer test             # suíte inteira, em sandbox de rede
composer format           # aplica o estilo
```

Portões e testes estão em [TESTES.md](TESTES.md#portões-de-qualidade); a tabela
abaixo reúne os comandos operacionais. No host, o checkout é do root e os comandos de host rodam como
root, chamando o script direto: o Composer recusa rodar como root os aliases do
`composer.json`, que ficam para uso local.

| Comando | O que faz |
|---|---|
| `composer setup:dev` / `PROVISION_CONFIRM=dealnibot bash scripts/install/provision_host.sh` | prepara o checkout / provisiona o host Linux, como root |
| `composer install-macos` / `composer install-linux` | bootstrap completo da máquina |
| `composer check:config:production` | carrega a configuração como produção |
| `composer check:config:examples` / `composer check:env` | exemplos cobrem o exigido / `.env.example` contra o schema |
| `composer check:toolchain` | o `check:all` no PHP 8.5 local |
| `DEPLOY_CONFIRM=dealnibot scripts/deploy/preparar_fpm.sh` | deriva `/etc/dealnibot/fpm.environment`; só no host, como root ([FPM](#ambiente-isolado-do-php-fpm)) |
| `composer release:check` (`scripts/deploy/release_check.sh`) | commit e tags do `HEAD`, árvore limpa, `main` e lock em dia; não substitui o `check:all` |
| `DEPLOY_CONFIRM=dealnibot scripts/deploy/deploy.sh` | preparação no host, como root ([Deploy](#deploy)) |
| `scripts/deploy/deploy_verify.sh` | carimbo, `HEAD`, `/health` e `ExecMainStartTimestamp` do FPM; `--smoke` e `--esperado=SHA`; só no host |
| `ROLLBACK_CONFIRM=dealnibot scripts/deploy/rollback.sh` | volta o **código**; não toca no banco; só no host, como root |
| `composer db:migrate:deploy` / `composer db:migrate:status` (`scripts/deploy/migrate.php`) | migrations; no host, `scripts/operacao/executar_com_ambiente.sh --php scripts/deploy/migrate.php [--status]` ([DADOS.md](DADOS.md#schema-e-migrations)) |
| `composer db:backup` / `composer db:restore:test` (`scripts/deploy/db_backup.sh`, `scripts/deploy/db_restore_test.sh`) | backup e ensaio de restauração ([DADOS.md](DADOS.md#backup-e-restauração)) |
| `DB_RESTORE_CONFIRM=dealnibot scripts/deploy/db_restore.sh` | restauração em banco vazio; só no host, como root ([DADOS.md](DADOS.md#backup-e-restauração)) |
| `composer privacy:retention` | inativo: só informa que a retenção de contatos está desligada |
| `composer test:smoke -- --confirm-production` | Telegram, Redis e PostgreSQL reais, somente leitura |

Para inspecionar o banco no host:

```bash
sudo -u dealnibot psql -h /var/run/postgresql -d dealnibot -c 'TABLE schema_migrations_postgresql'
```

## Modelos de instalação

| Componente | Arquivo de referência |
|---|---|
| Nginx | `deploy/nginx/dealnibot.conf` |
| PHP-FPM | pool e configuração do mestre em `deploy/php-fpm/` |
| Redis | `deploy/redis/dealnibot.conf`, com instância dedicada |
| Agendamentos | `config/crontab.txt` e modelos em `deploy/systemd/` |
| Ambiente e credenciais | `.env.example` e `inclusos/config.example.php` |

### Identidade e Redis do bot

Os modelos configuram PHP-FPM, cron e Redis com o usuário `dealnibot`.
A configuração de referência vincula o Redis ao loopback `127.0.0.1:6380`.
Ao criar uma instância própria, limite o acesso ao usuário do serviço.
Configure `REDIS_PASSWORD` no ambiente do bot se o servidor exigir senha.

| Peça | Exemplo de destino | No repositório |
|---|---|---|
| Unit | `/etc/systemd/system/redis-dealnibot.service`: `User=dealnibot`, `ProtectSystem=strict`, escrita só em `/var/lib/dealnibot/redis` | `deploy/systemd/redis-dealnibot.service` |
| Drop-in | `redis-dealnibot.service.d/60-isolamento.conf`: exige `bots-redis-isolation.service` (nftables por UID) | `deploy/systemd/redis-dealnibot.service.d/` |
| Configuração | `/etc/dealnibot/redis.conf`, `root:dealnibot 0640`: porta 6380, AOF, `save 900 1`, `maxmemory 256mb` com `noeviction` | `deploy/redis/dealnibot.conf` |
| Dados | `/var/lib/dealnibot/redis`, `dealnibot:dealnibot 0750`, dentro do estado do host (`0700`) | criado pelo instalador |

A instância roda como o próprio usuário do bot, e não como `redis`: a
configuração fica ao lado do ambiente em `/etc/dealnibot` e os dados dentro de
`/var/lib/dealnibot`. `scripts/install/instalar_redis.sh` exige o usuário
`dealnibot`, cria diretórios, configuração, unit e drop-in só quando a unit
ainda não existe, preserva uma instância já instalada e recusa porta ocupada e
caminho symlink. Não migra chaves entre instâncias. `ContratoDoRedisTest` fixa
as linhas do host nesses arquivos e confere que instalador, unit e
configuração apontam para os mesmos caminhos.

## Deploy

Antes de suspender o runtime, o publicador extrai o SHA alvo em diretório
privado fora do checkout, confere `composer.json`/`composer.lock`, instala
`--no-dev --no-plugins --no-scripts` e valida a plataforma. O `vendor` servido
permanece intacto nesse preparo; falhas abortam com os serviços ativos.
Preparo e checkout precisam estar no mesmo filesystem. Depois da drenagem,
o deploy confere novamente SHA e lock e troca o `vendor` por rename; uma falha
na troca devolve o anterior quando possível e mantém os serviços parados.
Migrations, passos explícitos após install, ambiente FPM, carimbo e smoke
continuam obrigatórios. Deploy direto e recuperação mantêm instalação com
o runtime suspenso. O diretório temporário é limpo ao sair; se a devolução do
`vendor` anterior falhar, ele é preservado para intervenção manual.

Na publicação completa, `check-platform-reqs` roda no preparo isolado antes da
pausa, sempre com `--no-dev` para conferir apenas requisitos de produção; o
preparador interno consome as dependências conferidas sem repetir esse
comando. A instalação direta mantém sua conferência. O `deploy_verify.sh`
confere a plataforma novamente na aceitação e quando chamado sozinho, para
continuar detectando mudanças posteriores no PHP ou nas extensões do host.
Suítes unitárias, contratos e análise estática continuam nos gates locais;
saúde HTTP/FPM, banco e Redis são conferidos no ambiente publicado.

No host, como root e em `/var/www/dealnibot`:
`DEPLOY_CONFIRM=dealnibot scripts/deploy/publicar.sh --esperado=SHA_COMPLETO`,
chamado direto, porque o checkout é do root. Esse publicador suspende/drena o
runtime, troca o checkout e ativa a publicação sob lock. Seu preparo interno,
`scripts/deploy/deploy.sh`, executa estas etapas:

1. exige checkout em `/var/www/dealnibot`, `main` limpa, `inclusos/config.php`
   e `/etc/dealnibot/environment`;
2. remove do ambiente as chaves aposentadas
   (`scripts/deploy/sanear_ambiente_legado.sh`, com cópia datada ao lado);
3. valida o ambiente real (`check_env.php --file=...`);
4. faz backup verificado (`db:backup --rotulo=pre-deploy`), com nome
   `pre-deploy-*` e retenção própria ([DADOS.md](DADOS.md#snapshot-e-rotação)),
   exceto com `DEPLOY_SEM_BACKUP=1`;
5. aplica as migrations pendentes;
6. instala com `--no-dev` e `COMPOSER_ALLOW_SUPERUSER=1 --no-plugins
   --no-scripts` (nenhum código de pacote roda como root), confere plataforma
   sem plugins e roda pelo PHP direto os portões de ambiente e de configuração
   de produção;
7. refaz o `fpm.environment` e grava o carimbo `.release.json` por último.

O preparo direto exige FPM e agendamentos suspensos e drenados pelo publicador.
O fluxo completo é `scripts/deploy/publicar.sh`, que atualiza e ativa sob lock. A sequência no host,
como root:

```bash
cd /var/www/dealnibot
DEPLOY_CONFIRM=dealnibot scripts/deploy/publicar.sh --esperado=SHA_COMPLETO_DE_ORIGIN_MAIN
```

No fluxo completo, a release atual faz o backup pré-deploy antes de suspender
agendamentos ou parar serviços. Se falhar, a publicação aborta com o runtime
ativo; se passar, o preparador não repete a cópia. O preparo direto e a
recuperação mantêm suas próprias regras de backup. O backup online é um
snapshot consistente anterior à pausa: transações posteriores não entram
nele, portanto não representa o último instante parado. O banco continua
sem restauração automática.
O `deploy_verify.sh` roda com
`--smoke`. Só quando o dono pedir, publique sem backup (`DEPLOY_SEM_BACKUP=1`)
ou sem smoke (sem `--smoke`); a dispensa vale só para aquela publicação. O publicador interrompe a operação se o `fetch` falhar ou o SHA obtido divergir
do esperado; nesse caso não troca o checkout.

O monitor de recência do snapshot é opcional enquanto ausente. A publicação
não o instala; sua ativação exige a
[instalação explícita](DADOS.md#monitor-independente-de-recência-do-snapshot-diário).
Depois de instalado, seu timer é suspenso e retomado junto ao cron.

No fluxo completo, cron e FPM ficam suspensos na troca de código e só voltam
quando o preparo e a verificação terminam. O carimbo é publicado antes do novo
mestre FPM, e `/health` e `/ready` são verificados após sua ativação.

Antes de publicar, rode
`composer release:check` e
`bash scripts/quality/with_local_config.sh composer check:ci-local` num checkout
com dependências de desenvolvimento. O agregado já inclui toda a validação
offline; não rode também `check:all`. Para drenar a
entrega, `php config/config_remover.php` antes e `php config/config_webhook.php`
no fim; o Telegram reentrega o que não foi confirmado.

### Ambiente isolado do PHP-FPM

`scripts/deploy/preparar_fpm.sh` valida `/etc/dealnibot/environment` e deriva
`/etc/dealnibot/fpm.environment` (`0600`) com aliases `DEALNI_FPM_*`, que o pool
converte para os nomes do runtime. No host ele exige root e
`DEPLOY_CONFIRM=dealnibot` e também valida a configuração de produção. O
mestre exclusivo lê o derivado pelo `EnvironmentFile` da própria unit; o drop-in
abaixo serve só ao FPM global. O arquivo derivado não se edita à mão. O mestre compartilhado requer aliases por aplicação para impedir que as
variáveis `REDIS_*` de instalações distintas se sobrescrevam.

```bash
cd /var/www/dealnibot
DEPLOY_CONFIRM=dealnibot scripts/deploy/preparar_fpm.sh
install -d -m 0755 /etc/systemd/system/php8.5-fpm.service.d
install -m 0644 deploy/systemd/php8.5-fpm-dealnibot-environment.conf \
  /etc/systemd/system/php8.5-fpm.service.d/dealnibot-environment.conf
# incorpore as diretivas env[] de deploy/php-fpm/dealnibot.conf ao pool instalado
php scripts/quality/check_fpm_environment.php --host
systemctl daemon-reload && systemctl restart php8.5-fpm
```

Depois de alterar o arquivo de origem, repita o `preparar_fpm.sh` e
`systemctl daemon-reload && systemctl restart php8.5-fpm`. Um `reload` sozinho
não reimporta as variáveis do systemd no mestre. No host com mestre exclusivo,
troque `php8.5-fpm` por `php8.5-fpm-dealnibot`.

O `EnvironmentFile` guarda a senha do banco e, se houver, a do Redis. O modo de
`/etc/dealnibot/environment` é `root:dealnibot 0640` porque o cron roda como
`dealnibot`: esse é o **risco aceito**, registrado também no drop-in. Se o cron
deixar de lê-lo, prefira `0600 root:root`, que já é obrigatório para
`fpm.environment`. O carimbo `.release.json` fica `root:dealnibot 0640` para
leitura pelo FPM; locks e cópias do banco seguem dono e modo próprios, conforme
o instalador e [DADOS.md](DADOS.md#backup-e-restauração). Variáveis de
ambiente não são uma fronteira completa para segredos, segundo o próprio
systemd; a limitação vale até haver credenciais nativas do systemd.

### Como uma publicação é identificada

O `.release.json` (gravado por `scripts/deploy/registrar_publicacao.sh`) guarda
bot, origem (`deploy`/`rollback`), commit, commit anterior, tag, `git describe`,
SHA-256 do `composer.lock`, quem publicou e o instante UTC. É gitignorado:
descreve o host. A gravação é atômica, recusa symlink e usa `0640` com grupo
`dealnibot` em `/var/www/dealnibot` (`0600` fora dele).

O SHA basta como identidade; tag `vX.Y.Z` é opcional. O campo `version` de log,
`/health` e `/ready` é a tag exata ou o commit curto do carimbo; sem carimbo,
vem do `.git` lido como arquivo (sem subprocesso `git`); sem os dois,
`desconhecida`.

### Verificação da publicação

`scripts/deploy/deploy_verify.sh` só lê e exige juntos: carimbo deste bot e checkout,
com commit igual ao do disco (e ao de `--esperado=SHA_COMPLETO`); árvore limpa e
`EnvironmentFile` seguro (`root:root 0600` ou `root:dealnibot 0640`, sem
symlink), sem os quais para ali; `PHP85_BIN` canônico apontando para PHP 8.5;
`composer.lock` intacto, `vendor/` presente e `check-platform-reqs` verde; mestre FPM
que iniciou **depois** do deploy
(`systemctl show --timestamp=us+utc -p ExecMainStartTimestamp --value`, systemd
v248+); `fpm.environment` e pool instalado conforme o contrato; `/index.php`
com `302` para o username definido em `DEALNI_BOT_USERNAME` ou `404`
quando ele estiver vazio; e `/health` com `200`,
`service=dealnibot` e o `version` do carimbo; `/ready` confirma essa mesma
identidade e PostgreSQL/Redis prontos no processo FPM. Relógio fora de sincronia pode
inverter a ordem observada.

## Rollback

Rollback de código e de dados são operações diferentes, com tokens diferentes.

| Operação | Comando | Efeito |
|---|---|---|
| código | `ROLLBACK_CONFIRM=dealnibot scripts/deploy/rollback.sh` | volta ao `commit_anterior` do carimbo (ou `--para=<ref>`), reinstala `--no-dev` como o deploy (sem plugins nem scripts) e regrava o carimbo; `--ensaio` só mostra |
| dados | `DB_RESTORE_CONFIRM=dealnibot scripts/deploy/db_restore.sh` | restaura um dump num banco vazio ([DADOS.md](DADOS.md#restauração)) |

Os dois rodam no host, como root, chamando o script direto. O rollback exige
árvore limpa e o checkout do host (salvo `--ensaio`). Recusa
destino que remove o suporte PostgreSQL e destino que não conhece uma migration
nova ou alterada do checkout atual (compara os arquivos com `git diff`, não o
banco); só esta segunda recusa cede a `--aceito-schema-divergente`. Não existe
rollback de versão do PHP. Passo a passo
em [Runbooks de incidente](#runbooks-de-incidente).

## Rotinas automáticas

O modelo `config/crontab.txt` destina-se ao cron de sistema (formato de
`/etc/cron.d/`) e usa `CRON_TZ=America/Sao_Paulo`. Use um único agendamento
para cada rotina. Se o snapshot for instalado como timer systemd, retire
a linha correspondente da tabela cron do seu ambiente.

A linha de snapshot no modelo usa `/usr/local/libexec/vps-backup-maintenance.py`
para coordenar a execução com a manutenção do sistema. O supervisor e o lock
do host são externos a este projeto. Em instalação própria com agendamento
isolado, adapte a cópia instalada do cron para começar diretamente em
`scripts/operacao/executar_com_ambiente.sh`, preservando o lock do snapshot.
Coordene o agendamento com a manutenção do seu host.

| Job | Quando | Como conferir |
|---|---|---|
| `processos/processar_webhook_inbox.php`, por `avisar_se_falhar.sh webhook-inbox` | a cada minuto | canal `webhook_inbox` e `status` das linhas; aviso no Telegram se o job falhar |
| `processos/snapshot_banco.php`, por `avisar_se_falhar.sh snapshot` | 5h10 | canal `snapshot_banco` (sucesso e falha); aviso no Telegram em falha |
| `scripts/operacao/monitorar_saude.sh`, por `avisar_se_falhar.sh monitor-saude` | a cada 5 minutos | aviso no Telegram na falha e na recuperação |

O replay da inbox mantém o resumo de processamento e retenção em STDOUT. Uma
rodada com `falhas > 0` também escreve um diagnóstico em STDERR e termina com
código 1, preservando o backoff dos updates. Erro SQL na recuperação de leases
ou na reserva interrompe a rodada, conta como falha e mantém os updates para
retentativa; a retenção ainda é executada. O wrapper `avisar_se_falhar.sh`
recebe esse código e não sinaliza recuperação enquanto a rodada falha.

Os três passam por `scripts/operacao/executar_com_ambiente.sh`, que carrega
`/etc/dealnibot/environment` e, com `--php`, usa o `PHP85_BIN` dele, e por
`scripts/operacao/avisar_se_falhar.sh` ([aviso](#aviso-de-falha-no-telegram)),
para alertas opcionais. `CHATBOTS_ENV_FILE` só existe para a
suíte. Os dois jobs PHP usam `--lock-nonblocking`: um `flock` exclusivo em
arquivo regular de `/var/lib/dealnibot/cron`; o wrapper recusa criar o caminho
e recusa symlink.

O STDOUT de rotina vai para `/dev/null`. O cron encaminha STDERR ao
destinatário de `MAILTO` pelo `/usr/sbin/sendmail` do host, desde que o
transporte de e-mail esteja configurado.

Use os comandos abaixo para reinstalar o modelo em uma instância que use
as três rotinas do cron. Guarde a tabela anterior fora de `/etc/cron.d` para
evitar que o cron leia as duas cópias.

```bash
cp -p /etc/cron.d/dealnibot /root/cron.d-dealnibot.bak-$(date -u +%Y%m%dT%H%M%SZ)
install -o root -g root -m 0644 /var/www/dealnibot/config/crontab.txt /etc/cron.d/dealnibot
diff /var/www/dealnibot/config/crontab.txt /etc/cron.d/dealnibot
```

Para conferir:

```bash
systemctl is-enabled --quiet cron && systemctl is-active --quiet cron
command -v flock
stat -c '%a:%U:%G' /etc/cron.d/dealnibot /var/lib/dealnibot/cron/*.lock
```

## Logs

Uma linha JSON por evento em `logs/app-AAAA-MM-DD.log`, 14 arquivos, `0600` em
diretório `0700`. Em CLI há cópia em STDERR. Falha de escrita emite o evento em
STDERR com `extra.log_fallback=true` sem interromper o negócio. Comando rodado
como root não cria nem altera os arquivos diários: rode as rotinas como
`dealnibot`. Consulte os arquivos com `tail`, `rg` e `jq`.

O modelo FPM usa `/var/log/php8.5-fpm-dealnibot/error.log`. Configure
a rotação desse arquivo no seu host e mantenha permissões restritas.

### O evento

```json
{"ts":"2026-08-30T14:03:11.482Z","level":"error","msg":"Falha não tratada no roteador: RuntimeException: ...","service":"dealnibot","env":"production","version":"a1b2c3d","request_id":"3f2a9c1e-5b7d-4e11-9a02-77c1d4e8b930","channel":"roteador_erros"}
```

| Campo | O que é |
|---|---|
| `ts` | ISO 8601 em UTC, com milissegundos |
| `level` | `debug`, `info`, `warn`, `error` ou `fatal` (`notice` vira `info`; `critical`/`alert`/`emergency` viram `fatal`) |
| `service` | `dealnibot` |
| `env` | `development`, `test`, `staging` ou `production` |
| `version` | ver [identificação](#como-uma-publicação-é-identificada) |
| `request_id` | do ciclo: webhook, update do polling ou job |
| `channel` | assunto do evento |
| `context` | contexto redigido; omitido se vazio |
| `err` | `{type, message}`; sem `file`, `line` nem `trace` |

Chaves com `password`, `senha`, `token`, `authorization`, `cookie`, `session`,
`secret`, `key`, `jwt`, `auth`, `credential`, `cpf`, `cnpj`, `card`, `cvv` ou
`pix` viram `"[REDACTED]"` no contexto e no texto (`chave=valor`), e
`/bot<token>` em URL também é mascarado.

O `request_id` vem do header `X-Request-Id` quando casa
`^[A-Za-z0-9_-]{8,64}$`; senão é um UUID v4 gerado antes da conferência do
segredo. Volta no header da resposta.

### Os canais

| Canal | O que cai nele |
|---|---|
| `telegram` | falha da Bot API: método, nomes dos campos e `chat_id`; recusa definitiva de escrita (bot removido, bloqueado ou sem direito de escrever) é `warn` com `recusa_de_escrita: true`, e o resto, `error` |
| `erros_openai` | modalidade, HTTP, errno e diagnóstico sanitizado; erros do corpo viram categoria fixa, sem `error.message` bruto; níveis e avisos em [INTEGRACOES.md](INTEGRACOES.md#falhas-1) |
| `erros_redis`, `bd` | infraestrutura fora do ar; em `bd`, também consulta falha (PDO, SQLSTATE e SQL fixo, nunca os binds) |
| `roteador_erros` | exceção que escapou do roteador depois do ACK |
| `webhook` | fim do atendimento de cada update do webhook: `info` com `update_id`, `desfecho` (`processado`, `falha` ou `vazio`, para update já confirmado) e `duration_ms`, do topo do `bot_web.php` ao desfecho, ACK e chamada ao provedor inclusos |
| `webhook_inbox` | desfecho de update que não pôde ser registrado |
| `snapshot_banco` | desfecho do snapshot diário: `info` com `arquivo`, `bytes`, `removidos` e `duration_ms`; `error` com `err` e `duration_ms` na falha |
| `prontidao` | reprovação de `/ready`; aprovação não gera linha |

```bash
f=logs/app-$(date +%F).log
jq -c 'select(.channel=="roteador_erros")' "$f" | tail -20   # leu e não respondeu
jq -c 'select(.level=="error" or .level=="fatal")' "$f"
id=$(jq -r 'select(.level=="error") | .request_id' "$f" | tail -1)
jq -c --arg id "$id" 'select(.request_id==$id)' "$f"          # o ciclo inteiro
jq -c 'select(.channel=="webhook") | [.ts, .duration_ms, .context.desfecho]' "$f" | tail -20   # latência
```

### Logs operacionais

`escreverLog` mantém seus três argumentos legados e aceita `nivel` PSR-3 e
`contexto`. Falhas Redis distinguem `connect`, `auth` e `ping`; falhas do
roteador e da inbox usam `error`. `LOGS_ATIVO=false` desativa o arquivo de
rotina, mas mantém o STDERR JSON quando `iniciarLoggerCli()` foi chamado.
Os handlers continuam com diretório 0700, arquivo 0600, lock e fallback.

A entrada recebida e o replay se ligam por `context.update_id`, sem registrar
payload. Cada tentativa tem `context.attempt`; cada item do replay ganha
`request_id` próprio e herda `context.job_id` da execução. Ao sair do item,
o contexto anterior é restaurado. Saída com `exit` ainda registra a reserva.

Logs Telegram conservam somente status, código, descrição limitada,
message_id e retry_after. Conteúdo, entidades, mídia e resposta integral não
entram; recibos do banco e contabilidade de negócio continuam completos.
`message is not modified` usa debug. Falhas reais Telegram forçam o arquivo
mesmo com LOGS_ATIVO=false no FPM; recusas esperadas e avisos recuperados
continuam obedecendo à configuração. A descrição também é limitada no msg.

O Monolog conserva a rotação diária de 14 arquivos. A política proposta em
`config/logs-orcamento.json` também explicita o orçamento de 256 MiB e os
arquivos legados adicionais. Execute `php scripts/operacao/auditar_logs.php`
com `DEALNI_LOGS_DIR` apontando ao diretório a inspecionar: o comando informa
volume e candidatos fora de retenção em JSON. Ele nunca remove arquivos,
não instala cron e não aplica o orçamento automaticamente. Revisar o inventário
antes de qualquer limpeza; não confundir 14 dias com garantia de teto em bytes.

## Monitoramento e alertas

**Não há SLO numérico**: sem telemetria, ninguém poderia verificá-lo.

| SLI candidato | Como se mede hoje | O que falta |
|---|---|---|
| Disponibilidade | `/health` e `/ready` e o monitor local | sonda externa |
| Prontidão | `/ready` 503 e canal `prontidao` | histórico |
| Erro por update | canal `roteador_erros`, à mão | contador por janela |
| Latência | `duration_ms` no canal `webhook`, por update, lido com `jq` | percentis e alerta |
| Backup | canal `snapshot_banco` e avisos; [verificador de recência opcional](DADOS.md#monitor-independente-de-recência-do-snapshot-diário) no checkout | confirmar instalação/entrega do monitor e restauração real |
| RPO/RTO | não se mede; `composer db:restore:test` prova só o mecanismo | ensaio com snapshot real e cronômetro |

### Saúde e prontidão

Rotas públicas, sem autenticação, fora do `access_log`, com
`fastcgi_connect_timeout 3s` e `fastcgi_read_timeout 5s`.

| Rota | Responde | Toca dependência? |
|---|---|---|
| `/health` | `200 {"status":"ok","service":"dealnibot","version":"..."}` | não |
| `/ready` | `200`/`503` com `checks` (`postgresql`, `redis`: `ok`/`erro`) | `SELECT 1 FROM usuarios LIMIT 1` e `PING`, 2 s cada |

```bash
curl -s https://bot.example.com/health
curl -s https://bot.example.com/ready | jq .checks
```

### Alertas de saúde

O monitor local consulta `/health` e `/ready` em `DEALNI_PUBLIC_BASE_URL`,
registra falha e recuperação e limita avisos repetidos de acordo com
`DEALNI_MONITOR_ALERT_INTERVAL_SECONDS`. A cobertura do cron depende do
`MAILTO` ou de `DEALNI_ALERTA_CHAT_ID`. Um monitor fora do host consegue
detectar falhas de acesso ao servidor.

Se `/health` responder e `/ready` indicar erro, verifique separadamente
PostgreSQL e Redis. Se ambas falharem, confira Nginx, o serviço PHP-FPM,
DNS e TLS. Os eventos de prontidão aparecem em `channel=prontidao`.

### Aviso de falha no Telegram

`scripts/operacao/avisar_se_falhar.sh JOB [--php] COMANDO...` roda o job,
repassa STDOUT e STDERR e devolve o código dele. Com saída diferente de zero,
chama `scripts/operacao/avisar_falha.php`, que manda ao chat de
`DEALNI_ALERTA_CHAT_ID`, pelo `TOKEN_BOT` do `inclusos/config.php`, sistema, job,
host, código e as últimas 15 linhas do STDERR (token de bot, senha em URL e
`senha=`/`token=` viram `<redigido>`). A falha deixa
`/var/lib/dealnibot/aviso-<job>.falhou` com o instante do aviso; a mesma falha
dentro de uma hora (`AVISO_JANELA_DE_REPETICAO`) não volta ao Telegram, porque o
replay roda a cada minuto e o monitor a cada cinco. Envio que não chegou grava
`0` e a próxima falha tenta de novo. O próximo sucesso avisa "voltou a
funcionar" e apaga a marca. Os jobs são `webhook-inbox`, `snapshot` e
`monitor-saude`. Sucesso sem falha anterior não carrega a
configuração nem chama o Telegram. Sem `DEALNI_ALERTA_CHAT_ID` o aviso fica
desligado: a falha só escreve uma linha no STDERR. Aviso não entregue também
sai no STDERR, sem mudar o código do job. O `PHP85_BIN` passa pelas mesmas
conferências do `--php` do wrapper (absoluto, canônico sem symlink, PHP 8.5);
reprovado, o aviso fica desligado e o `--php` recusa o job com código 1.

O mesmo aviso cobre a conta recusada pelo OpenRouter: HTTP 401 (chave
inválida ou revogada) ou 402 (sem crédito) viram `error` em `erros_openai` e
mandam ao chat de `DEALNI_ALERTA_CHAT_ID` a chamada, o status e o host, no
máximo uma vez por hora para todas as chamadas. O 403 fica em `warn`, sem
aviso: no OpenRouter ele é também a moderação barrando o conteúdo de quem
escreveu, não a conta. Quem envia é o próprio processo que recebeu o erro (o FPM ou o
replay do cron), e a marca é `logs/aviso-openrouter.falhou`, porque o
diretório de logs é o único que o FPM grava. Não há mensagem de volta: a
próxima resposta da IA é a prova. Sem a variável no ambiente do FPM, sobra só
a linha `error`.

Para ativar o aviso em uma instância própria, indique em
`DEALNI_ALERTA_CHAT_ID` um chat que seu bot pode acessar. Atualize o ambiente
FPM para receber os avisos do OpenRouter. Use um chat de teste ao verificar a
entrega e lembre-se de que a verificação envia mensagens.

## Runbooks de incidente

### Bot mudo (nenhuma resposta)

1. `curl -s https://bot.example.com/ready | jq .`
2. `scripts/deploy/deploy_verify.sh --smoke` separa webhook desregistrado de
   serviço fora; `/ready` não fala com a Bot API.
3. Webhook ausente: `php config/config_webhook.php`.
4. Redis fora: suba o Redis; o `conectarRedis()` encerra antes do ACK e os
   updates são reentregues.
5. Smoke verde e bot mudo: canal `roteador_erros`; vazio, é o caso do
   `request_terminate_timeout` em [Diagnóstico](#diagnóstico-de-problemas-comuns).

### Publicação suspeita

1. `scripts/deploy/deploy_verify.sh` no host.
2. FPM não reiniciou depois do deploy: reinicie e rode de novo; até lá o
   OPcache pode servir o código anterior.
3. Sem systemd não há evidência equivalente: o verify reprova.

### Voltar o código (rollback de release)

1. `php config/config_remover.php`.
2. `ROLLBACK_CONFIRM=dealnibot scripts/deploy/rollback.sh` (ou
   `scripts/deploy/rollback.sh --para=<ref>` com o token), como root.
3. Reinicie o PHP-FPM e recarregue o Nginx.
4. `php config/config_webhook.php`.
5. `scripts/deploy/deploy_verify.sh --esperado=<sha anterior> --smoke`.

O banco não é tocado. Schema incompatível leva ao runbook seguinte.

### Voltar os dados (corrupção ou migração ruim)

Suspenda a entrada de updates e drene os processos da própria instância
antes de trocar de banco. Preserve a base de origem e restaure o dump
verificado em um banco vazio e isolado. Valide as migrations e o conteúdo
restaurado antes de adotar o novo DSN. Refaça o ambiente FPM e confira a
prontidão antes de retomar o webhook e os agendamentos. O comando e as
garantias da restauração estão em [DADOS.md](DADOS.md#restauração).

### Vazamento de credencial

Revogue a credencial exposta no provedor. Se a instância continuar em uso,
instale a substituta em `inclusos/config.php` ou no arquivo de ambiente,
conforme o [inventário](SEGURANCA.md#inventário-de-segredos), e atualize
todos os consumidores legítimos. Para um novo segredo do webhook, registre
o mesmo valor no Telegram. Após trocar a senha do banco ou do Redis,
regenere `fpm.environment` e reinicie o mestre FPM.

Caso a credencial tenha entrado no controle de versão, trate também
as referências e cópias que podem conter o valor. Revogar a credencial
e retirar o dado do histórico são verificações independentes.

### Banco indisponível ou travado

DSN errado, servidor fora ou falta de acesso falham explícito. Escrita presa é
lock de tabela: `lock_timeout` e `statement_timeout` devolvem erro em vez de
pendurar ([DADOS.md](DADOS.md#conexão)). Não aumente timeout para esconder
limpeza travada: confira plano, índices e progresso.

### Incidente de conectividade com o Telegram

O transporte do DealniBot para a Bot API força IPv4. Compare a conectividade
IPv4 e IPv6 com `api.telegram.org` e confira erros de transporte e saturação
do FPM.
Em uma instância própria, teste a entrega com seu bot e um chat de teste.

### Resposta de IA com entrega indeterminada

O aviso `Entrega de IA com resultado indeterminado; não reenviada` indica que
o checkpoint foi preservado após falha sem confirmação suficiente do Telegram.
O processamento do update pode estar concluído sem que a entrega esteja
confirmada. O bot não reenvia nem gera outra resposta automaticamente, para
evitar duplicidade; `/ready` não identifica essa pendência.

O aviso registra somente `codigo_erro` inteiro entre 1 e 599 quando disponível;
o transporte usa esse campo tanto para códigos da Bot API quanto para o errno
do cURL. Ele não comprova o status HTTP. Descrição, corpo da resposta e destino
não entram nesse diagnóstico. Ausência do código mantém o resultado ambíguo.

O log técnico da falha de transporte também registra, quando disponíveis,
`curl_http_status` e tempos cumulativos em milissegundos: `curl_dns_ms`,
`curl_conexao_ms` (host ou proxy), `curl_tls_ms`, `curl_primeiro_byte_ms` e
`curl_total_ms`. A captura usa apenas essas opções individuais do cURL, sem
URL, IP, cabeçalhos ou conteúdo. O HTTP 0 significa status não observado;
metadados ausentes ou inválidos são omitidos. Tempos devem ser finitos entre
zero e uma hora; o teto limita somente o diagnóstico, não a requisição.
Zero pode refletir cache ou granularidade do relógio, e os tempos não são
durações isoladas das fases. Nem HTTP 200 observado durante um timeout nem
primeiro byte comprovam entrega. O checkpoint é preservado e a resposta
permanece fora do replay.

Para dimensionar o caso, consultar apenas estados e datas da inbox, usando a
conexão operacional autorizada e uma transação somente leitura:

```sql
BEGIN READ ONLY;
SELECT entrega_ia_estado, count(*) AS quantidade,
       to_timestamp(min(recebido_em)) AS primeiro_update,
       to_timestamp(max(recebido_em)) AS ultimo_update
FROM webhook_inbox
WHERE entrega_ia_estado IN ('pendente', 'enviando', 'indeterminada', 'recusada')
GROUP BY entrega_ia_estado
ORDER BY entrega_ia_estado;
ROLLBACK;
```

As datas acima são de recebimento do update, não da última tentativa de envio.
Não imprimir `payload_json` nem `resposta_ia_json` em relatórios ou logs.
Uma revisão individual deve conferir a conversa antes de qualquer decisão
manual: reenviar, limpar o checkpoint ou alterar o estado exige autorização
específica. A retenção dos snapshots indeterminados é de sete dias, conforme
o [contrato do checkpoint](DADOS.md#checkpoint-de-entrega-de-respostas-de-ia).

## Diagnóstico de problemas comuns

| Sintoma | Causa provável |
|---|---|
| Bot mudo depois do deploy | webhook não registrado, ou segredo novo no config sem `setWebhook` (todo update vira 403) |
| Comando responde duas vezes | conferir a linha do `update_id` em `webhook_inbox`, a lease e se há dois crons |
| IA devolve erro genérico | canal `erros_openai`: 401/402 é a conta (chave ou crédito; chega o aviso ao dono), 403 é permissão ou moderação barrando a entrada (`warn`, sem aviso), 429/5xx é a API; errno diferente de zero é transporte |
| Bot calado num grupo que voltou a dar permissão | recusa de escrita lembrada por 6 h: `telegram:sem_escrita:<chat_id>` no Redis; vence sozinha, ou `DEL` da chave |
| Bot parou num grupo | cota do dia do grupo acabou (aviso omitido em grupo); ver `limite_diario:<id_do_grupo>:<AAAAMMDD>` |
| Mídia recusada em rajada | admissão: 5 por sujeito ou 20 no bot em 60 s; ver `dealnibot:midia:v1:*` |
| Sem IA no privado | esperado |
| Gasto subiu sem usuários novos | grupo movimentado consome a própria cota; conferir baldes de id negativo |
| 500 por falha de banco | `new bdTelegram()` fora de try/catch com `ecoarEEncerrar()` (o callback do polling é a exceção) |
| Webhook aponta para domínio incorreto | confira `DEALNI_PUBLIC_BASE_URL` no ambiente da instância e o endereço registrado no Telegram |
| `DEALNI_PUBLIC_BASE_URL deve ser uma URL HTTPS válida.` | variável mal preenchida no ambiente do FPM |
| Bot mudo, nada em `erros_openai` | Redis fora: `erros_redis` e encerramento antes do ACK |
| Leu e não respondeu, com exceção | canal `roteador_erros`; após o ACK não há reentrega do Telegram, mas o cron retoma a linha com backoff |
| Leu e não respondeu, sem nada no log | conferir status e lease em `webhook_inbox`, logs do mestre e pool; `request_terminate_timeout_track_finished = no` desliga o teto de 330 s após o ACK |
| Hash divergente de migration | migration publicada foi editada; corrija em arquivo novo |
| `composer test` recusa rodar | PHPUnit direto ou rede viva ([TESTES.md](TESTES.md#sandbox-de-rede)) |
| `redis-cli` mostra `AUTH failed` e depois `PONG` | `REDISCLI_AUTH` vazio herdado do ambiente: o Redis do bot não tem senha. Rode `env -u REDISCLI_AUTH redis-cli -p <porta> PING` e só aceite `PONG` exato com saída 0 |
| 403, 500 ou 502 logo depois de publicar | arquivo novo ilegível para o usuário do bot: confira o proprietário, o grupo e as permissões do checkout. Confira com `stat` os arquivos do commit e atualize o checkout com `umask 022` |
| `Could not open input file: /dev/stdin` | script PHP mandado pelo stdin ao `executar_com_ambiente.sh --php`; passe o caminho de um arquivo `.php` legível pelo usuário do bot |

## Procedimentos específicos

### Servidor web

Nginx em subdomínio próprio, checkout em `/var/www/dealnibot`. `deploy/` é
modelo: nada é aplicado sozinho, nem por `scripts/install/provision_host.sh`.

| Arquivo | Instalar como |
|---|---|
| `deploy/nginx/dealnibot.conf` | `/etc/nginx/sites-available/dealnibot`, habilitado em `sites-enabled` |
| `deploy/php-fpm/dealnibot.conf` | `/etc/php/8.5/fpm/pool.d/dealnibot.conf` |
| `deploy/php-fpm/dealnibot-master.conf` | `/etc/php/8.5/fpm/dealnibot-master.conf` |
| `deploy/systemd/php8.5-fpm-dealnibot.service` | unit do mestre exclusivo |
| `.env.example` | `/etc/dealnibot/environment` |
| `config/crontab.txt` | `/etc/cron.d/dealnibot` |

O virtual host expõe `/` e `/index.php` para `GET`/`HEAD`. Ambas redirecionam
com 302 quando `DEALNI_BOT_USERNAME` está configurado e respondem 404
quando vazio. `/health` e `/ready` aceitam `GET`/`HEAD`, e `/bot_web.php`
aceita `POST`. Confira herança de cache no Nginx instalado. Webhook e
página têm `fastcgi_read_timeout 330s`. Consulte as
[rotas públicas](SEGURANCA.md#superfície-exposta).

Substitua `bot.example.com` pelo domínio da sua instalação. Configure um
certificado válido para esse domínio antes de registrar o webhook. Se usar
Certbot com o plugin Nginx, aplique-o ao virtual host já instalado. O Certbot
edita o arquivo instalado, então preserve o bloco TLS ao atualizar o modelo.
Valide a configuração com `nginx -t` antes de recarregar.

### Isolamento do pool e do usuário

Ordem segura: criar o usuário `dealnibot`, ajustar permissões, instalar o pool,
reiniciar o FPM, habilitar o virtual host, `nginx -t`, `systemctl reload nginx`
e só então o TLS. O vhost aponta para o socket do pool isolado: instalado antes
do pool, o `nginx -t` passa e o site devolve 502.

O pool isola usuário, socket, ambiente (`clear_env = yes`) e capacidade, mas
**não é uma fronteira completa de segurança**: pools do mesmo mestre
compartilham, por exemplo, o OPcache. As permissões continuam obrigatórias.

O checkout pertence ao root. Em `/var/www`, `instalar_linux.sh` (via
`scripts/install/provision_host.sh`) aplica `root:dealnibot` à árvore, tira escrita do
grupo e acesso de outros, e deixa a raiz em `0751`. O `config.php` fica
`root:dealnibot 0640`. O runtime grava só em `logs/`, em `DEALNI_SNAPSHOTS_DIR`
(criados com dono `dealnibot` e `0700`, sem symlink), no diretório temporário
do sistema e em `/var/lib/dealnibot`, que o mesmo instalador cria com `cron/`
dentro (os dois `dealnibot 0700`) e os locks do cron `0600`. Snapshot fora do
checkout só é criado se a variável estiver no ambiente do instalador.

```bash
useradd --system --home /var/www/dealnibot --shell /usr/sbin/nologin dealnibot
chown root:dealnibot /var/www/dealnibot/inclusos/config.php
chmod 0640 /var/www/dealnibot/inclusos/config.php
install -d -o dealnibot -g dealnibot -m 0700 /var/www/dealnibot/logs /var/www/dealnibot/snapshots
```

O pool usa `pm = dynamic`, `pm.max_children = 8` (o aviso
`server reached pm.max_children` vai para o `error_log` do mestre:
`/var/log/php8.5-fpm-dealnibot/error.log` no exclusivo, `/var/log/php8.5-fpm.log`
no global; **não** para o journal), socket `0660` de `www-data`,
`request_terminate_timeout = 330s`, `request_terminate_timeout_track_finished = no`
(o teto não vale depois do ACK; o pool registra 365 s como pior caso previsto
do áudio, somando os timeouts de rede) e
`security.limit_extensions = .php`. Nenhuma página de status do FPM é publicada.

### Mestre FPM exclusivo no host

O mestre `php8.5-fpm-dealnibot.service` carrega exclusivamente o pool
definido por `/etc/php/8.5/fpm/dealnibot-master.conf`. Sua unit lê
`/etc/dealnibot/fpm.environment`. Os modelos de isolamento do PHP-FPM e do
Redis referenciam `bots-redis-isolation.service`. Antes de instalar as
unidades, providencie a dependência de isolamento ou adapte os modelos para
uma fronteira equivalente no seu ambiente.

### Primeira instalação em host novo

1. Prepare um checkout Git em `/var/www/dealnibot` com a referência que
   deseja publicar disponível em `origin/main`. Instale o runtime, as
   extensões, PostgreSQL, Redis, Nginx e Composer conforme os
   [pré-requisitos](#pré-requisitos). Crie o usuário e os diretórios definidos nos
   [modelos](#isolamento-do-pool-e-do-usuário). Os scripts
   `provision_host.sh` e `instalar_linux.sh` exigem o supervisor e o lock
   externos de [coordenação dos backups](#rotinas-automáticas). Use o
   provisionamento manual em um host que dispense essa infraestrutura.
2. Criar `/etc/dealnibot/environment` a partir de `.env.example`, com dono
   `root:dealnibot` e modo `0640`. Substitua o hostname de exemplo pela URL
   HTTPS da sua instalação e preencha `DEALNI_BOT_USERNAME` para ativar o
   redirecionamento e reconhecer menções ao novo nome. O preparador do banco
   exige esse arquivo e o usuário de sistema já existente.
3. `DEPLOY_CONFIRM=dealnibot scripts/deploy/preparar_postgres.sh`: cria banco,
   papel e `/etc/dealnibot/postgresql.environment`. Conferir o candidato e
   adotá-lo como `/etc/dealnibot/environment`, preservando `0640` e o grupo.
   O script não troca o ambiente ativo sozinho.
4. `bash scripts/install/configurar_credenciais.sh`, preencher as três
   constantes e fixar `inclusos/config.php` em `root:dealnibot 0640`.
5. `scripts/operacao/executar_com_ambiente.sh --php scripts/deploy/migrate.php`
   e o mesmo com `--status` (o runner não carrega o ambiente sozinho).
6. `DEPLOY_CONFIRM=dealnibot scripts/deploy/preparar_fpm.sh`; instalar o pool
   e a unit do mestre **dedicado**, executar `systemctl daemon-reload` e manter
   esse FPM parado até a publicação. As credenciais do passo 4 precisam existir
   antes da validação. O fluxo novo não aceita o mestre compartilhado.
7. Instalar virtual host e certificado ([Servidor web](#servidor-web)).
8. Conferir os locks e instalar `config/crontab.txt` em `/etc/cron.d/dealnibot`
   ([Rotinas](#rotinas-automáticas)) antes do publicador, para que a suspensão
   e drenagem sejam comprovadas. Registre o webhook pelo ambiente do host:
   `scripts/operacao/executar_com_ambiente.sh --php config/config_webhook.php`.
   Enquanto o FPM está parado, o Telegram mantém os updates para reentrega.
9. `DEPLOY_CONFIRM=dealnibot scripts/deploy/publicar.sh --esperado=SHA_COMPLETO`.
   O publicador prepara, ativa e verifica, inclusive o webhook que o smoke
   espera já registrado.
10. Conferir o resultado de `/ready` e do smoke automático. Essa verificação
    não prova resposta real da IA nem entrega de mídia no Telegram.

## Publicação serializada e recuperação de falha

O fluxo canônico é `DEPLOY_CONFIRM=dealnibot scripts/deploy/publicar.sh
--esperado=SHA_COMPLETO_DE_ORIGIN_MAIN`. O publicador exige `main` limpa,
confirma o SHA após `fetch` e adquire um lock exclusivo root em
`/var/lib/chatbots-publicacao/dealnibot.lock`. O mesmo descritor permanece aberto
na atualização do checkout, preparo, restart e verificação. Após rollback,
aceita também HEAD detached quando o carimbo comprova `origem=rollback`, commit,
checkout e bot corretos e a árvore está limpa. A branch `main` precisa ser
ancestral do alvo; o switch para ela só ocorre após a suspensão e drenagem.
Publicação e rollback
exigem mestre FPM dedicado; o verificador ainda diagnostica instalações antigas
com mestre compartilhado.

Antes de mudar código, o publicador inventaria `/etc/crontab`, os crontabs de
usuários, `/etc/cron.d`, diretórios periódicos e timers systemd. Referências
extras ao checkout ou timers próprios fora do inventário recusam a operação.
Este bot usa `/etc/cron.d/dealnibot` e inventaria dois timers opcionais:
`dealnibot-snapshot-recencia.timer` e `dealnibot-backup-local.timer`, quando
instalados. Ambos têm estado suspenso e retomado pelo publicador, além do cron.
A suspensão move somente esse cron para o diretório privado do lock, aguarda
65 segundos para a releitura do cron, para o FPM dedicado e aguarda até
60 segundos pelos processos do checkout. Nenhum job é morto pela drenagem.
O scanner capturado antes da troca e as funções carregadas no shell também
permitem voltar a um checkout antigo. Depois do sucesso, cron e agendamentos
anteriores são restaurados; nenhum cron global é parado.

`DEPLOY_CONFIRM=dealnibot scripts/deploy/deploy.sh` é somente o preparo do HEAD
atual: exige a prova de suspensão/drenagem e serviços parados. Atualizar código
manualmente fora do publicador não participa do lock. Use o publicador para o
fluxo regular. O `rollback.sh` usa o mesmo lock, suspensão e drenagem,
regenera o ambiente FPM do destino, ativa e verifica antes de restaurar o cron.
O verificador exige `/ready` pelo FPM com versão, serviço, PostgreSQL e Redis
corretos, além de `/health` e do smoke. Republicar o mesmo SHA mantém como
`commit_anterior` o último SHA distinto.

Falha anterior à alteração do checkout tenta restaurar apenas os serviços
antes ativos e seus agendamentos. Se a operação já começou com agendamentos
suspensos por uma falha anterior, eles permanecem suspensos. Falha após alteração
mantém serviços parados e cron suspenso, com mensagem de publicação incompleta.
Não restaura o banco.

Antes da troca, o fluxo preserva ferramentas e um manifesto privado em
`/var/lib/chatbots-publicacao/dealnibot.recuperacao`. O manifesto contém projeto,
SHA de origem e de destino, SHA das ferramentas, estado e hashes dos arquivos;
não contém ambiente nem credenciais. Essa cópia continua disponível se o
checkout antigo não possuir os helpers novos. Confira a causa antes de retomar:

```bash
RECOVERY_CONFIRM=dealnibot /var/lib/chatbots-publicacao/dealnibot.recuperacao/scripts/deploy/recuperar_publicacao.sh
```

O runner exige root, diretório privado canônico, permissões e hashes corretos,
manifesto incompleto e HEAD correspondente ao destino. Só essa prova permite
retomar o mesmo SHA; repetir um rollback comum para o HEAD atual continua
recusado. A retomada de um deploy executa backup e migrations do alvo antes de
reinstalar dependências; retomada de rollback não altera o banco. Ambiente FPM,
restart e `/ready` são verificados antes de restaurar os agendamentos. Uma nova
publicação recusa um bundle incompleto: use a recuperação indicada.
