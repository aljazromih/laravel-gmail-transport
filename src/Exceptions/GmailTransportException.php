<?php

namespace AljazRomih\GmailTransport\Exceptions;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Every exception of the package is a Symfony TransportException, so code that already catches
 * TransportExceptionInterface around Mail::send() keeps working.
 */
class GmailTransportException extends TransportException {}
