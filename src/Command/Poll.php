<?php

declare(strict_types=1);

namespace UARegistry\Sdk\Command;

use UARegistry\Sdk\Client;
use UARegistry\Sdk\Response;

/**
 * The service message queue (RFC 5730 §2.9.2.3). Reached via Client::poll().
 *
 * Use Response::messageId() / messageCount() to read the queue head, then ack() it.
 */
final class Poll
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** Request the next service message (1301 with a message, 1300 when empty). */
    public function request(): Response
    {
        $frame = $this->client->frame();
        $frame->verb('poll')->setAttribute('op', 'req');

        return $this->client->request($frame);
    }

    public function ack(string $messageId): Response
    {
        $frame = $this->client->frame();
        $poll = $frame->verb('poll');
        $poll->setAttribute('op', 'ack');
        $poll->setAttribute('msgID', $messageId);

        return $this->client->request($frame);
    }
}
