<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade UpdateData da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait UpdateDataTrait
{
    /// Get the data of the current message

    /** Get the POST request of a user in a webhook or the message actually processed in a getUpdates() environment.
     * \return the JSON users's message.
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if ($this->data === []) {
            $rawData = \file_get_contents('php://input');
            if ($rawData === false) {
                return [];
            }

            $decoded = \json_decode($rawData, true);

            return $this->arrayOrNull($decoded) ?? [];
        }
        return $this->data;
    }

    /// Set the data currently used
    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
        $this->update_type = null;
    }

    /// Narrow a raw update value to string (accessors' honest return types)
    /**
     * @param mixed $value
     */
    private function stringOrNull($value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    /// Narrow a raw update value to int (accessors' honest return types)
    /**
     * @param mixed $value
     */
    private function intOrNull($value): ?int
    {
        return \is_int($value) ? $value : null;
    }

    /// Narrow a raw update value to array (accessors' honest return types)
    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function arrayOrNull($value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        // Objetos JSON da Bot API sempre têm chaves string; filtrar prova o
        // contrato declarado em vez de apenas afirmá-lo (mesmo padrão do
        // normalizeRow de bdTelegram).
        $narrowed = [];
        foreach ($value as $key => $item) {
            if (\is_string($key)) {
                $narrowed[$key] = $item;
            }
        }

        return $narrowed;
    }

    /// Percorre com segurança um caminho aninhado dentro de $this->data
    /**
     * Substitui o antigo padrão $this->dataPath('a', 'b', 'c'): como $this->data
     * vem de json_decode (mixed), cada nível precisa ser provado como array
     * antes do acesso. Devolve null se qualquer nível não for array ou se a
     * chave não existir — exatamente o resultado que o @ produzia antes.
     *
     * @param string|int ...$keys
     * @return mixed
     */
    private function dataPath(...$keys)
    {
        $current = $this->data;
        foreach ($keys as $key) {
            if (!\is_array($current) || !\array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /// Devolve com segurança a lista de updates retornada por getUpdates()
    /**
     * $this->updates vem do endpoint (mixed). Aqui provamos que existe a chave
     * 'result' e que ela é uma lista indexada por inteiros, devolvendo um
     * array vazio quando a resposta não tem o formato esperado.
     *
     * @return array<int, mixed>
     */
    private function updatesResult(): array
    {
        if (!isset($this->updates['result']) || !\is_array($this->updates['result'])) {
            return [];
        }

        $lista = [];
        foreach ($this->updates['result'] as $key => $item) {
            if (\is_int($key)) {
                $lista[$key] = $item;
            }
        }

        return $lista;
    }

}
