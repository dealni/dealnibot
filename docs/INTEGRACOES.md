# Integrações — DealniBot

Cada serviço externo com que o bot fala: contrato, autenticação, falhas e
exemplos. Links oficiais em
[REFERENCIAS.md](REFERENCIAS.md#integrações-externas); segredos em
[SEGURANCA.md](SEGURANCA.md#inventário-de-segredos).

## Panorama

| Serviço | Para quê | Autenticação | Onde é chamado |
|---|---|---|---|
| Telegram Bot API | entrada de updates e saída de mensagens | `TOKEN_BOT` na URL; `secret_token` no webhook | `inclusos/Telegram.php`, `bot_web.php`, `config/`, `inclusos/funcoes_aviso_de_falha.php` |
| OpenRouter | chat e visão (OpenAI GPT-6 Luna) e transcrição (OpenAI Whisper) | `Authorization: Bearer OPENROUTER_API_KEY` | `comandos/funcoes_http_openai.php` e os `funcoes_*.php` das modalidades |
| Redis | cota, admissão e memória | `REDIS_PASSWORD` quando preenchida | `inclusos/funcoes.php`, `inclusos/redis_conexao.php`, `inclusos/funcoes_sondas.php` |

Não há outra integração: backup externo (rclone) não existe no código.

## Política comum

- **Timeouts explícitos**, nunca `default_socket_timeout`: 10 s de conexão;
  total de 15 s na Bot API, 90 s no chat, 120 s na transcrição e 20 s no
  download de arquivo. Cada chamada segura um filho do FPM, por isso o teto da
  Bot API é baixo. O `getUpdates` do polling usa o timeout pedido mais 10 s.
- **TLS sempre verificado** (`CURLOPT_SSL_VERIFYPEER = true`,
  `CURLOPT_SSL_VERIFYHOST = 2`). A Bot API usa IPv4 (`CURLOPT_IPRESOLVE`) e
  o stream do aviso de falha usa `bindto=0.0.0.0:0`
  ([incidente](OPERACAO.md#incidente-de-conectividade-com-o-telegram)).
- **Sem circuit breaker.** Reentrega antes do ACK e replay da inbox depois.
  A resposta de IA usa checkpoint e reagendamento no webhook; no polling,
  repete uma vez um 429 com `retry_after` de 1 a 60 s.
  Timeout e 5xx não autorizam retry automático de envio no transporte. Um
  replay integral da inbox ainda pode repetir efeitos externos.
- **Segredos chegam por constante** (`inclusos/config.php` via
  `config_loader.php`), nunca por variável de ambiente. Só o Redis lê
  `REDIS_*` do ambiente.
- **Nos testes ninguém fala com a rede**: rede desligada pelo SO e transportes
  substituídos por dublês ([TESTES.md](TESTES.md#sandbox-de-rede)).
- **Log de falha sem payload**: método, nomes de campos e `chat_id` no canal
  `telegram`; modalidade, status, errno e mensagem curta em `erros_openai`.

### Política de dados da IA

As chamadas de IA enviam `provider.data_collection=deny`,
`provider.ignore=["openai"]` e `provider.allow_fallbacks=true`; retentativas
preservam a política. O OpenRouter escolhe automaticamente entre provedores
elegíveis, excluindo o provedor OpenAI. Os IDs dos modelos `openai/*`
continuam identificando os modelos solicitados, sem fixar seu provedor. Isso filtra provedores pela política de coleta e não
comprova ZDR, residência regional ou configuração de logging da conta.
ZDR usa `provider.zdr=true` em endpoints compatíveis; opções da conta
OpenRouter são verificações separadas. Os dados enviados continuam sendo
processados para gerar a resposta.

Chat de texto, imagem e áudio transcrito envia `provider` como objeto; a
transcrição multipart envia `provider` como string JSON. Fonte:
`comandos/funcoes_{chatgpt,ver_imagem,ver_audio,transcricao_audio}.php`.

Referências: [roteamento](https://openrouter.ai/docs/guides/routing/provider-selection),
[logging dos provedores](https://openrouter.ai/docs/guides/privacy/provider-logging)
e [coleta pelo OpenRouter](https://openrouter.ai/docs/guides/privacy/data-collection).

## Telegram Bot API

### Entrada

Para receber webhooks, a própria instalação aceita **POST** em
`/bot_web.php`, sob o domínio HTTPS configurado em `DEALNI_PUBLIC_BASE_URL`,
com `X-Telegram-Bot-Api-Secret-Token`, comparado com `TELEGRAM_WEBHOOK_SECRET`
por `hash_equals()` ([autenticação](SEGURANCA.md#autenticação-e-autorização)).
Desenvolvimento: `getUpdates` em long polling, com offset confirmado depois do
lote.

Campos lidos: `message` (e `callback_query.message` para achar o chat),
`chat.type`, `from.id`, `text`, `caption`, `photo`, `voice`, `audio`,
`reply_to_message.from.id`, `message_thread_id` e `update_id`.

### Saída

| Método | Uso |
|---|---|
| `sendMessage` | respostas como reply com `parse_mode: Markdown`. Repete sem `parse_mode` quando o Telegram rejeita a formatação com 400 "can't parse entities" |
| `sendChatAction` | `typing`, depois do portão de cota; recusa definitiva de escrita encerra o comando antes da chamada paga |
| `getFile` + `https://api.telegram.org/file/bot<TOKEN_BOT>/<file_path>` | foto e áudio, por `baixarArquivoDoTelegram()` |
| `setWebhook` / `deleteWebhook` | `config/config_webhook.php` e `config/config_remover.php` |
| `sendMessage` do aviso de falha | texto puro por stream (sem curl), IPv4 e 10 s, em `inclusos/funcoes_aviso_de_falha.php` ([aviso](OPERACAO.md#aviso-de-falha-no-telegram)) |

Fora o aviso de falha, as chamadas passam por `Telegram::endpoint()` com as
opções de `inclusos/funcoes_curl_telegram.php` (`CONNECT_TIMEOUT_SECONDS` 10,
`REQUEST_TIMEOUT_SECONDS` 15). O download usa cURL com prazo total de 20 s,
IPv4, TLS verificado e sem seguir redirecionamentos. Um 404 nunca vira mídia,
e o callback de escrita interrompe a transferência no teto de bytes do fluxo.

`config/config_webhook.php` monta a URL a partir de `PUBLIC_BASE_URL` e chama
`setWebhook` com `url` e `secret_token` (conexão 5 s, total 15 s).

### Falhas

| Falha | O que acontece |
|---|---|
| Segredo ausente ou errado | 403 antes de qualquer dependência |
| PostgreSQL fora antes do ACK | falha de conexão devolve 500; falha ao registrar a inbox devolve 503; o Telegram reentrega |
| Falha depois do ACK | linha volta a `erro`; o cron retenta com backoff |
| Update duplicado | 200; se a linha já está processada, em processamento ou em backoff, não é roteada novamente nesta requisição |
| Markdown recusado | reenvio sem `parse_mode`; marcador simples aberto já sai como texto puro |
| Limite 429 na resposta de IA | webhook: checkpoint `pendente`, retomado pelo cron após `retry_after` positivo (30 s se inválido/ausente), sem nova IA/cota; polling: uma retentativa após 1 a 60 s |
| Outra recusa do `sendMessage` | sem reenvio; o turno não entra na memória |
| Recusa definitiva de escrita | 403, ou 400 com `CHAT_WRITE_FORBIDDEN`, `CHAT_RESTRICTED`, `chat not found`, `not enough rights to send` ou `have no rights to send` (`TelegramErrorLogger::ehRecusaDefinitivaDeEscrita()`): `warn` com `recusa_de_escrita`, e o chat fica 6 h sem chamada à Bot API ([DADOS.md](DADOS.md#chaves-e-ttls)) |
| Erro da Bot API | `TelegramErrorLogger` grava método, nomes dos campos e `chat_id` (`Telegram::contextoTecnicoDaFalha()`); o 400 "can't parse entities" da resposta de IA vira aviso quando o reenvio sem `parse_mode` entrega, e erro quando não entrega |
| Download falha ou estoura 20 s | aviso fixo; a chamada paga não sai |

O webhook não aguarda `retry_after` no FPM: preserva a resposta no PostgreSQL
antes de enviar e o cron retoma apenas a entrega pendente. Timeout, 5xx ou
interrupção durante envio deixam resultado indeterminado, sem reenvio automático;
400/403 definitivos ficam recusados. Estados e retenção estão em
[DADOS.md](DADOS.md#checkpoint-de-entrega-de-respostas-de-ia); revisão manual em
[OPERACAO.md](OPERACAO.md#resposta-de-ia-com-entrega-indeterminada).

A lease de 360 s é renovada por CAS antes de cada fase externa. A maior fase
tem teto de 120 s; atrasos de banco, Redis ou escalonamento não têm garantia de
caber na margem. Tentativa expirada ou substituída não renova nem inicia o
próximo efeito externo. Antes de gravar o checkpoint, uma falha ainda pode
repetir chamadas pagas; depois dele, o replay não regenera a resposta.

No polling, a retentativa curta bloqueia o processo: até três envios de 15 s
(fallback de Markdown e uma recusa 429), com espera de até 60 s. Esse caminho
não oferece o checkpoint durável do webhook/cron.

### Exemplos

```bash
# Simular um update no webhook local (só com o segredo certo).
curl -s -X POST http://localhost/bot_web.php \
  -H 'Content-Type: application/json' \
  -H "X-Telegram-Bot-Api-Secret-Token: $TELEGRAM_WEBHOOK_SECRET" \
  -d '{"update_id":1,"message":{"message_id":1,"chat":{"id":-100,"type":"supergroup"},"from":{"id":1},"text":"dealni, oi"}}'

# Conferir o webhook registrado (o secret não é devolvido).
curl -s "https://api.telegram.org/bot$TOKEN_BOT/getWebhookInfo"
```

## OpenRouter

Uma implementação cURL, `executarRequisicaoOpenAi()`, com conexão de 10 s e
total de 90 s (chat) ou 120 s (transcrição). Registra só diagnóstico técnico.

### Chat Completions

`POST https://openrouter.ai/api/v1/chat/completions`:

| Campo | Valor |
|---|---|
| `model` | `openai/gpt-6-luna` (texto, áudio transcrito e visão) |
| `temperature` | `1` |
| `max_completion_tokens` | `2048` |
| `reasoning` | `effort: xhigh`, `exclude: true` |
| `messages[0]` | papel `developer` com a persona e a data/hora de São Paulo |
| histórico | pares `user`/`assistant` da memória |
| imagem | `image_url.url` em `data:<mime>;base64,…`, `detail: high`, mais a legenda |

A resposta sai de `choices[0].message.content`. Conteúdo ausente, status fora de
2xx ou objeto `error` viram o aviso fixo; a categoria fixa vai para
`erros_openai` por `registrarErroDaApiOpenAi()`, sem persistir `error.message`.

### Transcrição

`POST https://openrouter.ai/api/v1/audio/transcriptions`, multipart, com
`model = openai/whisper-large-v3-turbo`, `language = pt` e nome/MIME coerentes
com a extensão (`metadadosDeUploadAudio()`; `.oga` vira `audio.ogg`). O texto
sai de `text` e passa por `cortarMensagem()`.

### Falhas

| Falha | O que acontece |
|---|---|
| Transporte, timeout, `curl_init` | `registrarFalhaHttpOpenAi()` em `erros_openai` (`OpenAI <contexto> falhou \| HTTP <n> \| cURL <errno> \| <erro truncado em 300>`); aviso fixo |
| HTTP fora de 2xx | idem, com `cURL 0`; o nível sai de `nivelDaFalhaHttpOpenAi()`: 401 e 402 são `error` e avisam o dono no Telegram ([aviso](OPERACAO.md#aviso-de-falha-no-telegram)); os demais 4xx e 5xx são `warning`, sem aviso; transporte e corpo com erro usam `error` |
| 2xx com `error` na raiz ou primeira choice | `registrarErroDaApiOpenAi()`; aviso fixo. Bloqueio persistente do usuário da API (`this user has been blocked for a previous policy violation`) também avisa o dono com a janela existente; moderação de conteúdo isolado não dispara esse aviso. |
| Resposta vazia ou só com espaços | aviso fixo; nada entra na memória; a string `"0"` é conteúdo válido |

Contextos no log: `chat.completions.texto`, `chat.completions.audio`,
`chat.completions.imagem` e `audio.transcriptions`.

```bash
curl -s https://openrouter.ai/api/v1/chat/completions \
  -H "Authorization: Bearer $OPENROUTER_API_KEY" -H 'Content-Type: application/json' \
  -d '{"model":"openai/gpt-6-luna","provider":{"allow_fallbacks":true,"data_collection":"deny","ignore":["openai"]},"messages":[{"role":"user","content":"oi"}]}'

curl -s https://openrouter.ai/api/v1/audio/transcriptions \
  -H "Authorization: Bearer $OPENROUTER_API_KEY" \
  -F model=openai/whisper-large-v3-turbo -F 'provider={"allow_fallbacks":true,"data_collection":"deny","ignore":["openai"]}' \
  -F 'file=@audio.ogg;type=audio/ogg'
```

## Redis

Local, `REDIS_HOST`/`REDIS_PORT` (padrão `127.0.0.1:6380`), 2 s de timeout de
conexão e leitura, autenticação antes do `PING` quando há senha. A sonda
`/ready` abre conexão própria. Chaves e contrato do servidor em
[DADOS.md](DADOS.md#cache); instância em
[OPERACAO.md](OPERACAO.md#identidade-e-redis-do-bot).

```bash
redis-cli -p 6380 PING
redis-cli -p 6380 --scan --pattern 'limite_diario:*'
redis-cli -p 6380 GET 'limite_diario:<id_do_grupo>:<AAAAMMDD>'
```
