<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\GitHub;

class GitHubWebhookVerifier
{
    public function __construct(
        private readonly string $webhookSecret,
    ) {}

    public function verify(string $body, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $parts = explode('=', $signature, 2);
        if ($parts[0] !== 'sha256' || ! isset($parts[1])) {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $this->webhookSecret);

        return hash_equals($expected, $parts[1]);
    }
}
