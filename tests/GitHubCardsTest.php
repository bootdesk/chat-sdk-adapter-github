<?php

namespace BootDesk\ChatSDK\GitHub\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\GitHub\GitHubCards;
use PHPUnit\Framework\TestCase;

class GitHubCardsTest extends TestCase
{
    public function test_card_to_markdown_with_header(): void
    {
        $card = Card::make()->header('Deploy Ready')->section(fn ($s) => $s->text('Build passed'));

        $md = GitHubCards::toGitHubMarkdown($card);

        $this->assertStringContainsString('**Deploy Ready**', $md);
        $this->assertStringContainsString('Build passed', $md);
    }

    public function test_card_to_markdown_with_fields(): void
    {
        $card = Card::make()->section(fn ($s) => $s->fields(['Status' => 'passing', 'Branch' => 'main']));

        $md = GitHubCards::toGitHubMarkdown($card);

        $this->assertStringContainsString('**Status:** passing', $md);
        $this->assertStringContainsString('**Branch:** main', $md);
    }

    public function test_card_to_markdown_with_buttons(): void
    {
        $card = Card::make()
            ->header('Actions')
            ->actions([Button::primary('Deploy', 'deploy'), Button::secondary('Cancel', 'cancel')]);

        $md = GitHubCards::toGitHubMarkdown($card);

        $this->assertStringContainsString('Deploy', $md);
        $this->assertStringContainsString('Cancel', $md);
        $this->assertStringContainsString(' • ', $md);
    }

    public function test_card_to_markdown_with_image(): void
    {
        $card = Card::make()->image('https://example.com/chart.png', 'Chart');

        $md = GitHubCards::toGitHubMarkdown($card);

        $this->assertStringContainsString('![Chart](https://example.com/chart.png)', $md);
    }

    public function test_card_to_plain_text(): void
    {
        $card = Card::make()->header('Title')->section(fn ($s) => $s->text('Body text'));

        $text = GitHubCards::toPlainText($card);

        $this->assertStringContainsString('Title', $text);
        $this->assertStringContainsString('Body text', $text);
    }

    public function test_escape_markdown(): void
    {
        $this->assertSame('hello\\*world\\_', GitHubCards::escapeMarkdown('hello*world_'));
        $this->assertSame('\\[link\\]', GitHubCards::escapeMarkdown('[link]'));
    }

    public function test_empty_card(): void
    {
        $md = GitHubCards::toGitHubMarkdown(Card::make());
        $this->assertSame('', $md);
    }
}
