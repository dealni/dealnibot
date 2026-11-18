# Convenções — DealniBot

Regras para alterar código e documentação em uma instalação própria. Organize
informações descritivas por assunto conforme o [índice](../README.md#documentação).

## Nomenclatura e idioma

- **pt-BR** em comentários, testes, commits, documentação e mensagens ao
  usuário; código, comandos e termos técnicos ficam como estão.
- **Identificadores**: português no domínio (`saldo`, `cota`, `contato`),
  inglês na infraestrutura (`client`, `handler`, `logger`). Vale para código
  novo e trecho tocado, sem renomear em massa.
- Termos do [glossário](VISAO_GERAL.md#domínio-e-glossário) como estão, sem
  sinônimo novo; nomes completos, guard clauses e comentário que diz o porquê.
- Data em texto `DD/MM/AAAA`; data que máquina lê `AAAA-MM-DD`.
- Variável de ambiente: `DEALNI_` deste bot, `CHATBOTS_` da suíte e das
  ferramentas locais; sem prefixo só `PHP85_BIN`, `REDIS_HOST`, `REDIS_PORT`,
  `REDIS_PASSWORD`. A constante PHP mantém o nome canônico.

## Antes de dar por pronto

```bash
composer check:runtime                                        # pins e extensões; preparo local rápido
composer check                                                 # durante o trabalho
bash scripts/quality/with_local_config.sh composer check:all   # antes do push e do PR; é o pre-push
```

`composer check:runtime` valida PHP, Composer e requisitos de plataforma sem
rodar o agregado. `composer check:toolchain` conserva a validação completa
local, incluindo `check:all`; use-o quando quiser executar essa sequência.
O pre-push continua exigindo o agregado completo.

- Antes de publicar e ao mexer em dependência, escolha
  `bash scripts/quality/with_local_config.sh composer check:ci-local` em lugar de
  `check:all`; o agregado já inclui a mesma suíte e acrescenta auditorias online.
- O teste que prova a mudança **falha sem ela** e entra numa categoria da
  [taxonomia](TESTES.md#taxonomia-de-testes).
- Fato novo vai para o documento dono ([Documentação](#documentação)).
- Smoke (`composer test:smoke -- --confirm-production`) fala com serviços
  reais: é passo depois de publicar.

## Onde as coisas moram

| Se você vai escrever | Vai em | Restrição |
|---|---|---|
| fluxo de comando roteado | `comandos/funcoes_<comando>.php` | o `comandos/<comando>.php` fica só com guarda de contexto e a chamada |
| helper de mais de um comando de IA | `comandos/funcoes_ia_comuns.php` ou `funcoes_http_openai.php` | nada executável fora de funções |
| decisão de roteamento | `inclusos/roteamento.php` | função pura; o efeito fica no `roteador.php` |
| infraestrutura (banco, cota, cache, sondas) | `inclusos/` | sem namespace |
| código com namespace | `src/Support/` (`App\`) | único lugar com PSR-4 |
| portão de qualidade | `scripts/quality/` | bloco de dados se tiver valor deste bot |
| dado lido por portão | `tools/quality/` | escrito pelo próprio script, não configuração |
| runner de produção | `scripts/deploy/` | confirmação nominal e bloco de dados |
| mudança de schema | nova `database/postgresql/NNN_<assunto>.sql` e `database/schema.sql` | SQL PostgreSQL à mão; migration publicada é imutável |
| teste | `tests/<Categoria>/` | o diretório decide a categoria |

## Convenções de código

### Estilo e lint

- PSR-12 pelo php-cs-fixer (`composer format:check`; `composer format`
  corrige).
- PHPStan `max` sem baseline: corrija no código; exceção estrutural vai no
  `phpstan.neon` com identificador e comentário, nunca inline.
- Arquivo até **500 linhas físicas** e função até complexidade **15**
  (`composer check:complexity`); a dívida antiga está gravada e se paga
  dividindo a unidade, não regravando a baseline. Cada função, closure e arrow
  começa em 1; contam os ramos, operadores booleanos, `??` e ternários.
  Cada condição de `match` conta como um `case` de `switch`; `default` não
  acrescenta decisão. Closures e arrows também são medidas separadamente;
  suas decisões permanecem na função continente, contadas uma vez.
- `declare(strict_types=1)` em todo arquivo novo e em `tests/`. Em um arquivo relacionado
  `funcoes_*.php` ele acompanha as funções (a coerção do retorno segue o
  arquivo que declara a função). `composer check:strict-types` só aceita
  ausência listada na baseline, que só encolhe.

### PHP

- **PHP 8.5 apenas**, com PHPUnit 13. Nenhuma menção a runtime mais antigo, nem
  em comentário: `LegadoDeRuntimeAntigoTest` reprova sem allowlist.
- Valor do banco ou de JSON é `mixed` na borda; a guarda é `is_numeric()` ou
  cast explícito.
- Ao mover código global para um namespace, qualifique chamadas originalmente
  globais como `\nativa()` ou `\helperGlobal()` para preservar sua resolução.
- Telegram, banco e Redis chegam às funções por **parâmetro** tipado; nada de
  `global` nem `$GLOBALS` (`SemEstadoGlobalTest`).
- Todo `new bdTelegram()` fica em try/catch com `ecoarEEncerrar()`, menos no
  callback do polling, onde a exceção atravessa o laço.
- SQL novo tem texto fixo e bind. Construção que o `SqlPostgres.php` não conhece
  é escrita já em PostgreSQL; o adaptador não cresce.

### `exit` e funções em script roteado

O porquê está em [ARQUITETURA.md](ARQUITETURA.md#o-laço-de-polling-e-o-exit).

- **`roteador.php` nunca chama `exit`/`die`**; todo fim de fluxo é `return`
  (`PollingLoopTest`), mantendo o laço vivo. O helper confirma o update no
  shutdown de um `exit` normal; exceção ou fatal não confirma o lote e pode
  repetir efeitos no restart.
- **Script roteado não declara função nomeada**: a segunda inclusão no mesmo
  processo aborta com `Cannot redeclare function` (`InfraRoteadorPollingTest`).
- **A lógica mora no irmão `funcoes_<nome>.php`**, carregado com `require_once`;
  script novo já nasce com ele. `FuncoesDeComandoSaoInertesTest` exige, nos
  diretórios da constante `DIRETORIOS`, que fora das funções só haja
  declarações, `declare(strict_types=1);`, `const` literal e `require_once` de
  outro `funcoes_*.php` do mesmo diretório, e que todo irmão seja carregado por
  algum script (os compartilhados `funcoes_ia_comuns.php` e
  `funcoes_http_openai.php` não têm script homônimo).

### Testes

- O diretório decide a categoria ([taxonomia](TESTES.md#taxonomia-de-testes)).
- Nomes em português; todo teste tem asserção e não escreve na saída (use
  `ob_start()`); nada de `sleep` nem hora real; temporário em
  `sys_get_temp_dir()` com limpeza.
- Metadado é **atributo** (`#[DataProvider]`, `#[RunInSeparateProcess]`,
  `#[PreserveGlobalState(false)]`), porque docblock é ignorado em silêncio;
  provider é `public static`. `createStub()` quando nada é verificado;
  `createMock()` só com `expects()`.
- A ordem é sorteada: teste que depende de outro falha, e a suíte também passa
  com `--order-by=reverse`. `define()` não se desfaz: quem precisa de constante
  diferente roda em processo separado.
- **Constante de regra tem o valor de produção**; `tests/bootstrap.php` não
  define constante. O bloco de cota dos três comandos de IA é travado **na
  fonte** por `ProvedorOpenAiContratoTest`: mudou o bloco, mude o espelho.
- Script de topo se testa pelo irmão. `tests/Support/ExtratorDeFuncoesDeScript.php`
  é o último recurso; prefira executar as funções reais do produto.
  Contrato que varre fonte de comando usa `tests/Support/FonteDoComando::ler()`,
  que inclui os irmãos carregados.
- Arquivo real grande (logs, dumps) nunca vai para a memória: use
  `tests/Support/AppLogReader.php` ou fixture. `phpunit.xml` usa
  `includeUncoveredFiles="true"`.

### Observabilidade

Log só pela `App\Support\LoggerFactory`: `escreverLog($mensagem, $canal,
$forcar)` no código procedural, `LoggerFactory::channel()` nas classes. O canal
é o assunto. **Dado variável vai em campo de contexto**, nunca interpolado na
mensagem; a redação não esconde valor sensível por você. Infraestrutura fora do
ar usa `$forcar = true`.

### Scripts e portões

- Shell começa com `#!/usr/bin/env bash` e `set -euo pipefail` e passa no
  `composer check:shell`.
- **Script com valor deste repositório começa com o bloco de dados**; abaixo
  dele o código é canônico. Marcadores literais até o fecho de hífens (`//` no
  PHP): `# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) ---`
  e `# --- FIM DOS DADOS; daqui para baixo o código é canônico ---`. O
  `ContratoDeReleaseTest` os cobra nos runners de `scripts/deploy/` e nos
  portões com lista própria.
- Portão novo nasce em `scripts/quality/` com teste e entra no `check:all`; lê
  de `tools/quality/` e escreve em `.artifacts/`. Portão lento começa por
  `Composer\Config::disableProcessTimeout`; portão com baseline reprova com
  `CI=1` em vez de regravar.
- Comando destrutivo exige confirmação nominal do alvo (`DEPLOY_CONFIRM`,
  `ROLLBACK_CONFIRM`, `DB_RESTORE_CONFIRM`, `--confirm-production`);
  `db_restore_test.sh` só toca cópia descartável.

## Nunca faça

- **Afrouxar portão para ficar verde**: baixar piso ou teto, encolher escopo
  medido ou regravar baseline para destravar rodada.
- **Pular teste sem registro na quarentena** (`testes-em-quarentena.json`) ou
  criar comando de categoria vazio: categoria que não se aplica ganha linha em
  [TESTES.md](TESTES.md#taxonomia-de-testes).
- **Calar achado** com `nosemgrep`, `@phpstan-ignore`, `phpstan-baseline.neon`
  ou exceção para uma credencial real: proibido. O `run_semgrep.php`
  desliga o `nosemgrep`, e achado do gitleaks se resolve tirando a credencial
  do código.
- **Commitar segredo** (`inclusos/config.php`; o modelo versionado é
  `inclusos/config.example.php`) ou editar lockfile à mão.
- **Editar migration aplicada**: correção vai em migration nova.
- **Executar ou incluir script de topo**
  ([Nunca execute os scripts de topo](#nunca-execute-os-scripts-de-topo)).
- **Apontar a suíte ou um script de desenvolvimento para o banco de produção**,
  ou tocar à mão no banco de produção ou em `logs/`.
- **Juntar rollback de código e de dados**, ou unificar os tokens
  `DEPLOY_CONFIRM`, `ROLLBACK_CONFIRM` e `DB_RESTORE_CONFIRM`.
- **Renomear variável `DEALNI_*`** ou comando do
  [contrato](#contrato-dos-comandos).
- **Liberar chamada paga sem o portão de cota.**
- **Mandar a URL de download do Telegram a terceiro**: ela carrega o `TOKEN_BOT`.
- **Criar worker residente ou unidade systemd da aplicação**
  ([limites do contrato](#não-existem-de-propósito)).
- **Apontar o ensaio de restauração para banco existente**, gravar backup dentro
  do repositório ou prometer cópia externa do backup que os scripts deste
  repositório não fazem.

### Nunca execute os scripts de topo

Os arquivos de `comandos/`, `config/` e `processos/` **executam no
`include`**: instanciam o Telegram com o token real, enviam mensagem, registram
webhook e gravam arquivo. Nunca rode `php caminho/do/script.php` por engano nem
os inclua em teste com contexto operacional; a exceção são os irmãos
`funcoes_*.php`, que só declaram funções. `ComandosContextoInvalidoTest` inclui
os três comandos em processo separado, sem clientes e com a rede bloqueada,
só para provar a recusa.

Os `.php` de `scripts/operacao/` são ferramentas de CLI com guarda:
`avisar_falha.php` só avisa o Telegram com `--job`, `--codigo` e
`DEALNI_ALERTA_CHAT_ID` configurada; `aplicar_retencao.php` não apaga nada e
existe para crons antigos. Nenhum dos dois se inclui em teste.

## Ao encontrar um bug

1. **Reproduza com um teste que falha**, na categoria certa
   ([TESTES.md](TESTES.md#taxonomia-de-testes)).
2. **Corrija na camada dona**: o irmão `funcoes_*.php`, `inclusos/` ou
   `src/Support/`, nunca o corpo do script de topo.
3. **Não afrouxe portão**: limite menor, baseline regravada ou teste pulado não
   é correção.
4. **Em produção, siga o [runbook](OPERACAO.md#runbooks-de-incidente)** e ache
   o `request_id` antes de mexer.
5. **Deixe o porquê** em comentário e o fato novo no documento dono.

Mudanças em dados ou schema exigem alvo, impacto e recuperação
explícitos. Aplique somente as operações autorizadas; a revisão documental
não autoriza executá-las.

## Commits e hooks

Commit, push e deploy são autorizações separadas do proprietário.

Conventional Commits em pt-BR, conferidos pelo commitlint
(`commitlint.config.mjs`) no hook `commit-msg`: tipos do
`@commitlint/config-conventional`; tipo e escopo em minúsculas; cabeçalho até
100 caracteres, sem ponto final; corpo e rodapé até 100 por linha; assunto no
imperativo (`corrige`, não `corrigindo` nem `adicionei`). Escopo é opcional e
de lista fechada:

| Grupo | Escopos |
| --- | --- |
| comuns (`ESCOPOS_COMUNS`) | `qualidade`, `docs`, `deploy`, `seguranca`, `testes`, `deps`, `operacao`, `ci`, `release`, `api`, `painel`, `app`, `backend`, `bot`, `ia`, `banco`, `infra`, `hooks` |
| deste repositório (`ESCOPOS_DO_REPOSITORIO`) | `redis`, `webhook` |

Escopo novo entra no bloco de dados do `commitlint.config.mjs` e nesta tabela,
no mesmo commit; ferramenta (`jest`, `phpstan`…) não é escopo. Exemplo:
`fix(ia): corrige cota do comando de imagem`.

Mantenha os hooks ativos. Corrija verificações defeituosas no mesmo commit.
Consulte [o que cada hook executa](TESTES.md#hooks).

## Pull request

- Um assunto por PR: código, teste e documento dono juntos.
- Antes de abrir, `bash scripts/quality/with_local_config.sh composer check:all`;
  antes de publicar, `composer check:ci-local`.
- A descrição informa motivo, efeito para quem usa ou opera, risco e o resultado
  observado das verificações locais e remotas.
- Baseline, piso ou teto regravado vai em **commit próprio**, com o motivo.
- Atualização de dependência não vem com mudança de comportamento; major do
  Dependabot se valida à mão.
- Mudança em `scripts/deploy/`, `deploy/`, `config/crontab.txt` ou
  `.env.example` descreve o passo manual no host.

## Documentação

- `README.md` e os guias em `docs/` cobrem os assuntos listados no
  [índice](../README.md#documentação). Os modelos podem ter notas específicas
  junto aos arquivos, como a [configuração do Nginx](../deploy/nginx/LEIA-ME.md).
- **Formato**: H1 `# <Nome> — DealniBot`, parágrafo de escopo, H2 fixos na ordem
  (portões leem por título), H3 livres; H2 vazio diz por que não se aplica.
  Tabela para inventário, cerca de 80 colunas, sem emoji, rodapé nem histórico;
  data absoluta; versão vem do manifesto; comportamento vem do código; estado de host exige evidência datada.
- Código cita âncoras e frases destes docs: `rg` antes de renomear seção ou
  reescrever regra.

### Portões que leem os docs

| Portão | O que exige |
| --- | --- |
| `composer check:docs` | links relativos e âncoras de todo `.md` (também no pre-commit) |
| `ContratoDeReleaseTest` | todo `composer X` citado existe; `OPERACAO.md` cita cada comando de release e banco |
| `InventarioDeSegredosTest` | todo segredo do código no inventário de `SEGURANCA.md` |
| `ContratoDePhpFpmTest`, `ContratoDeCronTest` | frases dos runbooks em `OPERACAO.md` e os links oficiais em `REFERENCIAS.md` |
| `LegadoDeRuntimeAntigoTest` | nenhum arquivo de texto, docs inclusive, cita runtime antigo |
| `DeployAssetsTest` | o comando `configurar_credenciais.sh` do `README.md` funciona |
| `composer check:tests` | a linha de `test:e2e` em `TESTES.md` |

### Depois de mexer em…

| Mexeu em | Rode | Atualize |
| --- | --- | --- |
| scripts do `composer.json` / `package.json` | `composer check:config` | [OPERACAO.md](OPERACAO.md#comandos-do-dia-a-dia) e [TESTES.md](TESTES.md#portões-de-qualidade) |
| dependências | `composer check:online` | [REFERENCIAS.md](REFERENCIAS.md#dependências) |
| `.env.example` / `config/env.schema.php` | `composer check:env` | [OPERACAO.md](OPERACAO.md#variáveis-de-ambiente) e, se for segredo, [SEGURANCA.md](SEGURANCA.md#inventário-de-segredos) |
| `database/` | `composer check:postgres` | [DADOS.md](DADOS.md#modelo-de-dados) |
| `deploy/`, `config/crontab.txt` | `composer check:deploy-guards` | [OPERACAO.md](OPERACAO.md#procedimentos-específicos) |
| `.github/` | `composer check:workflows` | [TESTES.md](TESTES.md#ci) e [REFERENCIAS.md](REFERENCIAS.md#ci) |
| `tests/` | `composer check:tests` | [TESTES.md](TESTES.md#invariantes-críticas) |
| `comandos/`, roteamento | `composer check` | [FUNCIONALIDADES.md](FUNCIONALIDADES.md#panorama) |
| `src/Support/`, sondas, log | `composer check` | [ARQUITETURA.md](ARQUITETURA.md#observabilidade) e [OPERACAO.md](OPERACAO.md#logs) |

## Contrato dos comandos

Nome de comando é interface: significa sempre a mesma coisa, e renomear é
mudança deliberada. `ContratoDeReleaseTest` reprova nome fixo ausente e apelido
de volta. O que cada um faz: [TESTES.md](TESTES.md#portões-de-qualidade)
e [OPERACAO.md](OPERACAO.md#comandos-do-dia-a-dia).

### Nomes fixos

A lista executável é `composer.json#scripts` e `package.json#scripts`, travada
por `ContratoDeReleaseTest`. Finalidades e execução estão em
[TESTES.md](TESTES.md#portões-de-qualidade) e
[OPERACAO.md](OPERACAO.md#comandos-do-dia-a-dia). Ao mudar a interface, atualize
o manifesto, os testes de contrato e o documento dono no mesmo trabalho.

### Não existem de propósito

| Nome | Por quê |
|---|---|
| reversão automática de migration | o runner não desfaz |
| `db:migrate:dev`, ORM, `db:seed` | migration é SQL à mão e não há semente |
| `release:tag`, SemVer obrigatório | o SHA e o `.release.json` identificam a publicação ([OPERACAO.md](OPERACAO.md#como-uma-publicação-é-identificada)) |
| `test:e2e` | não se aplica ([TESTES.md](TESTES.md#taxonomia-de-testes)) |
| worker residente | o trabalho fora do webhook é cron |
| cópia externa e criptografia do backup | responsabilidade de cada instalação |
| SLO numérico | não há telemetria |

### Nomes aposentados

`test-unit`, `test-integration`, `smoke`, `mutation`, `deps-nao-usadas`,
`analyse`, `cs`, `coverage` e os demais apelidos antigos; a lista executável
está em `ContratoDeReleaseTest`.

## Regras que não são óbvias no código

- **Nome novo com cara de credencial entra no inventário**: chave de
  `inclusos/config.example.php` ou `config/env.schema.php` que case
  `TOKEN|SECRET|KEY|PASSWORD|SENHA|CERT` vai para o
  [inventário](SEGURANCA.md#inventário-de-segredos) no mesmo commit.
- **Cada operação de produção tem token próprio com o nome do bot**
  (`DEPLOY_CONFIRM=dealnibot`), e o deploy faz backup verificado antes de migrar
  ([OPERACAO.md](OPERACAO.md#deploy)).

## Tamanho das funções

Funções de lógica têm limite de **150 linhas efetivas**. Acima desse limite,
`composer check:complexity` bloqueia
novas violações e pioras; o legado tem exceções individuais na seção
`linhas_de_funcao` de `tools/quality/tamanho-baseline.json`.
São contadas assinatura, chaves e linhas de código/literais; linhas vazias e
comentários ficam fora. Closures e arrows são medidas separadamente e o corpo
continente inclui as filhas. A identidade lexical não depende de linhas nem
do corpo; callbacks indistinguíveis acima do limite reprovam.

O recorte é o mesmo produto PHP declarado em `ALVOS_DE_TAMANHO` e
`ARQUIVOS_DE_TAMANHO` de `scripts/quality/check_tamanho.php`. Testes,
ferramentas, migrations e vendor estão fora desses alvos; código gerado não
deve ser adicionado ao recorte. O arquivo usa linhas físicas, incluindo
comentários/vazios, com limite 500; complexidade ciclomática tem limite 15.
`CI=1` nunca regrava baseline. `composer complexity:baseline` é a operação
explícita de migração/revisão de exceções; no uso normal a dívida só diminui.
