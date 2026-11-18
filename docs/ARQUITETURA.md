# Arquitetura — DealniBot

Como o sistema é feito, e por quê. O que o bot faz está em
[FUNCIONALIDADES.md](FUNCIONALIDADES.md); o modelo de dados, em
[DADOS.md](DADOS.md); operar e publicar, em [OPERACAO.md](OPERACAO.md).

## Stack e versões

PHP 8.5 procedural, sem framework, com um único namespace autoloaded
(`App\` → `src/`, PSR-4). Não há compatibilidade com linha anterior do PHP. As
versões exatas estão em [REFERENCIAS.md](REFERENCIAS.md#runtime-e-plataforma).

| Camada | O que é |
|---|---|
| Runtime | PHP-FPM 8.5 sob Nginx, pool próprio |
| Log | Monolog, por trás da PSR-3 |
| Banco | PostgreSQL via PDO |
| Estado efêmero | Redis via phpredis |
| Testes e análise | PHPUnit, PHPStan nível `max` |
| Ferramental de commit | Node para Lefthook e Commitlint; fora da produção |

## Componentes

| Componente | Papel | Onde |
|---|---|---|
| Webhook | autentica a origem, grava o update, devolve 200 e só então roteia | `bot_web.php`, `inclusos/funcoes_webhook.php` |
| Polling | o mesmo roteamento em desenvolvimento | `bot.php`, `inclusos/funcoes_polling.php`, `bot.sh` |
| Roteador | decide qual comando atende o update | `roteador.php` (efeitos) + `inclusos/roteamento.php` (decisão pura) |
| Comandos de IA | texto, imagem e áudio contra o OpenRouter | `comandos/` |
| Cota e admissão | o único limite de gasto com a IA | `inclusos/limites_requisicoes.php` |
| Inbox durável | `update_id` como chave primária, lease, backoff, replay e retenção | `inclusos/funcoes_webhook_inbox.php`, `inclusos/funcoes_retencao_inbox.php`, `processos/` |
| Banco | contatos, inbox e checkpoints em PostgreSQL | `inclusos/bdTelegram.php`, `banco.php`, `BancoPostgres.php`, `ConsultaBanco.php` |
| Adaptador de SQL | traduz o SQL herdado para PostgreSQL | `inclusos/SqlPostgres.php` |
| Cliente da Bot API | envio, download e `getUpdates` | `inclusos/Telegram.php`, `funcoes_curl_telegram.php` |
| Redis | conexão com timeout e autenticação | `inclusos/redis_conexao.php`, `inclusos/funcoes.php` |
| Observabilidade | log JSON, `request_id`, sondas | `src/Support/`, `inclusos/funcoes_sondas.php`, `health.php`, `ready.php` |
| Snapshot | cópia diária do banco | `processos/snapshot_banco.php`, `inclusos/funcoes_snapshot_*.php` |
| Operação | webhook, scripts de instalação e verificações | `config/`, `scripts/` |

O PostgreSQL guarda o estado inteiro e não é reconstruível; tudo no Redis nasce
com TTL e pode ser perdido sem apagar updates já aceitos.

## Mapa de pastas

| Pasta | O que tem |
|---|---|
| `comandos/` | `chatgpt`, `ver_imagem` e `ver_audio`; a lógica de cada um vive no `funcoes_*.php` irmão, e as três modalidades de IA compartilham `funcoes_ia_comuns.php`, `funcoes_memoria_conversa.php` e `funcoes_http_openai.php`; a validação de respostas usa `funcoes_respostas_ia.php`, a admissão de mídia usa `funcoes_admissao_midia.php`, e a preparação binária mora em `funcoes_arquivo_audio.php` e `funcoes_arquivo_imagem.php` |
| `inclusos/` | Bot API, banco, cota, inbox, roteamento, configuração, sondas, polling e snapshot, sem namespace |
| `src/Support/` | `LoggerFactory`, `FormatadorDeContrato`, `ProcessadorDeRedacao`, `RedatorJson`, `ContextoDeExecucao`, `VersaoDoGit`, `RuntimeEnvironment`, `Sonda`, `FormatoDeImagem` |
| `processos/` | jobs de cron: replay da inbox e snapshot |
| `config/` | registro e remoção do webhook, modelo do cron (`crontab.txt`) e schema do ambiente (`env.schema.php`) |
| `database/` | `schema.sql`, migrations em `postgresql/` e histórico em `migrations/` |
| `deploy/` | modelos de Nginx, PHP-FPM, Redis e systemd; nada é aplicado sozinho |
| `scripts/` | `install/`, `deploy/`, `operacao/` e `quality/`; nenhum script solto na raiz |
| `tools/` | Semgrep, PHPCPD isolado, bootstrap do PHPStan e os dados dos portões em `quality/` |
| `tests/` | `Unit/`, `Integration/`, `Contract/`, `Security/`, `Performance/` e `Support/` |

Os arquivos de `comandos/`, `config/` e `processos/` **executam no
`include`** ([regra](CONVENCOES.md#nunca-execute-os-scripts-de-topo)).

A classe global `Telegram` mantém a API e o include em `inclusos/Telegram.php`.
Os traits em `src/Support/Telegram/` separam transporte, updates, teclados e
famílias de endpoints. Eles seguem o PSR-4 `App\` existente e o include
legado os carrega por caminhos locais explícitos, mesmo sem autoload Composer.

## Fluxo de uma requisição

```
Telegram → bot_web.php (webhook, produção) ─┐
           bot.php     (polling, dev)       ─┴→ roteador.php → comandos/…
```

### Webhook

1. `inclusos/ciclo_web.php` fixa o `request_id` e ecoa `X-Request-Id` antes de
   qualquer recusa.
2. `webhookTelegramAutorizado()` compara `X-Telegram-Bot-Api-Secret-Token` com
   `TELEGRAM_WEBHOOK_SECRET`; falha devolve 403 antes de abrir Telegram, banco
   ou Redis.
3. Abre PostgreSQL (falha de conexão devolve 500) e Redis (falha devolve
   503), depois decodifica o JSON e exige `update_id` inteiro (senão 400).
4. `registrarUpdateWebhook()` grava o payload em `webhook_inbox`; falha
   devolve 503, para o Telegram reentregar.
5. Devolve 200 e chama `fastcgi_finish_request()`.
6. `processarProximoUpdateWebhook()` toma uma lease e inclui o roteador;
   renova a posse por CAS antes das fases externas e recusa a próxima chamada
   quando a tentativa expira ou é substituída.
   Duplicata já processada ou reservada não encontra linha elegível; uma linha
   pendente ou em erro pode ser reservada quando disponível. Exceção ou erro
   fatal depois do ACK marca a linha como `erro` para o cron retomar.

### Polling

`bot.php` cria `Telegram` e Redis uma vez; `executarLongPollingTelegram()`
chama, por update, `ContextoDeExecucao::reset()`, `new bdTelegram()` e o
`require` do roteador. O offset só sobe **depois do lote inteiro**. Falha de
banco atravessa o laço como exceção, sem `ecoarEEncerrar()`, para não
confirmar o offset do update que falhou. `bot.sh` reexecuta `php bot.php` com
pausa de 1 s.

### O roteador

Recebe `$telegram`, `$bd` e `$redis` do escopo de quem o inclui. A decisão de
rota é pura (`inclusos/roteamento.php`); o roteador fica com o `require`, os
`return` e o sorteio `rand()`. Cada comando de IA segue: guarda de contexto →
cota (`admitir_midia()` antes do download de mídia, `consumir_limite_diario()`
antes da chamada paga) → `sendChatAction` → download → OpenRouter → resposta →
turno na memória. As regras estão em
[FUNCIONALIDADES.md](FUNCIONALIDADES.md#quando-o-bot-responde).

## Configuração

| Camada | Arquivo | O que traz |
|---|---|---|
| Segredos | `inclusos/config.php` (fora do git; modelo em `inclusos/config.example.php`) | `TOKEN_BOT`, `OPENROUTER_API_KEY`, `TELEGRAM_WEBHOOK_SECRET` |
| Constantes | `inclusos/dados_bot.php` | `PUBLIC_BASE_URL` (HTTPS validado quando informado) e `LOGS_ATIVO` |
| Ambiente do host | `/etc/dealnibot/environment` (modelo `.env.example`, regras `config/env.schema.php`) | banco, URL pública, username Telegram, snapshots, Redis, `PHP85_BIN` |

`inclusos/config_loader.php` exige as três constantes sensíveis em produção e
valida o formato do segredo do webhook: bot mal configurado não sobe.

As variáveis do host levam o prefixo `DEALNI_` para que um valor genérico
exportado num FPM compartilhado não faça este bot abrir o banco ou registrar o
webhook de outro. Só `REDIS_HOST`, `REDIS_PORT` e `REDIS_PASSWORD` não têm
prefixo. A lista é de [OPERACAO.md](OPERACAO.md#variáveis-de-ambiente).

## Processos de fundo

Não há worker residente, fila externa nem unidade systemd residente da aplicação.
O modelo `config/crontab.txt` define três jobs pelo wrapper `scripts/operacao/executar_com_ambiente.sh`, que
carrega `/etc/dealnibot/environment`:

- `processos/processar_webhook_inbox.php`, a cada minuto: retenta updates que
  falharam depois do ACK e limpa a inbox retida;
- `processos/snapshot_banco.php`, às 5h10 em `America/Sao_Paulo`: `pg_dump` e
  rotação;
- `scripts/operacao/monitorar_saude.sh`, a cada 5 minutos: consulta as sondas.

É possível agendar o snapshot por timer systemd. Configure uma única
execução periódica, como descrito nas [rotinas](OPERACAO.md#rotinas-automáticas).

No modelo, os três passam por `scripts/operacao/avisar_se_falhar.sh`, que leva a falha
ao Telegram do dono. Os dois jobs PHP usam `flock` não bloqueante. Encerramento
abrupto que não executa o shutdown handler não é capturado como
`Throwable`; o replay recupera a linha pela lease de 360 s. O pool fixa
`request_terminate_timeout_track_finished = no`, portanto o timeout de 330 s
não vale depois de `fastcgi_finish_request()`. Horários e locks em
[OPERACAO.md](OPERACAO.md#rotinas-automáticas).

## Observabilidade

O contrato de observabilidade, versão 1, registra uma linha JSON por evento
com dados sensíveis ocultados, `request_id` e versão publicada. Saúde e
prontidão têm sondas próprias. Formato, canais e filtros estão em
[OPERACAO.md](OPERACAO.md#logs).

| Peça | Onde |
|---|---|
| Campos do contrato (`ts`, `level`, `msg`, `service`, `env`, `version`, `request_id`) | `src/Support/FormatadorDeContrato.php` |
| Redação de chave sensível no contexto e no texto, e de `/bot<token>` em URL | `src/Support/ProcessadorDeRedacao.php`, empurrado em todo canal pelo `LoggerFactory` |
| `request_id`, `service`, `env`, `version` | `src/Support/ContextoDeExecucao.php` e `VersaoDoGit.php` |
| `/health` e `/ready` | `src/Support/Sonda.php` + `inclusos/funcoes_sondas.php` |

Decisões de forma:

- **Não existe logger fora do contrato**: o `LoggerFactory` é o único caminho.
- **`channel` continua no evento**; é por ele que os runbooks filtram.
- **O `request_id` é preguiçoso**: resolvido na primeira leitura; o polling
  chama `reset()` por update.
- **Stack trace nunca é gravado.** `err` leva `type` e `message`; `file`,
  `line` e `trace` entregariam o caminho do servidor.
- **O valor por bot vem de fora do arquivo** (nome do diretório raiz ou variável
  `CHATBOTS_*`/`DEALNI_*`). Exceções: `inclusos/funcoes_sondas.php` (lista as
  dependências deste bot e lê `REDIS_*` direto, sem carregar o token numa rota
  sem autenticação) e `LoggerFactory` (lê `DEALNI_LOGS_DIR`).

| Ponto do contrato | Situação aqui |
|---|---|
| `user_id` | ausente; o canal `telegram` registra `chat_id` |
| `duration_ms` | presente no fim do atendimento de cada update do webhook (canal `webhook`) e no desfecho do snapshot (`snapshot_banco`), medido com `hrtime`; o replay da inbox e o monitor de saúde não têm evento de fim |
| `http` | ausente: o webhook é rota única |
| `err` | presente: `{type, message}` |
| `msg` sem dado interpolado | não cumprido nos call sites antigos; código novo põe dado variável em campo |
| `X-Request-Id` em chamada de saída | não se aplica: só há chamadas a terceiros |
| Sonda fora do log de acesso | cumprido por `access_log off` no Nginx |
| OpenTelemetry e coletor | não adotados; o log é lido com `jq` no host |

## Decisões estruturais

Decisão nova entra aqui como subseção; não há `docs/adr/`.

### Só grupos e supergrupos

A IA responde só em grupo e supergrupo, onde a cota é do grupo. No privado o
roteador chama `registrarContato()` e orienta o uso em grupos; não há comando
de boas-vindas. Vídeo, documento e sticker ficam de fora para não virar chamada
paga com texto vazio.

### A cota é do grupo

Nenhum gatilho de grupo é escolha de quem mandou a mensagem, então
`limite_diario_sujeito()` devolve o **grupo** como sujeito. Fora de grupo o
sujeito é o usuário, nunca "sem cota". O dia é calculado em
`America/Sao_Paulo` por `limite_diario_dia_atual()`, independente do fuso do
processo.

### Memória por grupo, tópico e participante

Em grupo a chave é `memoria:v3:grupo:<id>:topico:<n>:usuario:<id>`; no privado,
`memoria:v2:privado:<id>:topico:0`. Tópicos e participantes não misturam
contexto. Da imagem persiste só a legenda, porque a URL do Telegram expira.
Resposta com falha ou entrega não confirmada não vira turno. A gravação usa
compare-and-set em Lua com até cinco tentativas. As chaves legadas `contexto*` e
`assistente*` seguem como fallback de migração ([DADOS.md](DADOS.md#chaves-e-ttls)).

### O laço de polling e o `exit`

O polling reaproveita o processo e confirma o offset na chamada seguinte,
depois do lote. Exceção ou erro fatal deixa o lote sem confirmação, permitindo
reentrega e repetição de efeitos que já aconteceram. Em `exit` normal de script
roteado, o shutdown handler confirma até o update em andamento; isso encerra
o processo e depende do restart para continuar. O roteador usa `return`, nunca
`exit`, para manter o laço vivo. Nenhum script roteado declara função nomeada:
as funções vivem no `funcoes_*.php` irmão, com `require_once`, pois redeclará-las
abortaria o lote. Regra prática em
[CONVENCOES.md](CONVENCOES.md#exit-e-funções-em-script-roteado).

### ACK antes do trabalho

Sob PHP-FPM, `flush()` não encerra a requisição. `bot_web.php` grava o update
com chave primária por `update_id`, devolve 200 e chama
`fastcgi_finish_request()` antes de processar. Reentregas não criam outra linha.
O pool e o Nginx configuram 330 s para a fase até o ACK. Os 365 s que o
comentário do pool registra como orçamento de rede do áudio são trabalho posterior
ao ACK, ao qual o timeout do FPM não se aplica com
`request_terminate_timeout_track_finished = no`
([pool](OPERACAO.md#isolamento-do-pool-e-do-usuário)).

### Uma porta de saída para log

`App\Support\LoggerFactory` grava uma linha JSON por evento em
`logs/app-AAAA-MM-DD.log` (14 arquivos). A assinatura legada continua:

```php
escreverLog(string $mensagem, string $arquivo_log = 'geral', bool $forcar = false): void
```

O arquivo só é escrito com `LOGS_ATIVO = true` (produção) ou `$forcar = true`,
usado para registrar infraestrutura fora do ar. Em CLI há também cópia em
STDERR. `DEALNI_LOGS_DIR` troca o diretório.

### Falha de infraestrutura é exceção

O construtor de `bdTelegram` lança `RuntimeException` depois de registrar no
canal `bd`. `conectarRedis()` ainda encerra com `exit(1)`; por isso
`inclusos/funcoes_sondas.php` abre conexão própria: em `/ready` a falha é a
resposta, não o fim do processo.

### Um adaptador de SQL, não um segundo banco

`inclusos/SqlPostgres.php` traduz só as construções do SQL herdado
(`INSERT OR IGNORE` → `ON CONFLICT DO NOTHING`, `strftime('%H', …)` →
`chatbots_hora()`), protegendo literais e comentários, e lança em formato
desconhecido. Migration nova é escrita em PostgreSQL e não passa pelo
adaptador.

### Duas sondas, dois arquivos

`health.php` não carrega `inclusos/` nem toca banco, Redis ou rede.
`ready.php` delega as dependências a `inclusos/funcoes_sondas.php` e a mecânica
a `Sonda`, com teto de 2 s por dependência. Arquivos separados impedem que uma
consulta vaze para a sonda de vida. Os limites da exposição estão em
[SEGURANCA.md](SEGURANCA.md#superfície-exposta).

### Sem processo residente

O primeiro processamento acontece no FPM; o cron retoma a inbox e gera o
snapshot. Não há o que supervisionar além de FPM, cron e Redis dedicado.
`deploy:verify` prova a publicação pelo `ExecMainStartTimestamp` do serviço FPM.

## Pontos não óbvios

- **`index.php` usa `DEALNI_BOT_USERNAME`** e responde 404 se o nome estiver vazio.
- **`rand(1, 10)` fica no mesmo ponto do roteador.** Movê-lo muda o consumo do
  gerador e o comportamento do sorteio.
- **A menção busca `dealni` ou o username configurado** como substring, sem
  distinção de maiúsculas. Texto com UTF-8 inválido vira `''`.
- **A resposta ao bot compara o ID do autor** ao ID contido no token da
  instância.
- **Resposta a outra pessoa encerra o roteamento**, mesmo com o nome do bot no
  texto.
- **`incrementar_contador_requisicoes()` e `verificar_limite_diario()` são
  legado.** O portão de produção é `consumir_limite_diario()`.
- **Os timeouts do banco são de sessão**, fixados pelo bot na abertura da
  conexão ([valores](DADOS.md#conexão)).
- **A versão do log vem do `.release.json`**, com fallback para o `.git` lido
  como arquivo ([identificação](OPERACAO.md#como-uma-publicação-é-identificada)).
