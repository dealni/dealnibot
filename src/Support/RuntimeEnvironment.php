<?php

declare(strict_types=1);

namespace App\Support;

final class RuntimeEnvironment
{
    /**
     * Errno EPERM — a assinatura de que o sandbox do sistema operacional está
     * no caminho: o kernel nega a PERMISSÃO de abrir o socket. Fora do sandbox
     * o mesmo endereço abre, ou falha com ECONNREFUSED (61)/ETIMEDOUT (60).
     */
    private const PERMISSAO_NEGADA = 1;

    /**
     * Sondas do isolamento no macOS. Nenhuma das duas emite pacote: `connect`
     * de UDP não transmite nada, e TCP em porta fechada do loopback resolve
     * dentro da máquina. 192.0.2.0/24 é a TEST-NET-1 da RFC 5737, reservada
     * para documentação e não roteável — mesmo que o sandbox falhasse, não há
     * para onde o pacote ir.
     *
     * @var list<string>
     */
    private const SONDAS_DE_REDE = [
        'udp://192.0.2.1:9',
        'tcp://127.0.0.1:1',
    ];

    public static function name(): string
    {
        $environment = getenv('APP_ENV');

        return is_string($environment) && $environment !== '' ? $environment : 'production';
    }

    public static function isTesting(): bool
    {
        return self::name() === 'test' && getenv('CHATBOTS_TESTING') === '1';
    }

    public static function hasNetworkIsolation(): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return getenv('CHATBOTS_OS_NETWORK_SANDBOX') === '1'
                && self::sandboxNegaSockets();
        }

        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        // getifaddrs consulta a namespace deste processo; um sysfs montado
        // antes de unshare pode continuar mostrando as interfaces do host.
        $interfaces = @net_get_interfaces();

        return is_array($interfaces) && array_keys($interfaces) === ['lo'];
    }

    /**
     * Prova, por tentativa real, que este processo não abre socket algum — nem
     * para fora, nem no loopback.
     *
     * Substitui a checagem antiga `PHP_SAPI === 'phpdbg'`, que atestava apenas
     * QUAL binário rodava a suíte, e não que a rede estava fechada: era um
     * proxy do isolamento, e o proxy morreu quando o PHPUnit 10 deixou de
     * funcionar sob phpdbg. Aqui a propriedade que interessa é medida direto.
     *
     * O portão só abre com EPERM em TODAS as sondas. Qualquer outro desfecho —
     * socket que abre, endereço inválido, descritor esgotado — mantém o portão
     * fechado, porque nenhum deles prova que a rede está negada. Falhar assim
     * é o lado seguro: sem prova, `tests/bootstrap.php` recusa a suíte.
     */
    private static function sandboxNegaSockets(): bool
    {
        foreach (self::SONDAS_DE_REDE as $endereco) {
            $codigoErro = 0;
            $mensagemErro = '';
            $socket = @stream_socket_client(
                $endereco,
                $codigoErro,
                $mensagemErro,
                1.0,
                STREAM_CLIENT_CONNECT
            );

            if ($socket !== false) {
                fclose($socket);

                return false;
            }

            if ($codigoErro !== self::PERMISSAO_NEGADA) {
                return false;
            }
        }

        return true;
    }
}
