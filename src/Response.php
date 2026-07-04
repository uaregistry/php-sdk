<?php

declare(strict_types=1);

namespace UARegistry\Sdk;

use UARegistry\Sdk\Exception\ConnectionException;

/**
 * A parsed EPP response (or greeting). Wraps the raw XML with convenience accessors:
 * the result code/message, transaction ids, the availability map for *:check, and
 * generic value/values getters plus the underlying DOM/XPath for anything bespoke.
 */
final class Response
{
    private string $raw;
    private \DOMDocument $dom;
    private \DOMXPath $xpath;

    private function __construct(string $raw, \DOMDocument $dom, \DOMXPath $xpath)
    {
        $this->raw = $raw;
        $this->dom = $dom;
        $this->xpath = $xpath;
    }

    public static function fromXml(string $xml): self
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);
        if ($ok === false) {
            throw new ConnectionException('Server returned malformed XML');
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('e', Namespaces::EPP);
        $xpath->registerNamespace('domain', Namespaces::DOMAIN);
        $xpath->registerNamespace('contact', Namespaces::CONTACT);
        $xpath->registerNamespace('host', Namespaces::HOST);
        $xpath->registerNamespace('secDNS', Namespaces::SECDNS);
        $xpath->registerNamespace('rgp', Namespaces::RGP);
        $xpath->registerNamespace('uareg', Namespaces::UAREG_EXT);
        $xpath->registerNamespace('balance', Namespaces::UAREG_BALANCE);

        return new self($xml, $dom, $xpath);
    }

    /** The EPP result code (e.g. 1000, 1001, 2200), or 0 for a greeting/codeless frame. */
    public function code(): int
    {
        $node = $this->xpath->query('//e:result/@code')->item(0);

        return $node !== null ? (int) $node->nodeValue : 0;
    }

    public function message(): ?string
    {
        $node = $this->xpath->query('//e:result/e:msg')->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }

    /** The language of the result <msg> ("en", "uk", "ua" or "ru"), or null. */
    public function messageLang(): ?string
    {
        $node = $this->xpath->query('//e:result/e:msg/@lang')->item(0);

        return $node !== null ? $node->nodeValue : null;
    }

    /** A 1xxx code means success (1000 done, 1001 action pending). */
    public function isSuccess(): bool
    {
        $code = $this->code();

        return $code >= 1000 && $code < 2000;
    }

    public function isPending(): bool
    {
        return $this->code() === 1001;
    }

    public function isGreeting(): bool
    {
        return $this->xpath->query('//e:greeting')->length > 0;
    }

    public function clTRID(): ?string
    {
        $node = $this->xpath->query('//e:trID/e:clTRID')->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }

    public function svTRID(): ?string
    {
        $node = $this->xpath->query('//e:trID/e:svTRID')->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }

    /**
     * Availability map for a *:check response: name/id => is-available.
     *
     * @return array<string, bool>
     */
    public function availability(): array
    {
        $out = [];
        /** @var \DOMElement $element */
        foreach ($this->xpath->query('//*[@avail]') as $element) {
            $out[trim($element->textContent)] = in_array($element->getAttribute('avail'), ['1', 'true'], true);
        }

        return $out;
    }

    /** Poll only: the queued message id to pass to pollAck(), or null. */
    public function messageId(): ?string
    {
        $node = $this->xpath->query('//e:msgQ/@id')->item(0);

        return $node !== null ? $node->nodeValue : null;
    }

    /** Poll only: how many messages remain in the queue. */
    public function messageCount(): int
    {
        $node = $this->xpath->query('//e:msgQ/@count')->item(0);

        return $node !== null ? (int) $node->nodeValue : 0;
    }

    /**
     * Object status values from the `s` attribute (e.g. ['ok'] or ['clientHold', ...]).
     *
     * @return string[]
     */
    public function statuses(): array
    {
        $out = [];
        foreach ($this->xpath->query("//*[local-name()='status']/@s") as $attr) {
            $out[] = $attr->nodeValue;
        }

        return $out;
    }

    /**
     * Account figures from a balance:info response (creditLimit / balance / availableCredit,
     * strings in your account currency), or null when this is not a balance response.
     *
     * @return array{creditLimit:string,balance:string,availableCredit:string}|null
     */
    public function balance(): ?array
    {
        $limit = $this->value('creditLimit');
        $avail = $this->value('availableCredit');
        if ($limit === null && $avail === null) {
            return null;
        }

        return [
            'creditLimit'     => (string) $limit,
            'balance'         => (string) $this->value('balance'),
            'availableCredit' => (string) $avail,
        ];
    }

    /**
     * Renewal/restore price hints from a domain:info response (the uaregistry priceData
     * extension), keyed by operation, e.g. ['renewal' => ['value' => '180.00', 'currency' => 'UAH']].
     * Empty when the response carries no price data.
     *
     * @return array<string, array{value:string, currency:string}>
     */
    public function prices(): array
    {
        $out = [];
        /** @var \DOMElement $node */
        foreach ($this->xpath->query("//*[local-name()='price']") as $node) {
            $op = $node->getAttribute('operation');
            if ($op === '') {
                continue;
            }
            $out[$op] = ['value' => trim($node->textContent), 'currency' => $node->getAttribute('currency')];
        }

        return $out;
    }

    /** The .ua trademark/licence number from a domain:info response, or null. */
    public function license(): ?string
    {
        return $this->value('license');
    }

    /**
     * RGP status values from a domain:info response (e.g. ['redemptionPeriod']); empty if none.
     *
     * @return string[]
     */
    public function rgpStatus(): array
    {
        $out = [];
        foreach ($this->xpath->query("//*[local-name()='rgpStatus']/@s") as $attr) {
            $out[] = $attr->nodeValue;
        }

        return $out;
    }

    /** The transfer status from a transfer response or poll trnData (e.g. "pending"), or null. */
    public function transferStatus(): ?string
    {
        return $this->value('trStatus');
    }

    /**
     * DNSSEC DS records from a domain:info response (secDNS:dsData), each as
     * ['keyTag'=>int,'alg'=>int,'digestType'=>int,'digest'=>string]. Empty when unsigned.
     *
     * @return array<int, array{keyTag:int,alg:int,digestType:int,digest:string}>
     */
    public function dsRecords(): array
    {
        $out = [];
        /** @var \DOMElement $ds */
        foreach ($this->xpath->query('//secDNS:dsData') as $ds) {
            $out[] = [
                'keyTag'     => (int) $this->childText($ds, 'keyTag'),
                'alg'        => (int) $this->childText($ds, 'alg'),
                'digestType' => (int) $this->childText($ds, 'digestType'),
                'digest'     => $this->childText($ds, 'digest'),
            ];
        }

        return $out;
    }

    /**
     * DNSSEC key records from a domain:info response (top-level secDNS:keyData), each as
     * ['flags'=>int,'protocol'=>int,'alg'=>int,'pubKey'=>string]. Empty when none.
     *
     * @return array<int, array{flags:int,protocol:int,alg:int,pubKey:string}>
     */
    public function keyRecords(): array
    {
        $out = [];
        /** @var \DOMElement $kd */
        foreach ($this->xpath->query('//secDNS:infData/secDNS:keyData') as $kd) {
            $out[] = [
                'flags'    => (int) $this->childText($kd, 'flags'),
                'protocol' => (int) $this->childText($kd, 'protocol'),
                'alg'      => (int) $this->childText($kd, 'alg'),
                'pubKey'   => $this->childText($kd, 'pubKey'),
            ];
        }

        return $out;
    }

    /** True when a domain:info response carries DNSSEC data (any DS or key records). */
    public function isSigned(): bool
    {
        return $this->dsRecords() !== [] || $this->keyRecords() !== [];
    }

    /**
     * Extra diagnostic text from a failed command's <extValue><reason> elements.
     *
     * @return string[]
     */
    public function errorReasons(): array
    {
        return $this->collect('//e:extValue/e:reason');
    }

    /** Greeting only: the object services the server advertises. @return string[] */
    public function serviceObjUris(): array
    {
        return $this->collect('//e:svcMenu/e:objURI');
    }

    /** Greeting only: the extension services the server advertises. @return string[] */
    public function serviceExtUris(): array
    {
        return $this->collect('//e:svcMenu/e:svcExtension/e:extURI');
    }

    /** First element anywhere with this local name (namespace-agnostic), trimmed. */
    public function value(string $localName): ?string
    {
        $node = $this->xpath->query(sprintf("(//*[local-name()='%s'])[1]", $localName))->item(0);

        return $node !== null ? trim($node->textContent) : null;
    }

    /** Every element with this local name, trimmed. @return string[] */
    public function values(string $localName): array
    {
        return $this->collect(sprintf("//*[local-name()='%s']", $localName));
    }

    /** The <resData> element of the response, if present (for custom parsing). */
    public function resData(): ?\DOMElement
    {
        $node = $this->xpath->query('//e:resData')->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function dom(): \DOMDocument
    {
        return $this->dom;
    }

    public function xpath(): \DOMXPath
    {
        return $this->xpath;
    }

    /** @return string[] */
    private function collect(string $query): array
    {
        $out = [];
        foreach ($this->xpath->query($query) as $node) {
            $out[] = trim($node->textContent);
        }

        return $out;
    }

    /** Text of a direct child element by local name, or '' if absent. */
    private function childText(\DOMElement $parent, string $localName): string
    {
        $node = $this->xpath->query("./*[local-name()='" . $localName . "']", $parent)->item(0);

        return $node !== null ? trim($node->textContent) : '';
    }
}
