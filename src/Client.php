<?php

declare(strict_types=1);

namespace UARegistry\Sdk;

use Psr\Log\LoggerInterface;
use UARegistry\Sdk\Command\Contact;
use UARegistry\Sdk\Command\Domain;
use UARegistry\Sdk\Command\Host;
use UARegistry\Sdk\Command\Poll;
use UARegistry\Sdk\Exception\AuthenticationException;
use UARegistry\Sdk\Exception\CommandException;
use UARegistry\Sdk\Exception\ConfigException;
use UARegistry\Sdk\Exception\ConnectionException;

/**
 * EPP client for the UARegistry service. Open a connection, log in, then reach the
 * object commands through resource handlers — $client->domain(), ->contact(), ->host(),
 * ->poll(). Each command returns a {@see Response}. By default any EPP error code
 * (>= 2000) is thrown as a {@see CommandException}; call throwOnFailure(false) to inspect
 * codes yourself instead.
 *
 *     $client = new Client(Config::fromArray(['host' => 'uaregistry.com', 'clid' => 'UAR0001', 'password' => '...']));
 *     $client->connect();
 *     $client->login();
 *     $avail = $client->domain()->check(['example.com.ua'])->availability();
 *     $client->logout();
 *     $client->disconnect();
 */
final class Client
{
    private Config $config;
    private Transport $connection;
    private ?LoggerInterface $logger;
    private ?Response $greeting = null;
    private bool $loggedIn = false;
    private bool $throwOnFailure = true;
    private int $tridCounter = 0;
    /** Middle clTRID segment — the OS process id, or a fallback token (set once in the ctor). */
    private string $processToken;

    private ?Domain $domain = null;
    private ?Contact $contact = null;
    private ?Host $host = null;
    private ?Poll $poll = null;

    public function __construct(Config $config, ?Transport $connection = null, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->connection = $connection ?? new Connection($config);
        $this->logger = $logger;
        // Middle clTRID segment: the OS process id. It matches the server's svTRID shape and
        // gives per-process uniqueness — ideal on shared/virtual hosting, where each request is
        // its own process — with NO CSPRNG dependency. If a host disables getmypid() via
        // disable_functions, fall back to a microtime-derived token (still no CSPRNG) so the SDK
        // degrades gracefully and never errors out.
        $pid = function_exists('getmypid') ? @getmypid() : false;
        $this->processToken = ($pid !== false && $pid !== null)
            ? (string) $pid
            : dechex(crc32(uniqid('', true)));
    }

    /** Open the connection in one step and log in. */
    public static function connectAndLogin(Config $config): self
    {
        $client = new self($config);
        $client->connect();
        $client->login();

        return $client;
    }

    /** Toggle automatic CommandException throwing on EPP error codes. */
    public function throwOnFailure(bool $throw = true): self
    {
        $this->throwOnFailure = $throw;

        return $this;
    }

    /** Attach (or clear) a PSR-3 logger; passwords/authInfo are masked before logging. */
    public function setLogger(?LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function __destruct()
    {
        try {
            $this->connection->close();
        } catch (\Throwable $e) {
            // Never throw from a destructor.
        }
    }

    // --- session ---------------------------------------------------------------

    /** Open the TLS socket and read the unsolicited <greeting>. */
    public function connect(): Response
    {
        if ($this->config->host === '') {
            throw new ConfigException('Config: host must not be empty');
        }
        if (!$this->connection->isOpen()) {
            $this->connection->open();
        }
        $raw = $this->connection->readFrame();
        if ($this->logger !== null) {
            $this->logger->debug('EPP << greeting', ['frame' => $raw]);
        }
        $this->greeting = Response::fromXml($raw);

        return $this->greeting;
    }

    public function greeting(): ?Response
    {
        return $this->greeting;
    }

    /** Send <hello>; the server replies with a fresh <greeting>. */
    public function hello(): Response
    {
        $this->connection->writeFrame(
            '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="' . Namespaces::EPP . '"><hello/></epp>'
        );
        $this->greeting = Response::fromXml($this->connection->readFrame());

        return $this->greeting;
    }

    /**
     * Authenticate. By default the login advertises exactly the services the server's
     * greeting offered (so it is never rejected for an unsupported service); override
     * with Config::$objUris / $extUris.
     *
     * Pass $newPassword to rotate the EPP password during login (RFC 5730 <newPW>); use
     * the new password for subsequent sessions once this returns success.
     */
    public function login(?string $newPassword = null): Response
    {
        // Catch the obvious config mistakes here, with a clear message, instead of letting
        // the server reject an empty <clID>/<pw> with a cryptic schema-validation 2001.
        if ($this->config->clid === '' || $this->config->password === '') {
            throw new ConfigException(sprintf(
                'login requires a non-empty clID and password (clID %s, password %s) — check your config/env',
                $this->config->clid !== '' ? 'set' : 'EMPTY',
                $this->config->password !== '' ? 'set' : 'EMPTY'
            ));
        }
        if ($this->greeting === null) {
            $this->connect();
        }

        $greetingObj = $this->greeting !== null ? $this->greeting->serviceObjUris() : [];
        $greetingExt = $this->greeting !== null ? $this->greeting->serviceExtUris() : [];
        $objUris = $this->config->objUris ?? ($greetingObj ?: Namespaces::DEFAULT_OBJ_URIS);
        $extUris = $this->config->extUris ?? ($greetingExt ?: Namespaces::DEFAULT_EXT_URIS);
        // The epp-1.0 base URI is not an object service and is never listed in <login>.
        $objUris = array_values(array_filter($objUris, static fn (string $u): bool => $u !== Namespaces::EPP));

        $frame = $this->frame();
        $login = $frame->verb('login');
        $frame->epp($login, 'clID', $this->config->clid);
        $frame->epp($login, 'pw', $this->config->password);
        if ($newPassword !== null) {
            $frame->epp($login, 'newPW', $newPassword);
        }
        $options = $frame->epp($login, 'options');
        $frame->epp($options, 'version', '1.0');
        $frame->epp($options, 'lang', $this->config->lang);
        $svcs = $frame->epp($login, 'svcs');
        foreach ($objUris as $uri) {
            $frame->epp($svcs, 'objURI', $uri);
        }
        if ($extUris !== []) {
            $svcExt = $frame->epp($svcs, 'svcExtension');
            foreach ($extUris as $uri) {
                $frame->epp($svcExt, 'extURI', $uri);
            }
        }

        $response = $this->transact($frame->toXml());
        if ($response->code() !== 1000) {
            throw new AuthenticationException(
                $response->code(),
                sprintf('Login failed (EPP %d): %s', $response->code(), $response->message() ?? 'no message'),
                $response
            );
        }
        $this->loggedIn = true;

        return $response;
    }

    public function logout(): Response
    {
        $frame = $this->frame();
        $frame->verb('logout');
        $response = $this->transact($frame->toXml()); // 1500; the server then closes the link
        $this->loggedIn = false;

        return $response;
    }

    public function disconnect(): void
    {
        $this->connection->close();
        $this->loggedIn = false;
    }

    public function isConnected(): bool
    {
        return $this->connection->isOpen();
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    // --- resource handlers -----------------------------------------------------

    public function domain(): Domain
    {
        return $this->domain ??= new Domain($this);
    }

    public function contact(): Contact
    {
        return $this->contact ??= new Contact($this);
    }

    public function host(): Host
    {
        return $this->host ??= new Host($this);
    }

    public function poll(): Poll
    {
        return $this->poll ??= new Poll($this);
    }

    /** Query the registrar account balance (creditLimit / balance / availableCredit). */
    public function balance(): Response
    {
        $frame = $this->frame();
        $frame->ns($frame->verb('info'), Namespaces::UAREG_BALANCE, 'balance:info');

        return $this->request($frame);
    }

    // --- low-level (used by the resource handlers, and for bespoke frames) ------

    /** A new command frame with an auto-generated clTRID already stamped. */
    public function frame(): Frame
    {
        return Frame::command($this->nextClTrid());
    }

    /**
     * Send a frame (a {@see Frame} or raw XML string) and return the parsed response.
     * Throws CommandException on an EPP error code unless throwOnFailure(false) is set.
     *
     * @param string|Frame $frame
     */
    public function request($frame): Response
    {
        return $this->execute($frame instanceof Frame ? $frame->toXml() : $frame);
    }

    // --- internals -------------------------------------------------------------

    private function transact(string $xml): Response
    {
        if (!$this->connection->isOpen()) {
            throw new ConnectionException('Not connected — call connect() first');
        }
        if ($this->logger !== null) {
            $this->logger->debug('EPP >> request', ['frame' => $this->redact($xml)]);
        }
        $this->connection->writeFrame($xml);
        $raw = $this->connection->readFrame();
        if ($this->logger !== null) {
            $this->logger->debug('EPP << response', ['frame' => $this->redact($raw)]);
        }

        $response = Response::fromXml($raw);
        if ($this->logger !== null) {
            $this->logger->log(
                $response->isSuccess() ? 'info' : 'warning',
                'EPP result ' . $response->code(),
                ['code' => $response->code(), 'svTRID' => $response->svTRID(), 'clTRID' => $response->clTRID()]
            );
        }

        return $response;
    }

    private function execute(string $xml): Response
    {
        $response = $this->transact($xml);
        if ($this->throwOnFailure && !$response->isSuccess()) {
            throw new CommandException(
                $response->code(),
                sprintf('EPP %d: %s', $response->code(), $response->message() ?? 'command failed'),
                $response
            );
        }

        return $response;
    }

    /** Mask passwords / authInfo (any namespace) before a frame is logged. */
    private function redact(string $xml): string
    {
        return (string) preg_replace(
            '~(<(?:[\w.-]+:)?(?:pw|newPW)>)(.*?)(</(?:[\w.-]+:)?(?:pw|newPW)>)~s',
            '$1***$3',
            $xml
        );
    }

    private function nextClTrid(): string
    {
        $this->tridCounter++;

        // A clTRID is a human-correlatable transaction label (not a secret), so it mirrors the
        // server's svTRID shape: a UTC timestamp for "when", the process token to group one
        // process's commands, and a monotonic counter for order. Prefix is Config::$clTRIDPrefix.
        return sprintf(
            '%s-%s-%s-%04d',
            $this->config->clTRIDPrefix,
            gmdate('YmdHis'),
            $this->processToken,
            $this->tridCounter
        );
    }
}
