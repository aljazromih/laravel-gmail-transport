<?php

namespace AljazRomih\GmailTransport\Exceptions;

/**
 * A refusal from Google's OAuth endpoints. `$error` is Google's code, e.g. `invalid_grant`.
 */
class GoogleOAuthException extends GmailTransportException
{
    public function __construct(public readonly string $error, string $message)
    {
        parent::__construct($message);
    }
}
