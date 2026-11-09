<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

/**
 * Attachment action refused (GOKINT-1099). The message is an English source
 * string of the Modules.Inpostvonhalsky.Admin domain, translated with getParams().
 */
class OfferAttachmentException extends \RuntimeException
{
    /** @var array<string,string|int> */
    private array $params;

    /**
     * @param array<string,string|int> $params
     */
    public function __construct(string $message, array $params = [])
    {
        parent::__construct($message);
        $this->params = $params;
    }

    /**
     * @return array<string,string|int>
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
