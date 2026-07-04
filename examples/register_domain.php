<?php

declare(strict_types=1);

/**
 * Create a contact, then register a domain that references it (with nameservers and,
 * for a second-level .ua, a trademark licence). Adjust the values before running.
 *
 *   UAREG_HOST=... UAREG_CLID=... UAREG_PW=... php examples/register_domain.php
 */

$autoload = is_file(__DIR__ . '/../vendor/autoload.php')
    ? __DIR__ . '/../vendor/autoload.php'
    : __DIR__ . '/../autoload.php';
require $autoload;

use UARegistry\Sdk\Client;
use UARegistry\Sdk\Config;
use UARegistry\Sdk\Exception\CommandException;
use UARegistry\Sdk\Exception\EppException;

$config = Config::fromArray([
    'host'     => getenv('UAREG_HOST') ?: 'uaregistry.com',
    'clid'     => getenv('UAREG_CLID') ?: 'UAR0001',
    'password' => getenv('UAREG_PW') ?: '',
    'port'     => (int) (getenv('UAREG_PORT') ?: 700),
    'caFile'   => getenv('UAREG_CA') ?: null,
]);

$client = Client::connectAndLogin($config);

try {
    // 1. A registrant contact (skip if it already exists — 2302 means "taken").
    try {
        $client->contact()->create('acme-01', [
            'name'     => 'ACME LLC',
            'org'      => 'ACME LLC',
            'street'   => ['1 Khreschatyk St'],
            'city'     => 'Kyiv',
            'pc'       => '01001',
            'cc'       => 'UA',
            'voice'    => '+380.441234567',
            'email'    => 'admin@acme.example',
            'authInfo' => 'C0nt@ct-Pw',
        ]);
        echo "contact acme-01 created\n";
    } catch (CommandException $e) {
        echo "contact acme-01: EPP {$e->eppCode} (already exists?) — continuing\n";
    }

    // 2. The domain. 'license' is only needed for a second-level .ua (trademark match).
    $result = $client->domain()->create('your-brand.com.ua', [
        'years'       => 1,
        'registrant'  => 'acme-01',
        'contacts'    => ['admin' => 'acme-01', 'tech' => 'acme-01'],
        'nameservers' => ['ns1.acme.example', 'ns2.acme.example'],
        'authInfo'    => 'D0main-Pw',
        // 'license'  => 'TM-2026-000123',  // <-- uncomment for a brand.ua second-level
    ]);
    echo 'domain create: EPP ' . $result->code()
        . ($result->isPending() ? ' (pending registry approval)' : ' (registered)') . "\n";
    echo 'expires: ' . ($result->value('exDate') ?? '-') . "\n";

    $client->logout();
} catch (EppException $e) {
    // STDERR exists only on the CLI; under php-fpm write to the response instead.
    fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'wb'), 'EPP error: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $client->disconnect();
}
