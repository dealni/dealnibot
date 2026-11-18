# Testes — DealniBot

Taxonomia, como rodar, instáveis, invariantes, portões, números que
não podem piorar, hooks e CI. Como **escrever** um teste está em
[CONVENCOES.md](CONVENCOES.md#testes); o setup da máquina, em
[OPERACAO.md](OPERACAO.md#pré-requisitos).

## Taxonomia de testes

A categoria é decidida pelo **diretório**; `composer check:tests` confere cada
arquivo contra o bloco de dados de `scripts/quality/check_taxonomia_testes.php`.

| Categoria | Comando | Onde mora | O que é |
| --- | --- | --- | --- |
| unit | `composer test:unit` | `tests/Unit/` | regra isolada, sem I/O externo |
| integration | `composer test:integration` | `tests/Integration/` | PostgreSQL temporário, scripts combinados, mais de um componente |
| contract | `composer test:contract` | `tests/Contract/` | manifestos, portões, artefatos de host (FPM, Nginx, cron, Redis) e interfaces internas |
| security | `composer test:security` | `tests/Security/` | origem do webhook e superfície HTTP |
| performance | `composer test:performance` | `tests/Performance/` | plano de consulta e carga mínima |
| smoke | `composer test:smoke` | `scripts/quality/run_smoke.php` | somente leitura contra o bot publicado; exige `-- --confirm-production` |

`tests/Support/` não é suíte: guarda espiões e dublês (`AppLogReader`,
`RedisFalsoComLua`, `TransporteHttpEspiao`, `ExtratorDeFuncoesDeScript`…).
Alguns testes de portão antigos seguem em `tests/Unit/`
(`ContratoDeReleaseTest`, `QualityGatesTest`); contratos novos vão para
`tests/Contract/`.

Exceção a [Nunca execute os scripts de topo](CONVENCOES.md#nunca-execute-os-scripts-de-topo):
`AvisarSeFalharTest` roda `scripts/operacao/avisar_falha.php` de verdade, em
subprocesso, só nos caminhos que não saem da máquina (argumentos inválidos,
sucesso sem falha anterior e chat não configurado), que não carregam a
configuração do bot. O envio ao Telegram é coberto por dublê em
`AvisoDeFalhaTest`.

| Categoria | Situação aqui |
| --- | --- |
| `test:e2e` | **não se aplica.** A interface é o Telegram: um e2e exigiria bot real e gastaria a chave do provedor a cada execução. O verificável sem isso é o `test:smoke` |

## Testes de navegador

`test:browser` e `test:browser:remote` não se aplicam ao bot: a interface é o
Telegram, sem frontend web próprio. Chromium e Cloudflare Browser Run não
substituem os testes de comandos, webhook, filas e integrações acima.

`test:smoke` verifica a saúde dos serviços por HTTP; não executa JavaScript,
não verifica telas no Chrome e não constitui monitoramento de navegador.
Sua execução contra produção continua dependendo de autorização específica.

## Como rodar

```bash
composer test                                # suíte inteira, ordem sorteada
composer test -- --filter NomeDoTeste        # um teste
composer test -- --order-by=reverse          # a suíte também tem de passar assim
composer test -- --random-order-seed=1234    # repete o sorteio de uma falha
composer test:unit                           # uma categoria (idem as demais)
composer test:smoke -- --confirm-production  # só depois de publicar
```

Rodar `vendor/bin/phpunit` direto é recusado pelo bootstrap. O
`tests/bootstrap.php` exige `APP_ENV=test`, `CHATBOTS_TESTING=1` e rede
isolada, aponta `DEALNI_LOGS_DIR` para um diretório temporário e não define
constante; o `config_loader.php` fornece credenciais fictícias em teste. Todo
`failOn*` do `phpunit.xml` está ligado.

### O banco de teste

Suíte e `composer check:postgres` exigem um **PostgreSQL
descartável**, nunca o da VPS. Sem `CHATBOTS_PG_TEST_DSN`, os runners se
relançam por `scripts/quality/with_postgres.sh`, que cria um cluster com socket
Unix, sem TCP, e o remove no fim (`CHATBOTS_PG_BIN` aponta os binários). Com
banco próprio, informe `CHATBOTS_PG_TEST_DSN`, `DEALNI_DB_USER` e
`DEALNI_DB_PASSWORD`; o nome do banco precisa começar com `chatbots_test_` e o
schema `public` precisa estar vazio. O banco precisa ser UTF-8: com `SQL_ASCII`
a suíte dá erro de Unicode e de lock. Cada fixture usa schema próprio. A suíte
também sobe um Redis descartável.

### Sandbox de rede

`composer test` roda com a rede desligada pelo SO: `sandbox-exec` no macOS e
`unshare` no Linux. No macOS, `RuntimeEnvironment::hasNetworkIsolation()` exige
que as duas sondas de socket recebam `EPERM`. No Linux, consulta as interfaces
da namespace atual por `net_get_interfaces()` e só aceita `lo`; falha, lista
vazia ou qualquer outra interface recusam a suíte. O runner de teste usa essa mesma decisão, sem inferir isolamento pelo PID 1 ou pelo sysfs.

## Testes instáveis e quarentena

Conferido por `composer check:tests`:

- **Foco commitado é proibido**: `--filter`/`--group` fixos no `composer.json`.
- **Nada de esperar pelo relógio**: `sleep`/`usleep` em teste.
- **Todo pulo é registrado** em `tools/quality/testes-em-quarentena.json`, com
  motivo, data e responsável; registro sem pulo também reprova.
- **Quarentena tem prazo** e não substitui testes executados. `guardas_de_ambiente`
  são condições de máquina. `failOnSkipped` reprova a suíte quando um teste é pulado.
- **Máquina carregada não é teste instável**: com carga acima de ~10
  (`uptime`), caem os testes de 5 s e o timeout do Composer. Espere a carga
  baixar e repita; não aumente timeout nem ponha o teste em quarentena.

Também reprovam: teste fora de categoria, comando de categoria com diretório
vazio e categoria não aplicável que ganhou comando, arquivo ou perdeu a linha
da tabela acima. Hoje a quarentena está vazia e há uma guarda de ambiente
(`FuncoesHelpersTest::pularSeStubImpossivel`).

## Invariantes críticas

Antes de mexer numa destas, confirme que o teste **falha** ao desfazer a
garantia.

| Invariante | Travada por |
| --- | --- |
| Cota: reserva atômica, falha fechada, bloco travado na fonte | `LimiteDiarioLuaTest`, `LimitesRequisicoesTest`, `FluxoIaRateLimitTest`, `ProvedorOpenAiContratoTest` |
| Erro do provedor vira aviso; `error.message` não vai ao chat | `FluxoIaTextoChatgptTest`, `ProvedorOpenAiContratoTest`, `TransporteHttpOpenAiTest` |
| Entrega da resposta: fallback de Markdown, checkpoint/429 no webhook e tentativa curta no polling; sem entrega, sem turno | `EntregaRespostaIaTest`, `EntregaIaDuravelTest`, `FluxoIa*Test`, `WebhookInboxTest`, `WebhookInboxLeaseTest` |
| Memória rolante por participante, CAS com cinco tentativas | `MemoriaConversaTest`, `FluxoIaAudioTest`, `FluxoIaImagemTest` |
| Replay CLI: código de falha, resumo, backoff e propagação pelo wrapper sem envios | `WebhookInboxCliTest` |
| Mídia: temporário em `finally`, MIME pelos bytes, token nunca ao provedor, deadline total e teto de download | `FluxoIaAudioTest`, `FluxoIaImagemTest`, `FormatoDeImagemTest`, `DownloadTelegramPrazoTotalTest` |
| Resposta de IA: navegação segura em JSON e detalhe privado somente escalar | `RespostasDeChatContratoTest`, `ProvedorOpenAiContratoTest` |
| Polling: `return` em vez de `exit`, nenhuma função em script roteado | `InfraRoteadorPollingTest`, `PollingLoopTest`, `FuncoesDeComandoSaoInertesTest` |
| ACK, persistência, replay e concorrência por `update_id` | `WebhookTelegramOrigemTest`, `WebhookInboxTest`, `ConcorrenciaEReplayTest` |
| Retenção da inbox em lotes indexados | `RetencaoInboxLotesTest`, `IndicesOperacionaisTest` |
| PostgreSQL como único banco | `PostgresExclusivoTest`, `PostgresOperacaoCliTest`, `BancoConfiguracaoTest` |
| Allowlist HTTP de quatro rotas | `BloqueioHttpDeArquivosTest` |
| Coerção explícita de contagens Redis e composição PSR-4 da API Telegram | `LimitesRequisicoesTest`, `TelegramApiPublicaTest` |
| Log sem segredo; falha da Bot API sem payload | `ContratoDeObservabilidadeTest`, `TelegramRespostaApiTest` |
| `request_id`, `version` e sondas | `ContextoDeExecucaoTest`, `ContextoDeExecucaoGitTest`, `SondasDeDependenciaTest`, `SondasHttpTest` |
| `duration_ms` monotônico no fim do atendimento do webhook e do snapshot | `DuracaoDoAtendimentoTest`, `SnapshotBancoTest` |
| Contrato de release e artefatos de deploy | `ContratoDeReleaseTest`, `DeployAssetsTest`, `FpmMestreExclusivoTest`, `ContratoDoRedisTest` |
| Checkout do root: runtime só lê o código | `PermissoesInstalacaoTest` |
| Aviso de falha: código do job preservado, log redigido, marca de recuperação, IPv4 e `PHP85_BIN` conferido | `AvisoDeFalhaTest`, `AvisarSeFalharTest` |
| Sem estado global no código de produção | `SemEstadoGlobalTest` |
| Inventário de segredos completo | `InventarioDeSegredosTest` |
| Nenhuma menção a runtime anterior ao 8.5 | `LegadoDeRuntimeAntigoTest` |
| Orçamento de desempenho | `OrcamentoDeDesempenhoTest` |
| Portões (taxonomia, tamanho e links) | `PortaoDeTaxonomiaDeTestesTest`, `PortaoDeTamanhoTest`, `QualityGatesTest` |

Acessibilidade e orçamento de bundle **não se aplicam**: não há UI própria nem
bundle.

### Contrato público de Telegram

O snapshot próprio `telegram-api-publica.json` registra assinaturas completas:
parâmetros, tipos, defaults, referências, variádicos, retorno e `static`.
`TelegramApiPublicaTest` compara conteúdo legível e aponta a divergência.
Arquivo, linha e trait são metadados de implementação e ficam fora da API.

O snapshot deste projeto é validado isoladamente.

## Portões de qualidade

| Comando | O que roda |
| --- | --- |
| `composer check` | `check:config`, `check:config:production`, `check:config:examples`, `check:env`, `check:postgres`, `check:editorconfig`, `check:shell`, `check:docs`, `typecheck`, `format:check`, `test` |
| `composer check:base` | folhas locais de runtime, taxonomia, complexidade, tipos, release, licenças, segredos, ferramentas, configuração, PostgreSQL, estilo, dependências, segurança e duplicação; sem PHPUnit |
| `composer check:all` | `check:base` e `test` completo em ordem aleatória |
| `composer check:online` | `check:vulnerabilities` e `npm run check:vulnerabilities` |
| `composer check:ci-local` | `CI=1`, `check:all:run` completo em ordem aleatória e `check:online` |

Escolha um agregado: `check:all` para a validação offline ou `check:ci-local`
quando também precisar das auditorias online. O segundo chama a mesma
composição offline e executa cada suíte uma vez; rodar os dois em sequência
repete o trabalho. Use o wrapper `with_local_config.sh` em ambos para manter
PostgreSQL descartável e configuração sintética.

O recibo é automático e fica somente em `.git/quality-validation`, por até
30 minutos. O pre-push o reutiliza apenas com checkout limpo, commit,
ambiente, ferramentas e entradas equivalentes. Alteração, expiração ou recibo
inválido faz o hook executar todos os gates. Não copie esse diretório entre
checkouts ou para a VPS: deploy valida seu próprio runtime e estado publicado.

Smoke, `check:toolchain` e os relatórios ficam fora dos agregados.

| Portão | O que trava |
|---|---|
| `check:tests` | taxonomia e instáveis |
| `check:complexity` / `complexity:baseline` | ratchet de 500 linhas físicas por arquivo e complexidade 15 por função; regrava a dívida |
| `check:strict-types` | `.php` sem `declare(strict_types=1)` fora de `tools/quality/strict-types-baseline.json` |
| `typecheck` | PHPStan `max` com extensões, sem baseline |
| `format:check` / `format` | php-cs-fixer (`@PSR12`) |
| `check:security` | Semgrep, com autoteste das regras |
| `check:duplication` / `duplication:baseline` | PHPCPD com tetos de clones e linhas |
| `check:postgres` | PostgreSQL descartável: migrations, conexão, concorrência e lease da inbox |
| `check:deploy-guards` | recusas de `deploy`, `rollback` e `release:check` e ensaio de restauração |
| `check:gitleaks` | gitleaks 8.30.1 no histórico, com contraprova |
| `check:config` / `check:env` | `composer validate --strict` / `.env.example` contra o schema |
| `check:deps:undeclared` / `check:dead-code` | símbolo sem pacote / pacote sem uso |
| `check:editorconfig`, `check:shell`, `check:docs` | whitespace, ShellCheck e links locais dos `.md` |
| `check:vulnerabilities` / `check:licenses` | ver [SEGURANCA.md](SEGURANCA.md#portões-de-segurança) |
| `check:workflows`, `npm run check:composite-actions` | actionlint e a composite action; fora do `check:all`; execute ao alterar `.github/` |
| `rector` / `deps:outdated` | relatórios; não reprovam |

Relatórios e caches vão para `.artifacts/`, ignorado pelo git.

### Duplicação (PHPCPD)

`systemsdk/phpcpd` exige uma linha de `sebastian/cli-parser` que conflita com o
PHPUnit do bot, por isso mora em `tools/phpcpd/`, com `composer.json` e
`composer.lock` versionados e `vendor/` próprio (`composer install -d tools/phpcpd`).
Sem ele, `run_phpcpd.php` sai com status 2: nunca falso verde. Usa
`--min-lines=10 --min-tokens=70`. PHPMD ficou de fora
([motivo](REFERENCIAS.md#ferramental-fora-do-manifesto)).

## Números que não podem piorar

| Portão | Onde mora o valor | Regra |
|---|---|---|
| Tamanho e complexidade | `tools/quality/tamanho-baseline.json` | a dívida não cresce e o escopo não encolhe |
| Duplicação | `tools/quality/duplicacao-baseline.json` | tetos preservados |
| `strict_types` | `tools/quality/strict-types-baseline.json` | a lista só encolhe; arquivo novo nasce com a declaração |
| PHPStan | `phpstan.neon` | nível max, sem baseline |

Regrave baseline só com mudança de escopo proposital, em commit próprio. Com `CI=1` os
ratchets ficam somente leitura.

## Hooks

| Hook | O que roda |
|---|---|
| `pre-commit` | `bash scripts/quality/check_staged.sh`: caminhos proibidos, gitleaks no índice (sem gitleaks, reprova), sintaxe, Composer e links Markdown na árvore do índice (inclui staging parcial) |
| `commit-msg` | `npm exec --offline -- commitlint --edit "{1}"` |
| `pre-push` | `bash scripts/quality/with_local_config.sh composer check:all`, sem regravar baselines |

`with_local_config.sh` fixa `CI=1`, recusa rodar sob `/var/www/` e sobe o
PostgreSQL descartável. Os hooks são instalados pelo `npm ci` (`prepare` roda
`lefthook install --reset-hooks-path`); `lefthook.yml` usa o binário de
`node_modules` e `assert_lefthook_installed: true`. O `commitlint.config.mjs`
estende `@commitlint/config-conventional`; as regras de mensagem estão em
[CONVENCOES.md](CONVENCOES.md#commits-e-hooks).

## CI

Os resultados remotos estão no histórico de execuções do GitHub Actions,
quando o serviço estiver disponível. A execução local registra evidência
apenas das verificações que rodaram no ambiente local.

Na composite action local, o Node sobe com cache desativado; o bootstrap global
instala o npm exato de `packageManager` fora do checkout e verifica sua versão.
Só então o setup-node restaura o cache nos jobs que instalam dependências.
O caminho sem dependências também fixa npm, sem restaurar cache. Os comandos
do projeto preservam `devEngines`; `ignore-scripts` mantém o comportamento
explícito dos jobs que já o declaram, sem desabilitar outros lifecycle scripts.
O contrato Redis roda com `php -n`, carregando apenas tokenizer quando ele
não for built-in; a extensão phpredis permanece ausente e o dublê não abre rede.

Nos jobs de CI, o CGI vem do `setup-php`, que também seleciona suas
alternativas; o apt instala somente `redis-server`. O pacote `php8.5-cgi` não
estava disponível no runner observado. Antes das dependências e dos testes,
`scripts/quality/check_php_cgi.sh` exige o binário irmão de `PHP_BINARY`, com
SAPI `cgi-fcgi` e versão exata da CLI. Ausência, versão divergente ou falha do
binário interrompem o job; nenhum teste HTTP é pulado. O contrato
`SetupPhpContratoTest` cobre essas recusas e os passos dos workflows.
As fontes do provisionamento estão em [REFERENCIAS.md](REFERENCIAS.md#ci).

A namespace de rede é criada com privilégio por `unshare --net`; em seguida,
`setpriv --reuid`, `--regid` e `--clear-groups` devolvem a execução ao UID/GID
do runner e removem grupos suplementares antes da suíte. `HOME`, `USER` e `LOGNAME` também preservam a identidade do runner,
sem herdar o ambiente completo pelo sudo. Os testes de permissões rodam sem privilégios de
root. As fixtures passam explicitamente o usuário e a senha do cluster
sintético aos subprocessos e à conexão PDO de teste; o DSN continua sem
credenciais e não são criados papéis com o nome do usuário do sistema. O contrato `SetupPhpContratoTest` confere essas condições nos workflows.
O executor `scripts/quality/check_ci_namespace.sh`, chamado depois de `setpriv`,
confere UID não zero, GID esperado e namespace de rede diferente da capturada
antes do sudo; só então executa o comando real. Os jobs registram caminhos e
versões de `/usr/bin/unshare`, `/usr/bin/setpriv` e do pacote `util-linux`.


O `pre-push` local continua obrigatório; registre a execução e seus limites. Não declare CI remoto aprovado com base em configuração ou validação local.

### Mapa dos workflows

| Workflow | Job | O que roda | Gatilho |
|---|---|---|---|
| `ci.yml` | `commit-messages` | commitlint nos commits novos | push em `main`, PR |
| `ci.yml` | `tests` | suíte em ordem aleatória e reversa, sob `unshare --net` | push em `main`, PR, dispatch |
| `ci.yml` | `quality` | portões locais, análise estática e estilo | idem |
| `ci.yml` | `workflows` | ShellCheck, actionlint e composite actions | idem |
| `ci.yml` | `reports` | Rector e `composer outdated` | idem |
| `ci.yml` | `postgresql` | `composer check:postgres` com PostgreSQL 16 e 17 | idem |
| `security.yml` | `dependencies`, `gitleaks`, `semgrep` | auditorias, licenças, segredos e Semgrep | push em `main`, PR, segunda 05:27 UTC, dispatch |

Actions fixadas por SHA; binários externos com versão e SHA-256 conferidos no
workflow; nenhum `secrets.*` ([versões](REFERENCIAS.md#ci)).

## Runtime, ordem e validação local

`composer check:runtime` confere os pins de PHP e Composer e os requisitos de
plataforma. `composer check:toolchain` acrescenta a validação de `check:all`.
A composição única dos agregados está em [Portões de qualidade](#portões-de-qualidade).

As fixtures mantêm PostgreSQL e Redis descartáveis e isolamento da rede.
`with_local_config.sh` remove a DSN herdada antes de iniciar o agregado;
as guardas de banco vazio, migrations e restauração continuam obrigatórias.

Os testes dos agregados expandem as folhas para rejeitar omissões e
execuções repetidas. O runner compara os bindings do plano com os scripts
reais antes de emitir recibo; divergências invalidam o reuso do `check:all`.
O inventário de `npm run test:quality-tools` inclui os testes de identidade,
ambiente, recuperação e bindings dos recibos.

## Validação antes do push

O [pre-push](#hooks) exige `check:all` completo e offline; `check:ci-local`
acrescenta auditorias online. O [gate de licenças](SEGURANCA.md#portões-de-segurança)
lê os locks locais de Composer raiz, PHPCPD e npm, sem instalar ferramentas.

Execute uma suíte pesada por vez, inclusive entre repositórios. Os testes
Node usam `--test-concurrency=1`; o PHPUnit conserva a ordem aleatória e
seus cenários de Performance, PostgreSQL, Redis e isolamento de rede.
A validação usa o ferramental existente. Smoke exige autorização específica
por consultar serviços reais.
