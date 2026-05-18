<?php

namespace BootDesk\ChatSDK\GitHub\Tests;

use BootDesk\ChatSDK\GitHub\GitHubWebhookVerifier;
use PHPUnit\Framework\TestCase;

class GitHubWebhookVerifierTest extends TestCase
{
    private GitHubWebhookVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new GitHubWebhookVerifier('my_webhook_secret');
    }

    public function test_valid_signature(): void
    {
        $body = '{"action":"opened"}';
        $hash = hash_hmac('sha256', $body, 'my_webhook_secret');

        $this->assertTrue($this->verifier->verify($body, "sha256={$hash}"));
    }

    public function test_invalid_signature(): void
    {
        $body = '{"action":"opened"}';

        $this->assertFalse($this->verifier->verify($body, 'sha256=badhash'));
    }

    public function test_empty_signature(): void
    {
        $this->assertFalse($this->verifier->verify('body', ''));
    }

    public function test_wrong_algorithm(): void
    {
        $this->assertFalse($this->verifier->verify('body', 'sha1=something'));
    }

    public function test_wrong_secret(): void
    {
        $body = '{"action":"opened"}';
        $hash = hash_hmac('sha256', $body, 'wrong_secret');

        $this->assertFalse($this->verifier->verify($body, "sha256={$hash}"));
    }
}
