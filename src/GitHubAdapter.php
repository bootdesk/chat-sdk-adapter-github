<?php

namespace BootDesk\ChatSDK\GitHub;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Support\NullFileUploadConverter;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class GitHubAdapter implements Adapter
{
    protected ?string $botUserId = null;

    protected GitHubFormatConverter $formatConverter;

    protected GitHubWebhookVerifier $webhookVerifier;

    /** @var array<string, string> owner/repo → installation ID */
    protected array $installationIds = [];

    protected FileUploadConverter $fileUploadConverter;

    protected const EMOJI_MAP = [
        '👍' => '+1',
        '+1' => '+1',
        'thumbs_up' => '+1',
        '👎' => '-1',
        '-1' => '-1',
        'thumbs_down' => '-1',
        '😄' => 'laugh',
        'laugh' => 'laugh',
        'smile' => 'laugh',
        '😕' => 'confused',
        'confused' => 'confused',
        'thinking' => 'confused',
        '❤️' => 'heart',
        'heart' => 'heart',
        'love_eyes' => 'heart',
        '🎉' => 'hooray',
        'hooray' => 'hooray',
        'party' => 'hooray',
        'confetti' => 'hooray',
        '🚀' => 'rocket',
        'rocket' => 'rocket',
        '👀' => 'eyes',
        'eyes' => 'eyes',
    ];

    public function __construct(
        protected readonly string $authToken,
        protected readonly ClientInterface $httpClient,
        string $webhookSecret,
        protected readonly string $apiUrl = 'https://api.github.com',
        protected readonly ?string $appId = null,
        protected readonly ?string $installationId = null,
        protected readonly ?Psr17Factory $psrFactory = null,
        ?FileUploadConverter $fileUploadConverter = null,
    ) {
        $this->formatConverter = new GitHubFormatConverter;
        $this->webhookVerifier = new GitHubWebhookVerifier($webhookSecret);
        $this->fileUploadConverter = $fileUploadConverter ?? new NullFileUploadConverter;
    }

    public function getName(): string
    {
        return 'github';
    }

    public function getBotUserId(): ?string
    {
        return $this->botUserId;
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        $body = (string) $request->getBody();
        $signature = $request->getHeaderLine('x-hub-signature-256');

        if (! $this->webhookVerifier->verify($body, $signature)) {
            return $this->jsonResponse(403, 'Invalid signature');
        }

        return null;
    }

    public function parseWebhook(ServerRequestInterface $request): Message
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if ($payload === null) {
            throw new AdapterException('Invalid GitHub webhook payload');
        }

        // Detect bot user ID from sender
        if (isset($payload['sender']['id']) && isset($payload['sender']['type']) && $payload['sender']['type'] === 'Bot') {
            $this->botUserId = (string) $payload['sender']['id'];
        }

        // Store installation ID for multi-tenant
        if (isset($payload['installation']['id'])) {
            $repo = $payload['repository']['full_name'] ?? null;
            if ($repo !== null) {
                $this->installationIds[$repo] = (string) $payload['installation']['id'];
            }
        }

        $event = $request->getHeaderLine('x-github-event');

        if ($event === 'ping') {
            throw new AdapterException('ping');
        }

        if ($event === 'issue_comment') {
            return $this->parseIssueComment($payload, $body);
        }

        if ($event === 'pull_request_review_comment') {
            return $this->parseReviewComment($payload, $body);
        }

        throw new AdapterException("Unsupported GitHub event: {$event}");
    }

    public function encodeThreadId(mixed $platformData): string
    {
        $owner = $platformData['owner'] ?? '';
        $repo = $platformData['repo'] ?? '';
        $type = $platformData['type'] ?? 'pr';

        if ($type === 'issue') {
            return "github:{$owner}/{$repo}:issue:{$platformData['number']}";
        }

        if ($type === 'review_comment') {
            return "github:{$owner}/{$repo}:{$platformData['prNumber']}:rc:{$platformData['commentId']}";
        }

        return "github:{$owner}/{$repo}:{$platformData['number']}";
    }

    public function decodeThreadId(string $threadId): mixed
    {
        // Review comment: github:owner/repo:prNumber:rc:commentId
        if (preg_match('/^github:([^\/]+)\/([^:]+):(\d+):rc:(\d+)$/', $threadId, $m)) {
            return [
                'owner' => $m[1],
                'repo' => $m[2],
                'prNumber' => (int) $m[3],
                'type' => 'review_comment',
                'commentId' => (int) $m[4],
            ];
        }

        // Issue: github:owner/repo:issue:number
        if (preg_match('/^github:([^\/]+)\/([^:]+):issue:(\d+)$/', $threadId, $m)) {
            return [
                'owner' => $m[1],
                'repo' => $m[2],
                'type' => 'issue',
                'number' => (int) $m[3],
            ];
        }

        // PR: github:owner/repo:number
        if (preg_match('/^github:([^\/]+)\/([^:]+):(\d+)$/', $threadId, $m)) {
            return [
                'owner' => $m[1],
                'repo' => $m[2],
                'type' => 'pr',
                'number' => (int) $m[3],
            ];
        }

        throw new AdapterException("Invalid GitHub thread ID: {$threadId}");
    }

    public function channelIdFromThreadId(string $threadId): string
    {
        $decoded = $this->decodeThreadId($threadId);

        return "github:{$decoded['owner']}/{$decoded['repo']}";
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        // Convert files to attachments via the registered converter
        if ($message->files !== []) {
            $converted = [];
            foreach ($message->files as $file) {
                $converted[] = $this->fileUploadConverter->upload($file, $this);
            }
            $message = new PostableMessage(
                content: $message->content,
                replyToMessageId: $message->replyToMessageId,
                attachments: array_merge($message->attachments, $converted),
            );
        }

        $decoded = $this->decodeThreadId($threadId);
        $body = $this->renderBody($message);
        $body = $this->appendAttachments($body, $message);

        if ($decoded['type'] === 'review_comment') {
            $response = $this->apiCall(
                "repos/{$decoded['owner']}/{$decoded['repo']}/pulls/{$decoded['prNumber']}/comments",
                ['body' => $body, 'in_reply_to' => $decoded['commentId']],
            );
        } else {
            $response = $this->apiCall(
                "repos/{$decoded['owner']}/{$decoded['repo']}/issues/{$decoded['number']}/comments",
                ['body' => $body],
            );
        }

        return new SentMessage(
            id: (string) ($response['id'] ?? ''),
            threadId: $threadId,
            timestamp: (string) ($response['created_at'] ?? ''),
        );
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);
        $body = $this->renderBody($message);
        $body = $this->appendAttachments($body, $message);

        if ($decoded['type'] === 'review_comment') {
            $response = $this->apiCall(
                "repos/{$decoded['owner']}/{$decoded['repo']}/pulls/comments/{$messageId}",
                ['body' => $body],
                'PATCH',
            );
        } else {
            $response = $this->apiCall(
                "repos/{$decoded['owner']}/{$decoded['repo']}/issues/comments/{$messageId}",
                ['body' => $body],
                'PATCH',
            );
        }

        return new SentMessage(
            id: (string) ($response['id'] ?? $messageId),
            threadId: $threadId,
            timestamp: (string) ($response['updated_at'] ?? ''),
        );
    }

    public function deleteMessage(string $threadId, string $messageId): void
    {
        $decoded = $this->decodeThreadId($threadId);

        if ($decoded['type'] === 'review_comment') {
            $this->apiCall(
                "repos/{$decoded['owner']}/{$decoded['repo']}/pulls/comments/{$messageId}",
                [],
                'DELETE',
            );
        } else {
            $this->apiCall(
                "repos/{$decoded['owner']}/{$decoded['repo']}/issues/comments/{$messageId}",
                [],
                'DELETE',
            );
        }
    }

    public function addReaction(string $threadId, string $messageId, string $emoji): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $content = self::EMOJI_MAP[$emoji] ?? $emoji;

        $this->apiCall(
            "repos/{$decoded['owner']}/{$decoded['repo']}/issues/comments/{$messageId}/reactions",
            ['content' => $content],
        );
    }

    public function removeReaction(string $threadId, string $messageId, string $emoji): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $content = self::EMOJI_MAP[$emoji] ?? $emoji;

        // GitHub requires the reaction ID, not the content, for deletion.
        // Fetch reactions to find the one matching this content.
        $reactions = $this->apiCall(
            "repos/{$decoded['owner']}/{$decoded['repo']}/issues/comments/{$messageId}/reactions",
            [],
            'GET',
        );

        foreach ($reactions as $reaction) {
            if (($reaction['content'] ?? '') === $content) {
                $reactionId = $reaction['id'];
                $this->apiCall(
                    "repos/{$decoded['owner']}/{$decoded['repo']}/issues/comments/{$messageId}/reactions/{$reactionId}",
                    [],
                    'DELETE',
                );

                return;
            }
        }
    }

    public function startTyping(string $threadId): void
    {
        // GitHub has no typing indicator
    }

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        $decoded = $this->decodeThreadId($threadId);

        if ($decoded['type'] === 'review_comment') {
            $data = $this->apiCall(
                "repos/{$decoded['owner']}/{$decoded['repo']}/pulls/{$decoded['prNumber']}/comments",
                [],
                'GET',
                ['per_page' => 100],
            );
        } else {
            $data = $this->apiCall(
                "repos/{$decoded['owner']}/{$decoded['repo']}/issues/{$decoded['number']}/comments",
                [],
                'GET',
                ['per_page' => 100],
            );
        }

        $messages = [];
        foreach ($data as $comment) {
            $messages[] = new Message(
                id: (string) $comment['id'],
                threadId: $threadId,
                author: new Author(
                    id: (string) ($comment['user']['id'] ?? ''),
                    isBot: ($comment['user']['type'] ?? '') === 'Bot',
                ),
                text: $comment['body'] ?? '',
                isDM: false,
                raw: json_encode($comment),
            );
        }

        return new FetchResult(messages: $messages);
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        $decoded = $this->decodeThreadId($threadId);

        if ($decoded['type'] === 'issue') {
            $data = $this->apiCall("repos/{$decoded['owner']}/{$decoded['repo']}/issues/{$decoded['number']}", [], 'GET');
        } else {
            $data = $this->apiCall("repos/{$decoded['owner']}/{$decoded['repo']}/pulls/{$decoded['number']}", [], 'GET');
        }

        return new ThreadInfo(
            id: $threadId,
            channelId: $this->channelIdFromThreadId($threadId),
            messageCount: (int) ($data['comments'] ?? 0),
        );
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        if (! preg_match('/^github:([^\/]+)\/(.+)$/', $channelId, $m)) {
            return null;
        }

        $data = $this->apiCall("repos/{$m[1]}/{$m[2]}", [], 'GET');

        return new ChannelInfo(
            id: $channelId,
            name: $data['full_name'] ?? $channelId,
            isPrivate: ($data['private'] ?? false),
        );
    }

    public function getUser(string $userId): ?UserInfo
    {
        $data = $this->apiCall("user/{$userId}", [], 'GET');

        return new UserInfo(
            id: (string) ($data['id'] ?? $userId),
            name: $data['login'] ?? $userId,
        );
    }

    public function openDM(string $userId): ?string
    {
        return null;
    }

    public function getFormatConverter(): ?FormatConverter
    {
        return $this->formatConverter;
    }

    public function initialize(Chat $chat): void
    {
        try {
            $me = $this->apiCall('user', [], 'GET');
            $this->botUserId = (string) ($me['id'] ?? null);
        } catch (AdapterException) {
            // Bot identity unavailable
        }
    }

    public function disconnect(): void
    {
        // No persistent connection
    }

    public function createResponse(): ?ResponseInterface
    {
        return null;
    }

    public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
    {
        $fullText = '';
        foreach ($textStream as $chunk) {
            $fullText .= $chunk;
        }

        if ($fullText === '') {
            return null;
        }

        return $this->postMessage($threadId, PostableMessage::text($fullText));
    }

    protected function renderBody(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return GitHubCards::toGitHubMarkdown($message->content);
        }

        return $this->formatConverter->renderPostable($message);
    }

    protected function appendAttachments(string $body, PostableMessage $message): string
    {
        $lines = [];

        foreach ($message->attachments as $att) {
            $name = $att->name ?? 'Attachment';
            $lines[] = $att->url !== null
                ? "[{$name}]({$att->url})"
                : $name;
        }

        if ($lines === []) {
            return $body;
        }

        return $body !== '' ? $body."\n\n".implode("\n", $lines) : implode("\n", $lines);
    }

    protected function getAuthToken(string $owner, string $repo): string
    {
        if ($this->appId !== null) {
            $key = "{$owner}/{$repo}";
            $installId = $this->installationIds[$key] ?? $this->installationId;

            if ($installId !== null) {
                return $this->authToken; // In production, exchange for installation token
            }
        }

        return $this->authToken;
    }

    protected function apiCall(string $endpoint, array $params, string $method = 'POST', array $queryParams = []): array
    {
        $factory = $this->psrFactory ?? new Psr17Factory;
        $url = "{$this->apiUrl}/{$endpoint}";

        if ($queryParams !== []) {
            $url .= '?'.http_build_query($queryParams);
        }

        // Determine auth token
        $owner = $repo = '';
        if (preg_match('#^repos/([^/]+)/([^/]+)/#', $endpoint, $m)) {
            $owner = $m[1];
            $repo = $m[2];
        }
        $token = ($owner !== '') ? $this->getAuthToken($owner, $repo) : $this->authToken;

        if ($method === 'GET' || $method === 'DELETE') {
            $request = $factory->createRequest($method, $url);
        } else {
            $body = json_encode(array_filter($params, fn ($v): bool => $v !== null));
            $request = $factory->createRequest($method, $url)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream($body));
        }

        $request = $request
            ->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/vnd.github+json')
            ->withHeader('User-Agent', 'bootdesk-github-adapter');

        $psrResponse = $this->httpClient->sendRequest($request);
        $responseBody = (string) $psrResponse->getBody();
        $statusCode = $psrResponse->getStatusCode();

        // Handle 204 No Content
        if ($statusCode === 204 || $responseBody === '') {
            return [];
        }

        $data = json_decode($responseBody, true);

        if ($data === null) {
            return [];
        }

        if (isset($data['message']) && ! isset($data['id']) && ! isset($data[0])) {
            $errorMsg = $data['message'];

            if (in_array($statusCode, [401, 403], true)) {
                throw new AuthenticationException("GitHub API authentication error ({$endpoint}): {$errorMsg}");
            }

            throw new AdapterException("GitHub API error ({$endpoint}): {$errorMsg}");
        }

        return $data;
    }

    protected function parseIssueComment(array $payload, string $rawBody): Message
    {
        $comment = $payload['comment'] ?? [];
        $issue = $payload['issue'] ?? [];
        $repository = $payload['repository'] ?? [];
        $owner = $repository['owner']['login'] ?? '';
        $repo = $repository['name'] ?? '';
        $isPullRequest = isset($issue['pull_request']);

        if ($isPullRequest) {
            $prNumber = $issue['number'];
            $threadId = $this->encodeThreadId(['owner' => $owner, 'repo' => $repo, 'number' => $prNumber]);
        } else {
            $issueNumber = $issue['number'];
            $threadId = $this->encodeThreadId(['owner' => $owner, 'repo' => $repo, 'type' => 'issue', 'number' => $issueNumber]);
        }

        return new Message(
            id: (string) ($comment['id'] ?? ''),
            threadId: $threadId,
            author: new Author(
                id: (string) ($comment['user']['id'] ?? ''),
                isBot: ($comment['user']['type'] ?? '') === 'Bot',
            ),
            text: $comment['body'] ?? '',
            isDM: false,
            raw: $rawBody,
        );
    }

    protected function parseReviewComment(array $payload, string $rawBody): Message
    {
        $comment = $payload['comment'] ?? [];
        $pullRequest = $payload['pull_request'] ?? [];
        $repository = $payload['repository'] ?? [];
        $owner = $repository['owner']['login'] ?? '';
        $repo = $repository['name'] ?? '';
        $prNumber = $pullRequest['number'] ?? 0;
        $commentId = $comment['id'] ?? 0;

        $threadId = $this->encodeThreadId([
            'owner' => $owner,
            'repo' => $repo,
            'prNumber' => $prNumber,
            'type' => 'review_comment',
            'commentId' => $commentId,
        ]);

        return new Message(
            id: (string) $commentId,
            threadId: $threadId,
            author: new Author(
                id: (string) ($comment['user']['id'] ?? ''),
                isBot: ($comment['user']['type'] ?? '') === 'Bot',
            ),
            text: $comment['body'] ?? '',
            isDM: false,
            raw: $rawBody,
        );
    }

    protected function jsonResponse(int $status, string $message): ResponseInterface
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        return $factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode(['error' => $message])));
    }
}
