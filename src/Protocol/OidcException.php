<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelAdminOidc\Protocol;

/**
 * Something about the provider or its answer that stops the sign-in. The
 * reason key is stable (logs, events, tests); the message is for whoever
 * reads the log — the person signing in sees a translated sentence.
 */
class OidcException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
