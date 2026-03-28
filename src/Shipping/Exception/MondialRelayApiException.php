<?php

namespace App\Shipping\Exception;

class MondialRelayApiException extends MondialRelayException
{
    /**
     * @param string[] $errorCodes
     */
    public function __construct(
        string $message,
        private readonly array $errorCodes = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return string[]
     */
    public function getErrorCodes(): array
    {
        return $this->errorCodes;
    }
}
