<?php

namespace BootDesk\ChatSDK\GitHub;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use BootDesk\ChatSDK\Core\PostableMessage;
use League\CommonMark\Node\Block\Document;

class GitHubFormatConverter extends BaseFormatConverter
{
    public function toAst(string $platformText): Document
    {
        return $this->parseMarkdown($platformText);
    }

    public function fromAst(Document $ast): string
    {
        return $this->renderMarkdown($ast);
    }

    public function renderPostable(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return GitHubCards::toGitHubMarkdown($message->content);
        }

        return (string) $message->content;
    }
}
