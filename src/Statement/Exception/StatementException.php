<?php

namespace App\Statement\Exception;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A statement the user must hear about: its message is a translation key, like a security exception's, so that
 * it can be stored and shown later, in the user's language.
 */
abstract class StatementException extends \RuntimeException implements TranslatableInterface
{
    /**
     * @param array<string, string> $messageData
     */
    public function __construct(
        private readonly string $messageKey,
        private readonly array $messageData = [],
    ) {
        parent::__construct($messageKey);
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * @return array<string, string>
     */
    public function getMessageData(): array
    {
        return $this->messageData;
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->messageKey, $this->messageData, locale: $locale);
    }
}
