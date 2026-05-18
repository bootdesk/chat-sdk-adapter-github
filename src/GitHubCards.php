<?php

namespace BootDesk\ChatSDK\GitHub;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Cards\Divider;
use BootDesk\ChatSDK\Core\Cards\Image;
use BootDesk\ChatSDK\Core\Cards\Link;
use BootDesk\ChatSDK\Core\Cards\LinkButton;
use BootDesk\ChatSDK\Core\Cards\Table;
use BootDesk\ChatSDK\Core\Cards\Text;
use BootDesk\ChatSDK\Core\Cards\TextStyle;

class GitHubCards
{
    public static function toGitHubMarkdown(Card $card): string
    {
        $lines = [];

        if ($card->getImageUrl() !== null) {
            $lines[] = '![]('.$card->getImageUrl().')';
            $lines[] = '';
        }

        if ($card->getHeader() !== null) {
            $lines[] = '**'.self::escapeMarkdown($card->getHeader()).'**';
            $lines[] = '';
        }

        foreach ($card->getChildren() as $child) {
            if ($child instanceof Text) {
                $content = self::escapeMarkdown($child->content);
                $lines[] = match ($child->style) {
                    TextStyle::Bold => "**{$content}**",
                    TextStyle::Muted => "*{$content}*",
                    default => $content,
                };
            } elseif ($child instanceof Divider) {
                $lines[] = '---';
            } elseif ($child instanceof Image) {
                $alt = $child->alt !== '' ? self::escapeMarkdown($child->alt) : 'image';
                $lines[] = "![{$alt}](".$child->url.')';
            } elseif ($child instanceof Link) {
                $lines[] = '['.self::escapeMarkdown($child->label).']('.self::escapeMarkdown($child->url).')';
            } elseif ($child instanceof Table) {
                $lines[] = self::renderTableToMarkdown($child);
            } elseif ($child instanceof LinkButton) {
                $lines[] = '['.self::escapeMarkdown($child->label).']('.self::escapeMarkdown($child->url).')';
            }
        }

        foreach ($card->getSections() as $section) {
            if ($section->getText() !== null) {
                $lines[] = $section->getText();
            }

            foreach ($section->getFields() as $label => $value) {
                $lines[] = '**'.self::escapeMarkdown((string) $label).':** '.self::escapeMarkdown((string) $value);
            }
        }

        $buttons = $card->getButtons();
        if ($buttons !== []) {
            $lines[] = '';
            $buttonParts = array_map(fn (Button $b): string => self::renderButton($b), $buttons);
            $lines[] = implode(' • ', $buttonParts);
        }

        $linkButtons = $card->getLinkButtons();
        if ($linkButtons !== []) {
            $lines[] = '';
            $linkButtonParts = array_map(fn ($b): string => '['.self::escapeMarkdown($b->label).']('.self::escapeMarkdown($b->url).')', $linkButtons);
            $lines[] = implode(' • ', $linkButtonParts);
        }

        return implode("\n", $lines);
    }

    public static function toPlainText(Card $card): string
    {
        return $card->getFallbackText();
    }

    private static function renderButton(Button $button): string
    {
        if ($button->actionHref !== null) {
            return '['.self::escapeMarkdown($button->label).']('.self::escapeMarkdown($button->actionHref).')';
        }

        return self::escapeMarkdown($button->label);
    }

    public static function escapeMarkdown(string $text): string
    {
        return preg_replace('/([\\\\*_\[\]])/', '\\\\$1', $text) ?? $text;
    }

    private static function renderTableToMarkdown(Table $table): string
    {
        $rows = [];
        $rows[] = '| '.implode(' | ', array_map([self::class, 'escapeMarkdown'], $table->headers)).' |';
        $separators = [];
        foreach (array_keys($table->headers) as $i) {
            $align = $table->align[$i] ?? null;
            $separators[] = match ($align?->value) {
                'center' => ':---:',
                'right' => '---:',
                default => '---',
            };
        }
        $rows[] = '| '.implode(' | ', $separators).' |';
        foreach ($table->rows as $row) {
            $rows[] = '| '.implode(' | ', array_map([self::class, 'escapeMarkdown'], $row)).' |';
        }

        return implode("\n", $rows);
    }
}
