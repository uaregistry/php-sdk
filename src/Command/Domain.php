<?php

declare(strict_types=1);

namespace UARegistry\Sdk\Command;

use UARegistry\Sdk\Client;
use UARegistry\Sdk\Frame;
use UARegistry\Sdk\Namespaces;
use UARegistry\Sdk\Response;

/**
 * Domain object commands (RFC 5731) plus the UARegistry .ua licence, secDNS (RFC 5910)
 * and RGP restore (RFC 3915) extensions. Reached via Client::domain().
 */
final class Domain
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** @param string[] $names */
    public function check(array $names): Response
    {
        $frame = $this->client->frame();
        $check = $frame->ns($frame->verb('check'), Namespaces::DOMAIN, 'domain:check');
        foreach ($names as $name) {
            $frame->ns($check, Namespaces::DOMAIN, 'domain:name', $name);
        }

        return $this->client->request($frame);
    }

    /** @param string $hosts nameserver detail to return: "all" (default) or "sub". */
    public function info(string $name, ?string $authInfo = null, string $hosts = 'all'): Response
    {
        $frame = $this->client->frame();
        $info = $frame->ns($frame->verb('info'), Namespaces::DOMAIN, 'domain:info');
        $frame->ns($info, Namespaces::DOMAIN, 'domain:name', $name, ['hosts' => $hosts]);
        if ($authInfo !== null) {
            $ai = $frame->ns($info, Namespaces::DOMAIN, 'domain:authInfo');
            $frame->ns($ai, Namespaces::DOMAIN, 'domain:pw', $authInfo);
        }

        return $this->client->request($frame);
    }

    /**
     * @param array{years?:int,registrant?:string,contacts?:array<string,string>,
     *     nameservers?:string[],authInfo?:string,license?:string,
     *     secDNS?:array<string,mixed>} $options
     */
    public function create(string $name, array $options = []): Response
    {
        $frame = $this->client->frame();
        $create = $frame->ns($frame->verb('create'), Namespaces::DOMAIN, 'domain:create');
        $frame->ns($create, Namespaces::DOMAIN, 'domain:name', $name);
        if (isset($options['years'])) {
            $frame->ns($create, Namespaces::DOMAIN, 'domain:period', (string) (int) $options['years'], ['unit' => 'y']);
        }
        if (!empty($options['nameservers'])) {
            $ns = $frame->ns($create, Namespaces::DOMAIN, 'domain:ns');
            foreach ((array) $options['nameservers'] as $host) {
                $frame->ns($ns, Namespaces::DOMAIN, 'domain:hostObj', (string) $host);
            }
        }
        if (isset($options['registrant'])) {
            $frame->ns($create, Namespaces::DOMAIN, 'domain:registrant', (string) $options['registrant']);
        }
        foreach ((array) ($options['contacts'] ?? []) as $type => $handle) {
            $frame->ns($create, Namespaces::DOMAIN, 'domain:contact', (string) $handle, ['type' => (string) $type]);
        }
        // authInfo is MANDATORY on domain:create per RFC 5731 (domain:createType requires it). Always
        // emit it — with the caller's transfer secret, or an empty <pw/> (schema-valid: pwType allows
        // minLength 0) so the registry then applies its per-zone authInfo policy (e.g. auto-mint). The
        // SDK's own contact:create emits <pw> unconditionally too; only this path was the outlier, and
        // omitting the element made every register-without-a-password fail server XSD validation.
        $ai = $frame->ns($create, Namespaces::DOMAIN, 'domain:authInfo');
        $frame->ns($ai, Namespaces::DOMAIN, 'domain:pw', (string) ($options['authInfo'] ?? ''));

        $secDns = $options['secDNS'] ?? null;
        $license = $options['license'] ?? null;
        // secDNS:create requires at least one dsData|keyData (dsOrKeyType) — an empty/keyless
        // secDNS array must NOT emit a childless <secDNS:create/>, which the server XSD rejects.
        $hasSecDns = is_array($secDns) && (!empty($secDns['dsData']) || !empty($secDns['keyData']));
        if ($hasSecDns || $license !== null) {
            $ext = $frame->extension();
            if ($hasSecDns) {
                $secCreate = $frame->ns($ext, Namespaces::SECDNS, 'secDNS:create');
                if (isset($secDns['maxSigLife'])) {
                    $frame->ns($secCreate, Namespaces::SECDNS, 'secDNS:maxSigLife', (string) (int) $secDns['maxSigLife']);
                }
                $this->appendSecDnsRecords($frame, $secCreate, (array) $secDns);
            }
            if ($license !== null) {
                $u = $frame->ns($ext, Namespaces::UAREG_EXT, 'uareg:create');
                $frame->ns($u, Namespaces::UAREG_EXT, 'uareg:license', (string) $license);
            }
        }

        return $this->client->request($frame);
    }

    /**
     * @param array{add?:array<string,mixed>,rem?:array<string,mixed>,
     *     chg?:array{registrant?:string,authInfo?:string},restore?:bool,license?:string,
     *     secDNS?:array{add?:array<string,mixed>,rem?:array<string,mixed>,remAll?:bool,maxSigLife?:int}} $options
     */
    public function update(string $name, array $options = []): Response
    {
        $frame = $this->client->frame();
        $update = $frame->ns($frame->verb('update'), Namespaces::DOMAIN, 'domain:update');
        $frame->ns($update, Namespaces::DOMAIN, 'domain:name', $name);

        foreach (['add', 'rem'] as $op) {
            $spec = $options[$op] ?? [];
            if ($spec === []) {
                continue;
            }
            $block = $frame->ns($update, Namespaces::DOMAIN, "domain:{$op}");
            if (!empty($spec['ns'])) {
                $ns = $frame->ns($block, Namespaces::DOMAIN, 'domain:ns');
                foreach ((array) $spec['ns'] as $host) {
                    $frame->ns($ns, Namespaces::DOMAIN, 'domain:hostObj', (string) $host);
                }
            }
            foreach ((array) ($spec['contacts'] ?? []) as $type => $handle) {
                $frame->ns($block, Namespaces::DOMAIN, 'domain:contact', (string) $handle, ['type' => (string) $type]);
            }
            foreach ((array) ($spec['statuses'] ?? []) as $status) {
                $frame->ns($block, Namespaces::DOMAIN, 'domain:status', null, ['s' => (string) $status]);
            }
        }

        $chg = $options['chg'] ?? [];
        if ($chg !== []) {
            $block = $frame->ns($update, Namespaces::DOMAIN, 'domain:chg');
            if (isset($chg['registrant'])) {
                $frame->ns($block, Namespaces::DOMAIN, 'domain:registrant', (string) $chg['registrant']);
            }
            if (isset($chg['authInfo'])) {
                $ai = $frame->ns($block, Namespaces::DOMAIN, 'domain:authInfo');
                $frame->ns($ai, Namespaces::DOMAIN, 'domain:pw', (string) $chg['authInfo']);
            }
        }

        if (!empty($options['restore'])) {
            $rgp = $frame->ns($frame->extension(), Namespaces::RGP, 'rgp:update');
            $frame->ns($rgp, Namespaces::RGP, 'rgp:restore', null, ['op' => 'request']);
        }
        if (isset($options['license'])) {
            $u = $frame->ns($frame->extension(), Namespaces::UAREG_EXT, 'uareg:update');
            $frame->ns($u, Namespaces::UAREG_EXT, 'uareg:license', (string) $options['license']);
        }

        // DNSSEC delta (RFC 5910): rem (specific or all), add, chg maxSigLife.
        $secDns = $options['secDNS'] ?? null;
        if (is_array($secDns)) {
            $secUpdate = $frame->ns($frame->extension(), Namespaces::SECDNS, 'secDNS:update');
            if (!empty($secDns['remAll'])) {
                $rem = $frame->ns($secUpdate, Namespaces::SECDNS, 'secDNS:rem');
                $frame->ns($rem, Namespaces::SECDNS, 'secDNS:all', 'true');
            } elseif (!empty($secDns['rem'])) {
                $rem = $frame->ns($secUpdate, Namespaces::SECDNS, 'secDNS:rem');
                $this->appendSecDnsRecords($frame, $rem, (array) $secDns['rem']);
            }
            if (!empty($secDns['add'])) {
                $add = $frame->ns($secUpdate, Namespaces::SECDNS, 'secDNS:add');
                $this->appendSecDnsRecords($frame, $add, (array) $secDns['add']);
            }
            if (isset($secDns['maxSigLife'])) {
                $chgSec = $frame->ns($secUpdate, Namespaces::SECDNS, 'secDNS:chg');
                $frame->ns($chgSec, Namespaces::SECDNS, 'secDNS:maxSigLife', (string) (int) $secDns['maxSigLife']);
            }
        }

        return $this->client->request($frame);
    }

    public function renew(string $name, string $curExpDate, int $years = 1): Response
    {
        $frame = $this->client->frame();
        $renew = $frame->ns($frame->verb('renew'), Namespaces::DOMAIN, 'domain:renew');
        $frame->ns($renew, Namespaces::DOMAIN, 'domain:name', $name);
        $frame->ns($renew, Namespaces::DOMAIN, 'domain:curExpDate', $curExpDate);
        $frame->ns($renew, Namespaces::DOMAIN, 'domain:period', (string) $years, ['unit' => 'y']);

        return $this->client->request($frame);
    }

    public function delete(string $name): Response
    {
        $frame = $this->client->frame();
        $del = $frame->ns($frame->verb('delete'), Namespaces::DOMAIN, 'domain:delete');
        $frame->ns($del, Namespaces::DOMAIN, 'domain:name', $name);

        return $this->client->request($frame);
    }

    /** Restore a redemption-period domain (rgp:restore op="request"). */
    public function restore(string $name): Response
    {
        return $this->update($name, ['restore' => true]);
    }

    /** @param string $op one of request|approve|reject|cancel|query */
    public function transfer(string $op, string $name, ?string $authInfo = null, ?int $years = null): Response
    {
        $frame = $this->client->frame();
        $transfer = $frame->verb('transfer');
        $transfer->setAttribute('op', $op);
        $d = $frame->ns($transfer, Namespaces::DOMAIN, 'domain:transfer');
        $frame->ns($d, Namespaces::DOMAIN, 'domain:name', $name);
        if ($years !== null) {
            $frame->ns($d, Namespaces::DOMAIN, 'domain:period', (string) $years, ['unit' => 'y']);
        }
        if ($authInfo !== null) {
            $ai = $frame->ns($d, Namespaces::DOMAIN, 'domain:authInfo');
            $frame->ns($ai, Namespaces::DOMAIN, 'domain:pw', $authInfo);
        }

        return $this->client->request($frame);
    }

    /** Append RFC 5910 dsData / keyData records to a secDNS block (create / add / rem). */
    private function appendSecDnsRecords(Frame $frame, \DOMElement $parent, array $spec): void
    {
        foreach ((array) ($spec['dsData'] ?? []) as $ds) {
            $dsData = $frame->ns($parent, Namespaces::SECDNS, 'secDNS:dsData');
            $frame->ns($dsData, Namespaces::SECDNS, 'secDNS:keyTag', (string) (int) ($ds['keyTag'] ?? 0));
            $frame->ns($dsData, Namespaces::SECDNS, 'secDNS:alg', (string) (int) ($ds['alg'] ?? 0));
            $frame->ns($dsData, Namespaces::SECDNS, 'secDNS:digestType', (string) (int) ($ds['digestType'] ?? 0));
            $frame->ns($dsData, Namespaces::SECDNS, 'secDNS:digest', (string) ($ds['digest'] ?? ''));
        }
        foreach ((array) ($spec['keyData'] ?? []) as $key) {
            $keyData = $frame->ns($parent, Namespaces::SECDNS, 'secDNS:keyData');
            $frame->ns($keyData, Namespaces::SECDNS, 'secDNS:flags', (string) (int) ($key['flags'] ?? 257));
            $frame->ns($keyData, Namespaces::SECDNS, 'secDNS:protocol', (string) (int) ($key['protocol'] ?? 3));
            $frame->ns($keyData, Namespaces::SECDNS, 'secDNS:alg', (string) (int) ($key['alg'] ?? 0));
            $frame->ns($keyData, Namespaces::SECDNS, 'secDNS:pubKey', (string) ($key['pubKey'] ?? ''));
        }
    }
}
