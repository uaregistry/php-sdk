<?php

declare(strict_types=1);

namespace UARegistry\Sdk\Exception;

use UARegistry\Sdk\Response;

/**
 * The server answered with an EPP error result code (>= 2000). The code and the full
 * parsed response are attached so the caller can branch on them.
 */
class CommandException extends EppException
{
    /** The EPP result code (>= 2000). */
    public int $eppCode;

    /** The full parsed response, if one was received. */
    public ?Response $response;

    public function __construct(int $eppCode, string $message, ?Response $response = null)
    {
        $this->eppCode = $eppCode;
        $this->response = $response;
        parent::__construct($message, $eppCode);
    }
}
