<?php

declare(strict_types=1);

namespace UARegistry\Sdk;

/**
 * Immutable connection settings for a UARegistry EPP session.
 *
 * The public endpoint is strict RFC EPP over TLS (host uaregistry.com, port 700) and
 * needs NO client certificate. The optional clientCert / clientKey / clientKeyPassphrase
 * are only used if your endpoint requires mutual TLS. When objUris / extUris are left
 * null the client logs in advertising exactly the services the server greeting offers,
 * so it is never rejected for an unsupported service.
 */
final class Config
{
    public string $host;
    public string $clid;
    public string $password;
    public int $port;
    public string $lang;
    public float $connectTimeout;
    public float $readTimeout;
    public bool $verifyPeer;
    public bool $verifyPeerName;
    /** CA bundle that signs the SERVER certificate (private-CA / self-signed endpoint). */
    public ?string $caFile;
    /** Your (registrar) client certificate — only when mutual TLS is required. PEM path. */
    public ?string $clientCert;
    /** OUR client private key. PEM path. May be omitted when bundled in clientCert. */
    public ?string $clientKey;
    /** Passphrase for an encrypted client private key, if any. */
    public ?string $clientKeyPassphrase;
    /** @var string[]|null Override the login objURIs; null = use the greeting's. */
    public ?array $objUris;
    /** @var string[]|null Override the login extURIs; null = use the greeting's. */
    public ?array $extUris;
    /** Prefix for auto-generated client transaction ids (clTRID). */
    public string $clTRIDPrefix;

    public function __construct(
        string $host,
        string $clid,
        string $password,
        int $port = 700,
        string $lang = 'en',
        float $connectTimeout = 10.0,
        float $readTimeout = 30.0,
        bool $verifyPeer = true,
        bool $verifyPeerName = true,
        ?string $caFile = null,
        ?string $clientCert = null,
        ?string $clientKey = null,
        ?string $clientKeyPassphrase = null,
        ?array $objUris = null,
        ?array $extUris = null,
        string $clTRIDPrefix = 'UAR-SDK'
    ) {
        $this->host = $host;
        $this->clid = $clid;
        $this->password = $password;
        $this->port = $port;
        $this->lang = $lang;
        $this->connectTimeout = $connectTimeout;
        $this->readTimeout = $readTimeout;
        $this->verifyPeer = $verifyPeer;
        $this->verifyPeerName = $verifyPeerName;
        $this->caFile = $caFile;
        $this->clientCert = $clientCert;
        $this->clientKey = $clientKey;
        $this->clientKeyPassphrase = $clientKeyPassphrase;
        $this->objUris = $objUris;
        $this->extUris = $extUris;
        $this->clTRIDPrefix = $clTRIDPrefix;
    }

    /**
     * @param array<string, mixed> $values keys match the constructor parameters
     */
    public static function fromArray(array $values): self
    {
        return new self(
            (string) ($values['host'] ?? ''),
            (string) ($values['clid'] ?? ''),
            (string) ($values['password'] ?? ''),
            (int) ($values['port'] ?? 700),
            (string) ($values['lang'] ?? 'en'),
            (float) ($values['connectTimeout'] ?? 10.0),
            (float) ($values['readTimeout'] ?? 30.0),
            (bool) ($values['verifyPeer'] ?? true),
            (bool) ($values['verifyPeerName'] ?? true),
            isset($values['caFile']) ? (string) $values['caFile'] : null,
            isset($values['clientCert']) ? (string) $values['clientCert'] : null,
            isset($values['clientKey']) ? (string) $values['clientKey'] : null,
            isset($values['clientKeyPassphrase']) ? (string) $values['clientKeyPassphrase'] : null,
            isset($values['objUris']) ? (array) $values['objUris'] : null,
            isset($values['extUris']) ? (array) $values['extUris'] : null,
            (string) ($values['clTRIDPrefix'] ?? 'UAR-SDK')
        );
    }

    /**
     * The stream-context options for the TLS socket.
     *
     * @return array<string, array<string, mixed>>
     */
    public function streamContextOptions(): array
    {
        $ssl = [
            'verify_peer'      => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeerName,
            'SNI_enabled'      => true,
            'peer_name'        => $this->host,
            // EPP runs over modern TLS; refuse anything below 1.2.
            'crypto_method'    => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        if ($this->caFile !== null) {
            $ssl['cafile'] = $this->caFile;
        }
        if ($this->clientCert !== null) {
            $ssl['local_cert'] = $this->clientCert;
        }
        if ($this->clientKey !== null) {
            $ssl['local_pk'] = $this->clientKey;
        }
        if ($this->clientKeyPassphrase !== null) {
            $ssl['passphrase'] = $this->clientKeyPassphrase;
        }

        return ['ssl' => $ssl];
    }
}
