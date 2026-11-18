<?php

declare(strict_types=1);

namespace App\Support;

/** Recibo do ensaio sintético; não certifica recuperação de backups de produção. */
final class ReciboRecuperacao
{
    /**
     * @return array{versao: int, conjunto: array{identificador: string, sha256: string}, integridade: array{localConferida: bool, transporteOffsite: string}, restauracao: array{executada: bool, bancoIsolado: string, origemDados: string, conjuntoProducaoRestaurado: bool}, escopo: array<string, string>, verificacoes: list<string>, limites: list<string>}
     */
    public static function sintetico(string $conjunto, string $sha256, string $banco): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1 || !str_starts_with($banco, 'chatbots_test_')) {
            throw new \InvalidArgumentException('Recibo exige SHA-256 e banco sintético isolado.');
        }

        return [
            'versao' => 1,
            'conjunto' => ['identificador' => basename($conjunto), 'sha256' => $sha256],
            'integridade' => ['localConferida' => true, 'transporteOffsite' => 'nao_verificado_neste_ensaio'],
            'restauracao' => [
                'executada' => true,
                'bancoIsolado' => $banco,
                'origemDados' => 'sintetica',
                'conjuntoProducaoRestaurado' => false,
            ],
            'escopo' => ['postgresql' => 'sintetico_restaurado', 'arquivos' => 'nao_ensaiados', 'redis' => 'nao_ensaiado', 'r2' => 'nao_reenviado', 'kv' => 'nao_reenviado'],
            'verificacoes' => ['SHA-256 e manifesto do dump', 'valor UTF-8 preservado', 'sequencia de IDs preservada'],
            'limites' => [
                'Somente tabela sintética; não restaura schema ou dados do produto.',
                'Não comprova transporte offsite nem disponibilidade de chaves externas.',
                'Não executa login, pagamento, envio Telegram ou recuperação de produção.',
            ],
        ];
    }
}
