# Nginx para uma instalação própria

O vhost em `dealnibot.conf` encaminha o webhook e as sondas a um pool PHP-FPM
dedicado. Substitua `bot.example.com` pelo seu domínio no vhost e em
`bots-correlacao.conf` e configure a mesma origem HTTPS em
`DEALNI_PUBLIC_BASE_URL`. O guia de [operação](../../docs/OPERACAO.md) cobre a
configuração do PHP-FPM e do TLS.

`/` usa internamente `/index.php`. As duas rotas redirecionam para o usuário do
bot no Telegram quando `DEALNI_BOT_USERNAME` está configurado e retornam 404
quando a variável está vazia.

## Canal de correlação

Inclua `bots-correlacao.conf` uma vez no contexto `http`, por exemplo em
`/etc/nginx/conf.d/bots-correlacao.conf`.

O mapa usa o `server_name` configurado. O canal grava uma linha JSON por
requisição em `/var/log/nginx/bots-correlacao.json.log`, com os campos `time`,
`server`, `status`, `bytes`, `request_time`, `upstream_time`, `upstream_status`,
`cf_ray` e `request_id`. URI, query, IP, User-Agent, referer e corpo ficam fora
do registro. O Nginx gera `request_id` e aceita `cf_ray` somente quando o valor
segue o formato de diagnóstico esperado.

`/health` e `/ready` desativam o log de acesso. Se o vhost declarar seu próprio
`access_log`, inclua nesse mesmo contexto os destinos que quer preservar.
Configure a rotação de `bots-correlacao.json.log`, valide com `nginx -t` e
recarregue o Nginx.
