<?php

namespace BootDesk\ChatSDK\GitHub\Tests;

use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\GitHub\GitHubFormatConverter;
use PHPUnit\Framework\TestCase;

class GitHubFormatConverterTest extends TestCase
{
    private GitHubFormatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new GitHubFormatConverter;
    }

    public function test_to_ast(): void
    {
        $ast = $this->converter->toAst('Hello **world**');
        $this->assertNotNull($ast);
    }

    public function test_from_ast(): void
    {
        $ast = $this->converter->toAst('Hello');
        $result = $this->converter->fromAst($ast);
        $this->assertStringContainsString('Hello', $result);
    }

    public function test_render_postable_card(): void
    {
        $card = Card::make()->header('Test');
        $message = PostableMessage::card($card);
        $result = $this->converter->renderPostable($message);
        $this->assertStringContainsString('Test', $result);
    }

    public function test_render_postable_text(): void
    {
        $message = PostableMessage::text('Plain');
        $result = $this->converter->renderPostable($message);
        $this->assertSame('Plain', $result);
    }
}
