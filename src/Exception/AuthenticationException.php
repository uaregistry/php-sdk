<?php

declare(strict_types=1);

namespace UARegistry\Sdk\Exception;

/**
 * Login was rejected (e.g. 2200 authentication error, 2002 already logged in). Carries
 * the EPP result code and response via CommandException.
 */
final class AuthenticationException extends CommandException
{
}
