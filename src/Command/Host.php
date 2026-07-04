<?php

declare(strict_types=1);

namespace UARegistry\Sdk\Command;

use UARegistry\Sdk\Client;
use UARegistry\Sdk\Namespaces;
use UARegistry\Sdk\Response;

/**
 * Host (nameserver) object commands (RFC 5732). Reached via Client::host().
 */
final class Host
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
        $check = $frame->ns($frame->verb('check'), Namespaces::HOST, 'host:check');
        foreach ($names as $name) {
            $frame->ns($check, Namespaces::HOST, 'host:name', $name);
        }

        return $this->client->request($frame);
    }

    public function info(string $name): Response
    {
        $frame = $this->client->frame();
        $info = $frame->ns($frame->verb('info'), Namespaces::HOST, 'host:info');
        $frame->ns($info, Namespaces::HOST, 'host:name', $name);

        return $this->client->request($frame);
    }

    /** @param string[] $addresses IPv4 or IPv6 literals; the version is auto-detected. */
    public function create(string $name, array $addresses = []): Response
    {
        $frame = $this->client->frame();
        $create = $frame->ns($frame->verb('create'), Namespaces::HOST, 'host:create');
        $frame->ns($create, Namespaces::HOST, 'host:name', $name);
        foreach ($addresses as $ip) {
            $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'v6' : 'v4';
            $frame->ns($create, Namespaces::HOST, 'host:addr', (string) $ip, ['ip' => $version]);
        }

        return $this->client->request($frame);
    }

    /**
     * @param array{addAddresses?:string[],remAddresses?:string[],addStatuses?:string[],
     *     remStatuses?:string[],newName?:string} $options
     */
    public function update(string $name, array $options = []): Response
    {
        $frame = $this->client->frame();
        $update = $frame->ns($frame->verb('update'), Namespaces::HOST, 'host:update');
        $frame->ns($update, Namespaces::HOST, 'host:name', $name);

        foreach (['add' => ['addAddresses', 'addStatuses'], 'rem' => ['remAddresses', 'remStatuses']] as $op => [$addrKey, $statusKey]) {
            $addrs = (array) ($options[$addrKey] ?? []);
            $statuses = (array) ($options[$statusKey] ?? []);
            if ($addrs === [] && $statuses === []) {
                continue;
            }
            $block = $frame->ns($update, Namespaces::HOST, "host:{$op}");
            foreach ($addrs as $ip) {
                $version = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'v6' : 'v4';
                $frame->ns($block, Namespaces::HOST, 'host:addr', (string) $ip, ['ip' => $version]);
            }
            foreach ($statuses as $status) {
                $frame->ns($block, Namespaces::HOST, 'host:status', null, ['s' => (string) $status]);
            }
        }
        if (!empty($options['newName'])) {
            $chg = $frame->ns($update, Namespaces::HOST, 'host:chg');
            $frame->ns($chg, Namespaces::HOST, 'host:name', (string) $options['newName']);
        }

        return $this->client->request($frame);
    }

    public function delete(string $name, bool $force = false): Response
    {
        $frame = $this->client->frame();
        $del = $frame->ns($frame->verb('delete'), Namespaces::HOST, 'host:delete');
        $frame->ns($del, Namespaces::HOST, 'host:name', $name);
        if ($force) {
            // UARegistry native: detach the host from every domain before deleting it.
            $u = $frame->ns($frame->extension(), Namespaces::UAREG_EXT, 'uareg:delete');
            $frame->ns($u, Namespaces::UAREG_EXT, 'uareg:deleteNS', null, ['confirm' => 'yes']);
        }

        return $this->client->request($frame);
    }
}
