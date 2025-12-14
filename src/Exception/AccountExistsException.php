<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountExistsException extends HttpException
{
    public function __construct(
        string $email,
        ?\Throwable $previous = null,
        array $headers = [],
        int $code = 0
    ) {
        $message = sprintf(
            'Un compte existe déjà avec l\'email "%s". Veuillez vous connecter ou utiliser un autre email.',
            $email
        );

        parent::__construct(409, $message, $previous, $headers, $code);
    }
}