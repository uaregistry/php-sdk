# UARegistry EPP SDK (PHP)

A small, dependency-free PHP client for the **UARegistry** EPP service — standard
**RFC 5730–5734** EPP over **TLS on port 700**. It speaks the wire protocol directly
(no framework, no server-side code), so you can drop it into any PHP 7.4+ project (works through 8.x).

- TLS transport with correct RFC 5734 framing (4-byte length prefix).
- Session: `hello` / `login` / `logout`, with the login services taken from the
  server greeting automatically (never rejected for an unsupported service).
- Full object commands: **domain**, **contact**, **host** (check / info / create /
  update / delete / transfer / renew), plus **poll** and **balance**.
- Extensions: **secDNS** (RFC 5910), **RGP restore** (RFC 3915), and the UARegistry
  native **.ua trademark licence**.
- Clean `Response` objects (result code, message, availability map, value getters)
  and typed exceptions.

## Install

```bash
composer require uaregistry/sdk
```

No Composer? Clone the repo and `require __DIR__ . '/sdk/autoload.php';` — it registers
a minimal PSR-4 autoloader for `UARegistry\Sdk\`.

Requires PHP ≥ 7.4 with the `dom`, `libxml` and `openssl` extensions
(and `psr/log` for optional logging — pulled in by Composer).

## Quick start

```php
use UARegistry\Sdk\Client;
use UARegistry\Sdk\Config;
use UARegistry\Sdk\Exception\EppException;

$client = new Client(Config::fromArray([
    'host'     => 'uaregistry.com',
    'clid'     => 'UAR0001',
    'password' => 'your-secret',
    'port'     => 700,                       // default; override only if the endpoint moves
    // 'caFile' => '/path/to/ca.cert.pem',   // for a private-CA / self-signed endpoint
    // 'clientCert' / 'clientKey' / 'clientKeyPassphrase' — only if mutual TLS is required
]));

try {
    $client->connect();   // TLS + read <greeting>
    $client->login();

    $avail = $client->domain()->check(['example.com.ua'])->availability();
    // => ['example.com.ua' => true]

    $info = $client->domain()->info('example.com.ua');
    echo $info->value('exDate');

    $client->logout();
} catch (EppException $e) {
    echo 'EPP error: ' . $e->getMessage();
} finally {
    $client->disconnect();
}
```

## TLS notes

| Scenario | Config |
|---|---|
| Public, browser-trusted cert | defaults (`verifyPeer: true`, `verifyPeerName: true`) |
| Private-CA / self-signed endpoint | set `caFile` to the CA `.pem` |
| Hostname mismatch (dev) | `verifyPeerName: false` |
| Mutual-TLS endpoint (client cert required) | `clientCert` + `clientKey` (+ `clientKeyPassphrase` if the key is encrypted) |

The public endpoint on `uaregistry.com:700` is strict RFC EPP and needs **no client
certificate** — auth is clID + password (over TLS) with an IP allowlist. Your own
certificate/key are only needed if your endpoint requires mutual TLS. Every TLS field
is optional and independent; the port defaults to 700 but is fully configurable.

## Commands

```php
// Session
$client->connect(); $client->login(); $client->logout(); $client->disconnect();
$client->login('new-password');                // rotate the EPP password during login
$client->hello();                              // re-read the greeting / keep-alive

// Domain
$client->domain()->check(['a.com.ua', 'b.com.ua']);
$client->domain()->info('a.com.ua', 'pw');
$client->domain()->create('a.com.ua', [
    'years'       => 1,
    'registrant'  => 'C1',
    'contacts'    => ['admin' => 'C1', 'tech' => 'C2'],
    'nameservers' => ['ns1.x.ua', 'ns2.x.ua'],
    'authInfo'    => 'pw',
    'license'     => 'TM-123',                 // second-level .ua only
    'secDNS'      => ['dsData' => [[
        'keyTag' => 12345, 'alg' => 8, 'digestType' => 2, 'digest' => 'ABCD...'
    ]]],
]);
$client->domain()->update('a.com.ua', [
    'add' => ['ns' => ['ns3.x.ua'], 'statuses' => ['clientHold']],
    'rem' => ['statuses' => ['clientHold']],
    'chg' => ['registrant' => 'C9', 'authInfo' => 'newpw'],
    // DNSSEC (RFC 5910) on an existing domain:
    // 'secDNS' => ['add' => ['dsData' => [[...]]], 'remAll' => true, 'maxSigLife' => 1209600],
]);
$client->domain()->renew('a.com.ua', '2027-01-15', 1);
$client->domain()->restore('a.com.ua');        // RGP restore (op="request")
$client->domain()->delete('a.com.ua');
$client->domain()->transfer('request', 'a.com.ua', 'pw', 1);

// Contact
$client->contact()->check(['c1']);
$client->contact()->info('c1', 'pw');
$client->contact()->create('c1', ['name' => 'ACME', 'city' => 'Kyiv', 'cc' => 'UA',
    'email' => 'a@b.ua', 'authInfo' => 'pw',
    // 'postalInfos' => [['type'=>'int', ...], ['type'=>'loc', ...]],   // int + localized
    // 'disclose'    => ['flag'=>false, 'addr'=>['int'], 'voice'=>true], // RFC 5733 privacy
]);
$client->contact()->update('c1', ['chg' => ['email' => 'new@b.ua',
    // 'postalInfo' => ['name'=>'New Name', 'city'=>'Lviv', 'cc'=>'UA'], // change the address
    // 'disclose'   => ['flag'=>true],
]]);
$client->contact()->delete('c1');
$client->contact()->transfer('request', 'c1', 'pw');

// Host
$client->host()->check(['ns1.x.ua']);
$client->host()->info('ns1.x.ua');
$client->host()->create('ns1.x.ua', ['203.0.113.10', '2001:db8::1']);  // v4/v6 auto-detected
$client->host()->update('ns1.x.ua', ['addAddresses' => ['203.0.113.11']]);
$client->host()->delete('ns1.x.ua');

// Poll & balance
$msg = $client->poll()->request();             // 1301 with a message, 1300 when empty
if ($msg->messageId() !== null) {              // messageCount() = how many remain
    // ... process $msg ...
    $client->poll()->ack($msg->messageId());
}
$b = $client->balance()->balance();            // ['creditLimit'=>…, 'balance'=>…, 'availableCredit'=>…]
```

## Responses

Every command returns a `Response`:

```php
$r->code();           // int EPP result code (1000, 1001, 2303, ...)
$r->isSuccess();      // true for 1xxx
$r->isPending();      // true for 1001 (registry will resolve via a poll message)
$r->message();        // human-readable <msg>
$r->messageLang();    // its language: "en" | "uk" | "ua" | "ru"
$r->availability();   // array<string,bool> for *:check
$r->statuses();       // ['ok'] or ['clientHold', ...] — from the status `s` attribute
$r->value('exDate');  // first element with that local name
$r->values('ns');     // all elements with that local name
$r->balance();        // ['creditLimit'=>…,'balance'=>…,'availableCredit'=>…] or null (balance:info)
$r->prices();         // domain:info hint: ['renewal'=>['value'=>…,'currency'=>'UAH'], ...]
$r->license();        // domain:info: the .ua trademark/licence number, or null
$r->rgpStatus();      // domain:info: ['redemptionPeriod'] etc.
$r->transferStatus(); // transfer/poll trnData: "pending" | "serverApproved" | ... or null
$r->dsRecords();      // domain:info DNSSEC: [['keyTag'=>…,'alg'=>…,'digestType'=>…,'digest'=>…], ...]
$r->keyRecords();     // domain:info DNSSEC keyData: [['flags'=>…,'protocol'=>…,'alg'=>…,'pubKey'=>…], ...]
$r->isSigned();       // bool: does the domain carry any DNSSEC data
$r->messageId();      // poll: id to pass to pollAck();  $r->messageCount() = queue size
$r->errorReasons();   // extra <extValue><reason> text on a failed command
$r->svTRID();         // server transaction id
$r->raw();            // the raw XML
$r->xpath();          // DOMXPath for anything bespoke (prefixes e/domain/contact/host/secDNS/rgp/uareg/balance)
```

## Error handling

By default any EPP error code (≥ 2000) throws `CommandException` (carrying `->eppCode`
and `->response`). Login failures throw `AuthenticationException`; transport problems
throw `ConnectionException`. All extend `EppException`.

```php
use UARegistry\Sdk\Exception\CommandException;
use UARegistry\Sdk\ResultCode;

try {
    $client->domain()->create('taken.com.ua', [...]);
} catch (CommandException $e) {
    if ($e->eppCode === ResultCode::OBJECT_EXISTS) { /* 2302 */ }
    // $e->response->errorReasons() may carry extra detail
}
```

`ResultCode` has named constants for every code (`SUCCESS`, `SUCCESS_PENDING`,
`OBJECT_EXISTS`, `OBJECT_DOES_NOT_EXIST`, `AUTHENTICATION_ERROR`, `BILLING_FAILURE`, …).

Prefer to branch on codes yourself? Turn throwing off:

```php
$client->throwOnFailure(false);
$resp = $client->domain()->info('maybe.com.ua');
if ($resp->code() === ResultCode::OBJECT_DOES_NOT_EXIST) { /* not found */ }
```

## Logging (PSR-3)

Pass any [PSR-3](https://www.php-fig.org/psr/psr-3/) logger (Monolog, Laravel, etc.).
Every request/response frame is logged at `debug` and each result at `info` / `warning`,
with **passwords and authInfo masked** so secrets never reach your logs.

```php
$log = new Monolog\Logger('epp');
$log->pushHandler(new Monolog\Handler\StreamHandler('php://stderr', Monolog\Level::Debug));

$client = new Client($config, null, $log);
// or later: $client->setLogger($log);
```

## Custom frames

Anything the high-level API doesn't cover can be built with `Frame` and sent raw:

```php
use UARegistry\Sdk\Frame;
use UARegistry\Sdk\Namespaces;

$frame = Frame::command('my-trid-1');
$check = $frame->ns($frame->verb('check'), Namespaces::DOMAIN, 'domain:check');
$frame->ns($check, Namespaces::DOMAIN, 'domain:name', 'x.com.ua');
$resp = $client->request($frame);   // or $client->request($rawXmlString);
```

## Testing

A no-dependency offline self-test (frame building + response parsing, no server):

```bash
php tests/offline_test.php      # or: composer test
```

## License

MIT — see [LICENSE](LICENSE).
