<?php

declare(strict_types=1);

namespace UARegistry\Sdk;

/**
 * EPP namespace URIs spoken by the UARegistry public endpoint (port 700, strict RFC
 * profile). These are protocol constants — the exact wire strings — not shared code.
 */
final class Namespaces
{
    public const EPP = 'urn:ietf:params:xml:ns:epp-1.0';
    public const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    // Standard RFC object mappings (RFC 5731/5732/5733).
    public const DOMAIN = 'urn:ietf:params:xml:ns:domain-1.0';
    public const CONTACT = 'urn:ietf:params:xml:ns:contact-1.0';
    public const HOST = 'urn:ietf:params:xml:ns:host-1.0';

    // Standard extensions.
    public const SECDNS = 'urn:ietf:params:xml:ns:secDNS-1.1'; // RFC 5910
    public const RGP = 'urn:ietf:params:xml:ns:rgp-1.0';       // RFC 3915

    // UARegistry extensions: the .ua trademark licence (<uareg:license>) and the
    // registrar account balance (creditLimit / balance / availableCredit).
    public const UAREG_EXT = 'http://uaregistry.com/epp/uaregistry-1.0';
    public const UAREG_BALANCE = 'http://uaregistry.com/epp/balance-1.0';

    /** Object services a client logs in with by default (standard RFC mappings). */
    public const DEFAULT_OBJ_URIS = [self::CONTACT, self::DOMAIN, self::HOST];

    /** Extension services the server advertises by default. */
    public const DEFAULT_EXT_URIS = [self::SECDNS, self::RGP, self::UAREG_EXT, self::UAREG_BALANCE];
}
