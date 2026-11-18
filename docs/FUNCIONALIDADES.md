# Funcionalidades — DealniBot

O comportamento visível a quem usa e a quem opera: gatilhos, regras, mensagens,
limites, automatismos e fluxos que não podem quebrar. A montagem interna está
em [ARQUITETURA.md](ARQUITETURA.md); os contratos externos, em
[INTEGRACOES.md](INTEGRACOES.md); os testes que travam cada regra, em
[TESTES.md](TESTES.md#invariantes-críticas).

## Panorama

Os fluxos abaixo descrevem o comportamento de uma instalação própria.

| Funcionalidade | Onde vive | Uso no checkout |
|---|---|---|
| Conversa por texto em grupo | `comandos/chatgpt.php` + `funcoes_chatgpt.php` | implementado |
| Descrição de imagem | `comandos/ver_imagem.php` + `funcoes_ver_imagem.php` | implementado |
| Transcrição de áudio e resposta | `comandos/ver_audio.php` + `funcoes_ver_audio.php` + `funcoes_transcricao_audio.php` | implementado |
| Memória conversacional | `comandos/funcoes_memoria_conversa.php` | implementado |
| Cota diária e admissão de mídia | `inclusos/limites_requisicoes.php` | implementado |
| Inbox durável do webhook | `inclusos/funcoes_webhook_inbox.php`, `processos/processar_webhook_inbox.php` | implementado; replay por cron |
| Cadastro de contatos no privado | `roteador.php` + `inclusos/bdTelegram.php` | implementado |
| Snapshot diário do banco | `processos/snapshot_banco.php` | [cron ou timer](OPERACAO.md#rotinas-automáticas), conforme a instalação |
| Registro/remoção do webhook | `config/config_webhook.php`, `config/config_remover.php` | manual |
| Sondas `/health` e `/ready` | `health.php`, `ready.php` | implementado |
| Página pública | `index.php` | redireciona para o bot da própria instância quando `DEALNI_BOT_USERNAME` está definido |

## Quando o bot responde

Só em **grupo e supergrupo**, e só nestes casos:

| Gatilho | Detalhe |
|---|---|
| Resposta a uma mensagem dele | o ID do autor em `reply_to_message.from.id` é o do bot configurado |
| Menção pelo nome | busca `dealni` ou o username de `DEALNI_BOT_USERNAME` no texto ou legenda da foto, sem distinguir maiúsculas |
| Sorteio | 10% das demais mensagens (`rand(1, 10) === 1`) |

Fora do privado só `message`, `photo`, `voice` e `audio` são atendidos; vídeo,
documento, sticker, `callback_query`, `edited_message` e `channel_post` são
descartados antes de qualquer custo.

Grupo que tirou do bot o direito de escrever fica calado por 6 h: a primeira
recusa definitiva do Telegram (bot removido, bloqueado ou sem permissão de
mandar mensagem) é lembrada no Redis e, até vencer, nenhum gatilho roda nem a
Bot API é chamada para esse chat. Se o grupo devolver a permissão, o bot volta
sozinho no vencimento.

## O privado

O roteador registra o contato e responde "O assistente responde em grupos.
Adicione o bot ao seu grupo para conversar.", sem consumir cota. Se o registro
falhar, a inbox permite uma nova tentativa.

## O que ele faz com a mensagem

| Conteúdo | Rota | O que acontece |
|---|---|---|
| Texto | `comandos/chatgpt.php` | responde com `openai/gpt-6-luna` e raciocínio `xhigh`, usando o histórico |
| Foto | `comandos/ver_imagem.php` | baixa o arquivo e envia `data:` base64 (`detail: high`) com a legenda cortada em 1.024 caracteres |
| Voz ou áudio | `comandos/ver_audio.php` | transcreve com `openai/whisper-large-v3-turbo` e responde à transcrição |

Prioridade: **foto > voz/áudio > texto**. Na menção pelo nome só a foto vira
imagem; voz e áudio seguem como texto. Texto vazio não gera chamada; `"0"` é
conteúdo válido.

No webhook, a resposta pronta é persistida antes do envio. HTTP 429 agenda
somente sua entrega pelo cron, sem esperar no FPM, chamar IA ou consumir cota
novamente. Resultado ambíguo fica para revisão, sem reenvio automático. Fora da
inbox, o polling permite uma tentativa adicional com `retry_after` de 1 a 60 s.
O [contrato do checkpoint](DADOS.md#checkpoint-de-entrega-de-respostas-de-ia)
define estados e retenção; os detalhes de transporte estão em
[INTEGRACOES.md](INTEGRACOES.md#falhas).

## Cota diária e admissão de mídia

**100 unidades por dia por grupo**, no dia de `America/Sao_Paulo`. Texto e
imagem gastam uma; áudio gasta duas. A reserva é atômica no Redis: se não cabe,
é recusada inteira. Redis fora **falha fechado**. O aviso de cota esgotada é
omitido em grupo para o bot não virar ruído.

Antes de `getFile`, foto e áudio passam pela admissão: **5 aquisições por
sujeito e 20 no bot por janela de 60 s**. Recusas não prolongam a janela; mídia
inválida também conta, sem gastar cota paga. A admissão confere o saldo diário,
mas a reserva paga só acontece depois da validação da mídia. O limite de rajada
é silencioso em grupo.

## Memória conversacional

Texto, áudio e imagem alimentam o mesmo histórico, separado por grupo, tópico e
participante. É rolante (até 8 turnos) e expira em 24 h. Não viram turno: falha
do provedor, resposta que o Telegram não entregou e a imagem em si (só a legenda
entra). Chaves em [DADOS.md](DADOS.md#chaves-e-ttls).

## Scripts de operador

Rodados à mão no host; todos executam no `include`
([regra](CONVENCOES.md#nunca-execute-os-scripts-de-topo)).

| Script | Para quê |
|---|---|
| `config/config_webhook.php` | `setWebhook` com o `secret_token` |
| `config/config_remover.php` | `deleteWebhook` |

Os scripts devolvem código diferente de zero se o Telegram recusar a operação.

## O que roda sem ninguém pedir

| Automatismo | Quando | O que faz |
|---|---|---|
| `processos/processar_webhook_inbox.php` | cron, a cada minuto | retenta updates que falharam depois do ACK; limpa processados após 7 dias e falhas após 30 |
| `processos/snapshot_banco.php` | modelo cron às 5h10 em `America/Sao_Paulo` | `pg_dump`, rotação e o desfecho no canal `snapshot_banco` |
| `scripts/operacao/monitorar_saude.sh` | cron, a cada 5 minutos | consulta `/health` e `/ready` e registra falha e recuperação ([alertas](OPERACAO.md#alertas-de-saúde)) |
| Aviso ao dono no Telegram | job do cron que falha; OpenRouter com 401 ou 402 | uma mensagem por hora no máximo ([aviso](OPERACAO.md#aviso-de-falha-no-telegram)) |
| Expiração da memória e da cota | TTL do Redis | 24 h |
| Expiração da recusa de escrita | TTL do Redis | 6 h |
| Rotação do log | Monolog | um arquivo por dia, 14 mantidos |

A retenção de contatos **não** roda: `composer privacy:retention` é inativo
([DADOS.md](DADOS.md#retenção)).

## Limites e mensagens visíveis

- Toda resposta vai como **reply** (`reply_parameters` com
  `allow_sending_without_reply`). Usa Markdown quando os marcadores estão
  balanceados; marcador simples aberto já sai como texto puro. Se o Telegram
  recusar a formatação (HTTP 400 "can't parse entities"), há fallback sem
  `parse_mode`. Sem entrega confirmada, o turno não é salvo.
- `sendChatAction` "typing" sai **depois** do portão de cota.
- Texto cortado em **4.096 caracteres** por `cortarMensagem()`, no último
  espaço quando houver.
- A persona vive no papel `developer`, com data e hora de São Paulo.
- Falha do provedor vira o **aviso fixo do comando**, nunca o `error.message`,
  que fala da conta e viraria texto público no grupo.
- Formato de imagem desconhecido: "Não reconheci o formato dessa imagem. Tente
  JPEG, PNG, GIF ou WebP!". Áudio grande: "Esse áudio é grande demais. Envie
  até 20 MiB.".

### Áudio e imagem

| Regra | Valor |
|---|---|
| Duração do áudio | até 3 minutos, conferida antes do download |
| Tamanho do áudio | 20 MiB (limite local) |
| Tamanho da imagem | 10 MiB (o base64 custa 4/3 do binário) |
| Timeout do download do Telegram | 20 s totais por download cURL |
| Formatos de áudio | `mp3`, `mp4`, `mpeg`, `mpga`, `m4a`, `wav`, `webm`, `flac`, `ogg`; `.oga` e extensão desconhecida vão como `ogg` |
| Formatos de imagem | JPEG, PNG, GIF não animado e WebP, deduzidos dos **bytes** |

Transcrição vazia encerra antes do chat; as duas unidades já reservadas da cota
não são devolvidas, inclusive quando o provedor falha. O temporário do áudio é
removido em `finally`. A imagem vira base64 **em memória**, porque a URL de
download do Telegram carrega o `TOKEN_BOT` ([SEGURANCA.md](SEGURANCA.md#modelo-de-ameaças)).

## Fluxos críticos ponta a ponta

### Uma mensagem de texto em grupo vira resposta

1. O Telegram faz `POST` em `bot_web.php`; o ciclo abre o `request_id`.
2. Segredo divergente: **403** antes de abrir dependências.
3. Sem `update_id` inteiro: **400**. `registrarUpdateWebhook()` grava a linha;
   falha: **503**.
4. Sai o **200** e `fastcgi_finish_request()` libera a conexão.
5. `processarProximoUpdateWebhook()` toma a lease e inclui o roteador.
6. O roteador confere grupo, tipo de update e gatilho; sem gatilho, `return`.
7. `executarComandoChatgpt()`: `consumir_limite_diario()` reserva uma unidade
   do grupo, "typing", leitura do histórico, `chat/completions`.
8. `enviarRespostaIa()` responde; entrega confirmada grava o turno por
   compare-and-set e a linha da inbox vira `processado`.

### Um áudio vira transcrição e resposta

Igual até o passo 6. Depois: `audioDaMensagem()` prefere `voice` a `audio` →
duração acima de 3 minutos é recusada → `admitir_midia()` com duas unidades →
`getFile` → download com teto de 20 s e 20 MiB para temporário `0600` →
`consumir_limite_diario(…, 2)` → `audio/transcriptions` → temporário removido →
transcrição vazia encerra → `chat/completions` → resposta e memória.

### Uma falha depois do ACK

`registrarFalhaDoRoteador()` grava no canal `roteador_erros` e
`marcarUpdateWebhookFalho()` devolve a linha a `erro` com backoff; erro fatal
passa por `register_shutdown_function`. O cron retoma quando o backoff permite.
Se o processo morrer sem executar o shutdown handler, a linha continua `processando` e volta à fila após a lease de
360 s desde a última renovação. Antes de existir checkpoint de resposta, a
retomada ainda pode repetir chamadas pagas. Com checkpoint, o replay retoma
somente entrega pendente ou classifica um envio interrompido como indeterminado,
sem reenviar. A inbox não torna todos os efeitos externos idempotentes
([diagnóstico](OPERACAO.md#diagnóstico-de-problemas-comuns)).

## Garantias transversais

| Garantia | Como é sustentada |
|---|---|
| Nenhuma chamada paga sem cota | `consumir_limite_diario()` falha fechado; o bloco de cota dos três comandos é travado na fonte por teste |
| Persistência e reserva de updates aceitos | inbox com chave por `update_id`, lease e replay; confirmação exige a tentativa atual, mas efeitos externos podem se repetir após falha |
| Exceção depois do ACK registrada | captura no processamento da inbox, canal `roteador_erros`; encerramento abrupto pode deixar só a lease para recuperação |
| Nenhum segredo no log | `ProcessadorDeRedacao` em todo canal |
| Nenhum temporário esquecido | `finally` no fluxo de áudio |
| Fallback para Markdown recusado | reenvio sem `parse_mode` quando o Telegram recusa a formatação; a segunda entrega também pode falhar |
| Nenhuma escrita de banco silenciosa | `bdTelegram::queryExec()` registra no canal `bd` |
| Nenhum reenvio em lote no polling | `return` em vez de `exit`; nenhum comando declara função no próprio arquivo |
