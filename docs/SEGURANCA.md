# Segurança — DealniBot

Modelo de ameaças, controles, segredos, dados pessoais, varredores e política
de segurança. Os segredos são só deste bot. Proibições para quem escreve código
estão em [CONVENCOES.md](CONVENCOES.md#nunca-faça).

## Modelo de ameaças

O bot não tem administrador nem saldo. O que se protege é o `TOKEN_BOT`, a
chave do OpenRouter (todo update atendido custa dinheiro), o banco e o conteúdo
das conversas.

| Ativo | De quem se protege | O que o protege |
|---|---|---|
| `TOKEN_BOT` | qualquer terceiro: quem o tem **é** o bot | fora do git, `root:dealnibot 0640` no host, nunca em URL enviada a terceiro, mascarado no log |
| `OPENROUTER_API_KEY` | quem forjaria updates para gastar a conta | segredo do webhook, cota diária e admissão de mídia |
| Banco PostgreSQL | perda e corrupção | snapshot, verificação do dump e ensaio sintético; restore real é evidência separada |
| Conversas | retenção longa demais | TTL de 24 h na memória Redis; inbox e checkpoint têm retenção própria; log sem payload |
| Workers do FPM | rajada de mídia e chamadas lentas | admissão de mídia, timeout de 15 s na Bot API, pool com 8 filhos |

O `TOKEN_BOT` não tem escopo reduzido na Bot API. A URL de download de arquivo
do Telegram carrega o token (`https://api.telegram.org/file/bot<TOKEN_BOT>/…`),
por isso a imagem é baixada no servidor e enviada ao provedor como `data:` URI
(`baixarImagemComoDataUri()`), e o áudio como multipart.

## Autenticação e autorização

**Não há autorização de usuário**: todo participante tem as mesmas capacidades.
O que existe é autenticação da **origem do update**. Sem ela, qualquer JSON
forjado na URL pública gastaria a chave do OpenRouter.

`bot_web.php` compara `X-Telegram-Bot-Api-Secret-Token` com
`TELEGRAM_WEBHOOK_SECRET` por `hash_equals()` e responde 403 antes de abrir
Telegram, banco ou Redis. `webhookTelegramAutorizado()`
(`inclusos/funcoes_webhook.php`) trata como não autorizado: constante ausente,
vazia ou fora de `[A-Za-z0-9_-]{32,256}`. O `config_loader.php` também recusa
subir sem ela. Não há modo permissivo; o polling não usa webhook.

Para ligar ou trocar o segredo:

1. gerar um valor de 32 a 256 caracteres `[A-Za-z0-9_-]` e gravá-lo em
   `inclusos/config.php`;
2. rodar `php config/config_webhook.php` no host;
3. só então publicar ou reiniciar.

`getWebhookInfo` não devolve o segredo: a conferência é local. Até o registro,
todo update vira 403.

## Superfície exposta

O modelo HTTP expõe as rotas abaixo.

| Rota | Arquivo | Método | Papel |
|---|---|---|---|
| `/` e `/index.php` | `index.php` | `GET`/`HEAD` | redireciona para o bot quando `DEALNI_BOT_USERNAME` está definido |
| `/bot_web.php` | `bot_web.php` | `POST` | webhook |
| `/health` | `health.php` | `GET`/`HEAD` | sonda de vida |
| `/ready` | `ready.php` | `GET`/`HEAD` | sonda de prontidão |

Todo o resto (inclusive `health.php` e `ready.php` pelo nome físico, `.git`,
logs e includes) recebe 404 do `deploy/nginx/dealnibot.conf`. Não há handler PHP
genérico nem `try_files`: arquivo novo nasce fechado.
`BloqueioHttpDeArquivosTest` trava o contrato.

As sondas são públicas porque um supervisor precisa chamá-las sem credencial.
Compensações: `checks` é só `{nome: "ok"|"erro"}`, sem mensagem nem caminho;
`/health` não carrega `inclusos/dados_bot.php` nem toca dependência; `/ready`
faz `SELECT 1 FROM usuarios LIMIT 1` e `PING`, 2 s cada; `version` é só o
commit.

Use um pool exclusivo para restringir o acesso às variáveis de ambiente do
processo. Firewall, TLS e confiança nos cabeçalhos do proxy são definidos
na instalação. Restrinja PostgreSQL e Redis ao acesso do próprio serviço.

## Segredos

`TOKEN_BOT`, `OPENROUTER_API_KEY` e `TELEGRAM_WEBHOOK_SECRET` vivem em
`inclusos/config.php`, fora do git, criado por
`bash scripts/install/configurar_credenciais.sh` com `0600` (em produção,
`root:dealnibot 0640`: o pool lê e não reescreve). As variáveis do host,
incluindo `DEALNI_DB_PASSWORD` e `REDIS_PASSWORD`, vivem em
`/etc/dealnibot/environment` e no derivado `fpm.environment` (`0600`).

| Camada | O que faz | Quando roda |
|---|---|---|
| `.gitignore` | cobre `inclusos/config.php`, snapshots, logs, chaves e certificados | sempre; `git add -f` passa por cima |
| `scripts/quality/check_staged.sh` | recusa esses caminhos mesmo com `-f` e passa o gitleaks no índice | pre-commit |
| `composer check:gitleaks` | varre o histórico inteiro, com contraprova do detector | pre-push, dentro do `check:all` |
| job `gitleaks` do `security.yml` | o mesmo no CI | conforme gatilhos do workflow; disponibilidade remota em [TESTES.md](TESTES.md#ci) |

### Inventário de segredos

Nenhum valor aparece aqui nem em arquivo versionado. Responsável: o
proprietário do repositório.

| Segredo | Onde vive | Quem usa | Rotação | Revogação |
|---|---|---|---|---|
| `TOKEN_BOT` | `inclusos/config.php` no host | `inclusos/Telegram.php`, `inclusos/funcoes_aviso_de_falha.php` (e o aviso de conta recusada em `comandos/funcoes_http_openai.php`) | `/revoke` no BotFather; gravar o novo, reiniciar o FPM e atualizar os consumidores autorizados ([runbook](OPERACAO.md#vazamento-de-credencial)) | o `/revoke` invalida o anterior na hora, inclusive nas cópias |
| `OPENROUTER_API_KEY` | `inclusos/config.php` no host | `comandos/funcoes_http_openai.php` | criar chave nova em openrouter.ai/settings/keys, publicar e só então apagar a antiga | apagar a chave em openrouter.ai/settings/keys |
| `TELEGRAM_WEBHOOK_SECRET` | `inclusos/config.php` no host | `bot_web.php` | gerar outro valor, gravar e rodar `php config/config_webhook.php` | novo `setWebhook` invalida o anterior |
| `DEALNI_DB_PASSWORD` | `/etc/dealnibot/environment` e `fpm.environment` | `inclusos/banco.php` | trocar a senha do papel `dealnibot`, atualizar o ambiente, `DEPLOY_CONFIRM=dealnibot scripts/deploy/preparar_fpm.sh` como root e reiniciar o FPM | tirar `LOGIN` do papel e encerrar as conexões |
| `REDIS_PASSWORD` | `/etc/dealnibot/environment` (opcional no contrato; o modelo Redis não configura senha e usa isolamento por UID, conforme os [modelos para host](OPERACAO.md#identidade-e-redis-do-bot)) | cliente Redis e sonda | `requirepass` em `/etc/dealnibot/redis.conf` (nunca no `deploy/redis/` versionado), o mesmo valor no ambiente, `DEPLOY_CONFIRM=dealnibot scripts/deploy/preparar_fpm.sh` como root e reinício do FPM | remover do `redis.conf` |

Os três primeiros são fail-closed: rotação pela metade derruba o endpoint em
vez de abri-lo. Nenhum workflow usa `secrets.*`; o CI cria um `config.php`
descartável (`scripts/quality/prepare_ci_config.php`).
`InventarioDeSegredosTest` reprova nome de `inclusos/config.example.php`, ou de
`config/env.schema.php` que case `TOKEN|SECRET|KEY|PASSWORD|SENHA|CERT`, fora
desta tabela. O passo a passo de vazamento está em
[OPERACAO.md](OPERACAO.md#vazamento-de-credencial).

### Verificação de segredos no Git

Os hooks verificam alterações no índice. O comando `composer check:gitleaks`
varre o histórico Git com a regra para tokens do Telegram definida em
`.gitleaks.toml` e as regras padrão do detector. A saída oculta os valores
detectados. Gere valores sintéticos em tempo de teste quando uma fixture
corresponder a um padrão de credencial.

Antes de alterar a visibilidade de um projeto, revise a árvore e as
referências que serão publicadas. Guarde logs, bancos e demais dados
operacionais em destinos privados pelo prazo de retenção aplicável.

### Segredo fora do log e do chat

O transporte do provedor registra modalidade, HTTP e errno, com o erro
sanitizado e truncado em 300 caracteres. O canal `telegram` guarda método,
nomes dos campos e `chat_id` (`Telegram::contextoTecnicoDaFalha()`), travado por
`TelegramRespostaApiTest`. O `error.message` do provedor é classificado em
categoria fixa, sem persistir o texto bruto nem enviá-lo ao grupo. `ProcessadorDeRedacao` redige chaves
sensíveis e `/bot<token>` em todo canal ([lista](OPERACAO.md#o-evento)).

O backup é dado pessoal inteiro: nasce `0600` num diretório `0700` e nunca pode
ficar dentro do repositório.

## Validação de entrada

| Superfície | Tratamento |
|---|---|
| Resposta do modelo | não escapada; reenvio sem `parse_mode` se o Telegram recusar a formatação |
| Texto de roteamento | `mb_check_encoding()` antes de `mb_strtolower()`; UTF-8 inválido vira vazio |
| SQL | texto fixo e bind; nada de ordenação ou `LIMIT` vindo de entrada |
| Imagem | 10 MiB, MIME pelos bytes, GIF animado recusado |
| Áudio | 3 minutos, 20 MiB, temporário removido em `finally` |
| Download do Telegram | cURL com deadline total, teto de bytes e sem redirecionamento |
| Tipos de update | só `message`, `photo`, `voice` e `audio` |
| Variáveis do host | tipadas em `config/env.schema.php`; `composer check:env` valida o modelo |
| TLS de saída | verificação de peer e host em toda chamada |

## Dados pessoais e LGPD

Retenção em [DADOS.md](DADOS.md#retenção).

- **Cadastro persistente.** O privado registra o contato. O responsável pela
  instalação define o prazo de retenção e como atender pedidos de exclusão.
- **Persistência e minimização.** O cadastro guarda ID e idioma; o payload
  original do Telegram fica na inbox e pode conter nome, texto, legenda e
  metadados da mídia. Respostas prontas podem ficar no checkpoint PostgreSQL.
  Os bytes da foto não são armazenados pelo fluxo de IA: viram base64 em
  memória. A retenção desses registros é distinta da memória Redis.
- **Memória temporária.** TTL de 24 h no Redis, separada por participante em
  grupo.
- **Processamento externo.** Texto, áudio e imagem passam pelo OpenRouter
  e provedores elegíveis ao [contrato de dados](INTEGRACOES.md#política-de-dados-da-ia).
  O snapshot local inclui os dados persistidos no PostgreSQL.
- **Log não é dossiê.** `erros_openai`, `telegram` e `bd` não guardam mensagem
  do usuário nem parâmetros de bind.

## Portões de segurança

| Portão | O que trava |
|---|---|
| `composer check:security` (Semgrep) | fluxo de dado; autotesta as regras na fixture antes do repositório |
| `composer check:gitleaks` | segredo no histórico inteiro; ferramenta ausente reprova |
| `composer check:vulnerabilities` | CVE nos dois `composer.lock`, com três tentativas de rede |
| `composer check:licenses` | Composer raiz, PHPCPD e npm offline; árvore ausente/inválida, pacote sem licença, licença fora da política ou falha de ferramenta |
| `composer test:security` | origem do webhook e allowlist HTTP |
| `npm run check:licenses` / `npm run check:vulnerabilities` | licença do lockfile npm e `npm audit --audit-level=high` |

As auditorias online ficam em `composer check:online`, fora do `check:all`. O
`.npmrc` fixa `save-exact`, `engine-strict` e `strict-allow-scripts` e desliga o
`audit` implícito; `package.json#allowScripts` nega o `postinstall` do Lefthook.

Regras de `tools/quality/semgrep/project/php.yaml`: `sql-montado-por-interpolacao`,
`comando-de-shell-com-variavel`, `unserialize-sem-allowed-classes`,
`segredo-comparado-sem-hash-equals`, `curl-sem-verificar-certificado`,
`aleatorio-fraco-para-segredo`, `entrada-da-requisicao-vira-caminho-de-arquivo`
e `entrada-da-requisicao-impressa-sem-escape`.

Licenças aceitas no Composer (`scripts/quality/quality_gates.php`): `0BSD`,
`Apache-2.0`, `BSD-2-Clause`, `BSD-3-Clause`, `ISC`, `MIT`, `MIT-0`, `MPL-2.0` e
`Unlicense`. O npm (`scripts/quality/check_npm_licenses.mjs`) aceita também
`BlueOak-1.0.0`, `CC0-1.0`, `Python-2.0`, `WTFPL` e `Zlib`.

Achado novo se conserta no código: não existe `nosemgrep`, fingerprint novo nem
`@phpstan-ignore`. Exceção estrutural entra na configuração da regra, à vista.

## Controles específicos

- **Tokens de confirmação nominais**: `DEPLOY_CONFIRM`, `ROLLBACK_CONFIRM`,
  `DB_RESTORE_CONFIRM` e `PROVISION_CONFIRM`, sempre com o nome do bot e
  separados, para um token exportado não disparar outra operação. As recusas
  rodam em `composer check:deploy-guards`.
- **Ambiente derivado do FPM**: `fpm.environment` é `0600` do root e só repete
  segredos já inventariados; rotação atualiza a origem, roda
  `DEPLOY_CONFIRM=dealnibot scripts/deploy/preparar_fpm.sh` como root e reinicia o FPM
  ([procedimento](OPERACAO.md#ambiente-isolado-do-php-fpm)).
- **Restauração nunca sobrescreve**: `scripts/deploy/db_restore.sh` exige banco vazio.

## Política de segurança

O projeto está descontinuado. Se encontrar uma vulnerabilidade ou credencial
exposta neste código, envie o relato pelo canal privado de avisos de segurança
do repositório, quando disponível, ou por um canal privado com o proprietário.
Identifique a versão, o impacto e os passos mínimos com dados sintéticos.
Mantenha credenciais, dados pessoais e detalhes sensíveis em canal privado.

## Fora do escopo

| Assunto | Situação |
|---|---|
| Autorização por papel | não existe |
| Antifraude e cobrança | não se aplica |
| Segurança do cliente do Telegram | do Telegram |
| Termos e retenção do OpenRouter e dos modelos | do provedor |
| Endurecimento do SO da VPS | operação do host; o repositório entrega os modelos de `deploy/` |
| Negação de serviço em rede | pool e admissão limitam custo local; proteção de rede exige configuração externa |
| Criptografia do backup | responsabilidade de quem opera cada instalação |
