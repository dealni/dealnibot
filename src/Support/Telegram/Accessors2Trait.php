<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Accessors da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait Accessors2Trait
{
    /// Get the title of the group chat
    /**
     *  \return a String of the title chat.
     */
    public function messageFromGroupTitle(): string
    {
        if ($this->dataPath('message', 'chat', 'type') !== 'private') {
            return $this->stringOrNull($this->dataPath('message', 'chat', 'title')) ?? '';
        }

        return '';
    }

}
