<?php

namespace App\Exceptions;

use RuntimeException;

class SlackApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $slackError = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
