<?php

declare(strict_types=1);

namespace UARegistry\Sdk\Command;

use UARegistry\Sdk\Client;
use UARegistry\Sdk\Frame;
use UARegistry\Sdk\Namespaces;
use UARegistry\Sdk\Response;

/**
 * Contact object commands (RFC 5733), including disclosure preferences and localized
 * (int/loc) postal-info blocks. Reached via Client::contact().
 */
final class Contact
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** @param string[] $ids */
    public function check(array $ids): Response
    {
        $frame = $this->client->frame();
        $check = $frame->ns($frame->verb('check'), Namespaces::CONTACT, 'contact:check');
        foreach ($ids as $id) {
            $frame->ns($check, Namespaces::CONTACT, 'contact:id', $id);
        }

        return $this->client->request($frame);
    }

    public function info(string $id, ?string $authInfo = null): Response
    {
        $frame = $this->client->frame();
        $info = $frame->ns($frame->verb('info'), Namespaces::CONTACT, 'contact:info');
        $frame->ns($info, Namespaces::CONTACT, 'contact:id', $id);
        if ($authInfo !== null) {
            $ai = $frame->ns($info, Namespaces::CONTACT, 'contact:authInfo');
            $frame->ns($ai, Namespaces::CONTACT, 'contact:pw', $authInfo);
        }

        return $this->client->request($frame);
    }

    /**
     * Give a single postal block via the flat name/org/street/city/sp/pc/cc/type keys,
     * or several (e.g. int + loc) via 'postalInfos'. 'disclose' sets RFC 5733 disclosure.
     *
     * @param array{name?:string,org?:string,street?:string[],city?:string,sp?:string,
     *     pc?:string,cc?:string,type?:string,postalInfos?:array<int,array<string,mixed>>,
     *     voice?:string,fax?:string,email?:string,authInfo?:string,
     *     disclose?:array<string,mixed>} $options
     */
    public function create(string $id, array $options = []): Response
    {
        $frame = $this->client->frame();
        $c = $frame->ns($frame->verb('create'), Namespaces::CONTACT, 'contact:create');
        $frame->ns($c, Namespaces::CONTACT, 'contact:id', $id);

        $postalInfos = $options['postalInfos'] ?? null;
        if (is_array($postalInfos) && $postalInfos !== []) {
            foreach ($postalInfos as $pi) {
                $this->appendPostalInfo($frame, $c, (array) $pi);
            }
        } else {
            $this->appendPostalInfo($frame, $c, $options); // one block from the flat keys
        }

        if (!empty($options['voice'])) {
            $frame->ns($c, Namespaces::CONTACT, 'contact:voice', (string) $options['voice']);
        }
        if (!empty($options['fax'])) {
            $frame->ns($c, Namespaces::CONTACT, 'contact:fax', (string) $options['fax']);
        }
        $email = (string) ($options['email'] ?? '');
        if ($email === '') {
            // RFC 5733 requires a contact email (emailType minLength 1). Fail fast client-side with a
            // clear message instead of sending an empty <email/> the server rejects with an opaque 2005.
            throw new \InvalidArgumentException("contact:create requires a non-empty 'email'");
        }
        $frame->ns($c, Namespaces::CONTACT, 'contact:email', $email);
        $ai = $frame->ns($c, Namespaces::CONTACT, 'contact:authInfo');
        $frame->ns($ai, Namespaces::CONTACT, 'contact:pw', (string) ($options['authInfo'] ?? ''));
        if (!empty($options['disclose'])) {
            $this->appendDisclose($frame, $c, (array) $options['disclose']);
        }

        return $this->client->request($frame);
    }

    /**
     * @param array{chg?:array{postalInfo?:array<string,mixed>,postalInfos?:array<int,array<string,mixed>>,
     *     voice?:string,fax?:string,email?:string,authInfo?:string,disclose?:array<string,mixed>},
     *     addStatuses?:string[],remStatuses?:string[]} $options
     */
    public function update(string $id, array $options = []): Response
    {
        $frame = $this->client->frame();
        $update = $frame->ns($frame->verb('update'), Namespaces::CONTACT, 'contact:update');
        $frame->ns($update, Namespaces::CONTACT, 'contact:id', $id);
        // contact:updateType allows a SINGLE add/rem block (each holding up to 7 statuses), so the
        // wrapper is created once and every status appended into it — emitting one <contact:add> per
        // status is rejected by the server XSD (add maxOccurs=1).
        $addStatuses = (array) ($options['addStatuses'] ?? []);
        if ($addStatuses !== []) {
            $add = $frame->ns($update, Namespaces::CONTACT, 'contact:add');
            foreach ($addStatuses as $status) {
                $frame->ns($add, Namespaces::CONTACT, 'contact:status', null, ['s' => (string) $status]);
            }
        }
        $remStatuses = (array) ($options['remStatuses'] ?? []);
        if ($remStatuses !== []) {
            $rem = $frame->ns($update, Namespaces::CONTACT, 'contact:rem');
            foreach ($remStatuses as $status) {
                $frame->ns($rem, Namespaces::CONTACT, 'contact:status', null, ['s' => (string) $status]);
            }
        }
        $chg = $options['chg'] ?? [];
        if ($chg !== []) {
            $block = $frame->ns($update, Namespaces::CONTACT, 'contact:chg');
            // RFC 5733 chg order: postalInfo*, voice?, fax?, email?, authInfo?, disclose?
            $pis = $chg['postalInfos'] ?? (isset($chg['postalInfo']) ? [$chg['postalInfo']] : null);
            if (is_array($pis)) {
                foreach ($pis as $pi) {
                    $this->appendPostalInfo($frame, $block, (array) $pi);
                }
            }
            if (isset($chg['voice'])) {
                $frame->ns($block, Namespaces::CONTACT, 'contact:voice', (string) $chg['voice']);
            }
            if (isset($chg['fax'])) {
                $frame->ns($block, Namespaces::CONTACT, 'contact:fax', (string) $chg['fax']);
            }
            if (isset($chg['email'])) {
                $frame->ns($block, Namespaces::CONTACT, 'contact:email', (string) $chg['email']);
            }
            if (isset($chg['authInfo'])) {
                $ai = $frame->ns($block, Namespaces::CONTACT, 'contact:authInfo');
                $frame->ns($ai, Namespaces::CONTACT, 'contact:pw', (string) $chg['authInfo']);
            }
            if (!empty($chg['disclose'])) {
                $this->appendDisclose($frame, $block, (array) $chg['disclose']);
            }
        }

        return $this->client->request($frame);
    }

    public function delete(string $id): Response
    {
        $frame = $this->client->frame();
        $del = $frame->ns($frame->verb('delete'), Namespaces::CONTACT, 'contact:delete');
        $frame->ns($del, Namespaces::CONTACT, 'contact:id', $id);

        return $this->client->request($frame);
    }

    public function transfer(string $op, string $id, ?string $authInfo = null): Response
    {
        $frame = $this->client->frame();
        $transfer = $frame->verb('transfer');
        $transfer->setAttribute('op', $op);
        $c = $frame->ns($transfer, Namespaces::CONTACT, 'contact:transfer');
        $frame->ns($c, Namespaces::CONTACT, 'contact:id', $id);
        if ($authInfo !== null) {
            $ai = $frame->ns($c, Namespaces::CONTACT, 'contact:authInfo');
            $frame->ns($ai, Namespaces::CONTACT, 'contact:pw', $authInfo);
        }

        return $this->client->request($frame);
    }

    /** Build one <contact:postalInfo> block from name/org/street/city/sp/pc/cc/type. */
    private function appendPostalInfo(Frame $frame, \DOMElement $parent, array $pi): void
    {
        $block = $frame->ns($parent, Namespaces::CONTACT, 'contact:postalInfo', null, ['type' => (string) ($pi['type'] ?? 'int')]);
        $frame->ns($block, Namespaces::CONTACT, 'contact:name', (string) ($pi['name'] ?? ''));
        if (!empty($pi['org'])) {
            $frame->ns($block, Namespaces::CONTACT, 'contact:org', (string) $pi['org']);
        }
        $addr = $frame->ns($block, Namespaces::CONTACT, 'contact:addr');
        foreach ((array) ($pi['street'] ?? []) as $line) {
            $frame->ns($addr, Namespaces::CONTACT, 'contact:street', (string) $line);
        }
        $frame->ns($addr, Namespaces::CONTACT, 'contact:city', (string) ($pi['city'] ?? ''));
        if (!empty($pi['sp'])) {
            $frame->ns($addr, Namespaces::CONTACT, 'contact:sp', (string) $pi['sp']);
        }
        if (!empty($pi['pc'])) {
            $frame->ns($addr, Namespaces::CONTACT, 'contact:pc', (string) $pi['pc']);
        }
        $frame->ns($addr, Namespaces::CONTACT, 'contact:cc', (string) ($pi['cc'] ?? ''));
    }

    /**
     * Build a <contact:disclose flag="0|1"> block. name/org/addr take a type (int|loc),
     * passed as a list; voice/fax/email are bare flags toggled by a truthy value.
     *
     * Example: ['flag' => false, 'addr' => ['int'], 'voice' => true, 'email' => true]
     */
    private function appendDisclose(Frame $frame, \DOMElement $parent, array $disclose): void
    {
        $flag = !empty($disclose['flag']) ? '1' : '0';
        $disc = $frame->ns($parent, Namespaces::CONTACT, 'contact:disclose', null, ['flag' => $flag]);
        foreach (['name', 'org', 'addr'] as $field) {
            if (!isset($disclose[$field])) {
                continue;
            }
            foreach ((array) $disclose[$field] as $type) {
                $frame->ns($disc, Namespaces::CONTACT, "contact:{$field}", null, ['type' => (string) $type]);
            }
        }
        foreach (['voice', 'fax', 'email'] as $field) {
            if (!empty($disclose[$field])) {
                $frame->ns($disc, Namespaces::CONTACT, "contact:{$field}");
            }
        }
    }
}
