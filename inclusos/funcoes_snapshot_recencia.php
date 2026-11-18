<?php

declare(strict_types=1);

/** A data UTC no nome diário evita tratar uma cópia antiga tocada como nova. */
function localizarSnapshotDiarioRecente(string $diretorio, int $agora, int $idadeMaxima = 129_600): ?string
{
    if ($agora <= 0 || $idadeMaxima <= 0) {
        return null;
    }
    $candidatos = array_reverse(listarCandidatosSnapshotsBanco($diretorio));
    foreach ($candidatos as $nome) {
        $data = DateTimeImmutable::createFromFormat('!Y-m-d-His', substr($nome, 4, -5), new DateTimeZone('UTC'));
        if ($data === false || $data->format('Y-m-d-His') !== substr($nome, 4, -5)) {
            continue;
        }
        $instante = $data->getTimestamp();
        if ($instante > $agora || $instante < $agora - $idadeMaxima) {
            continue;
        }
        $arquivo = $diretorio . '/' . $nome;
        if (snapshotBancoIntegro($arquivo)) {
            return $arquivo;
        }
    }
    return null;
}
