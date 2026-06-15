<?php

namespace BootDesk\ChatSDK\GitHub\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\Exceptions\UnsupportedOperationException;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\GitHub\GitHubAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class GitHubAdapterTest extends TestCase
{
    private GitHubAdapter $adapter;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;

        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $uri = (string) $request->getUri();
                $method = $request->getMethod();

                // POST issues/comments → create comment
                if ($method === 'POST' && preg_match('#/issues/\d+/comments$#', $uri)) {
                    return $factory->createResponse(201)->withBody(
                        $factory->createStream(json_encode([
                            'id' => 42,
                            'body' => 'test',
                            'created_at' => '2024-01-01T00:00:00Z',
                        ]))
                    );
                }

                // POST pulls/comments → create review comment reply
                if ($method === 'POST' && preg_match('#/pulls/\d+/comments$#', $uri)) {
                    return $factory->createResponse(201)->withBody(
                        $factory->createStream(json_encode([
                            'id' => 99,
                            'body' => 'reply',
                            'created_at' => '2024-01-01T00:00:00Z',
                        ]))
                    );
                }

                // PATCH issues/comments/{id} → edit issue comment
                if ($method === 'PATCH' && preg_match('#/issues/comments/\d+$#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'id' => 42,
                            'updated_at' => '2024-01-02T00:00:00Z',
                        ]))
                    );
                }

                // PATCH pulls/comments/{id} → edit review comment
                if ($method === 'PATCH' && preg_match('#/pulls/comments/\d+$#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'id' => 99,
                            'updated_at' => '2024-01-02T00:00:00Z',
                        ]))
                    );
                }

                // DELETE issues/comments/{id}
                if ($method === 'DELETE' && preg_match('#/issues/comments/\d+$#', $uri)) {
                    return $factory->createResponse(204);
                }

                // DELETE pulls/comments/{id}
                if ($method === 'DELETE' && preg_match('#/pulls/comments/\d+$#', $uri)) {
                    return $factory->createResponse(204);
                }

                // POST reactions
                if ($method === 'POST' && preg_match('#/comments/\d+/reactions$#', $uri)) {
                    return $factory->createResponse(201)->withBody(
                        $factory->createStream(json_encode(['id' => 55, 'content' => '+1']))
                    );
                }

                // GET reactions
                if ($method === 'GET' && preg_match('#/comments/\d+/reactions$#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            ['id' => 1, 'content' => '+1'],
                            ['id' => 2, 'content' => 'heart'],
                        ]))
                    );
                }

                // DELETE reactions/{id}
                if ($method === 'DELETE' && preg_match('#/comments/\d+/reactions/\d+$#', $uri)) {
                    return $factory->createResponse(204);
                }

                // GET user/{id}
                if ($method === 'GET' && preg_match('#/user/\d+$#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'id' => 12345,
                            'login' => 'octocat',
                        ]))
                    );
                }

                // GET me (user endpoint without ID)
                if ($method === 'GET' && preg_match('#/user$#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'id' => 99999,
                            'login' => 'bot[bot]',
                        ]))
                    );
                }

                // GET repos/{owner}/{repo}/issues/{number}
                if ($method === 'GET' && preg_match('#/repos/[^/]+/[^/]+/issues/\d+$#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'id' => 1,
                            'number' => 7,
                            'comments' => 3,
                        ]))
                    );
                }

                // GET repos/{owner}/{repo}/pulls/{number}
                if ($method === 'GET' && preg_match('#/repos/[^/]+/[^/]+/pulls/\d+$#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'id' => 2,
                            'number' => 5,
                            'comments' => 4,
                        ]))
                    );
                }

                // GET repos/{owner}/{repo}/issues/{number}/comments
                if ($method === 'GET' && preg_match('#/issues/\d+/comments#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            ['id' => 10, 'body' => 'comment 1', 'user' => ['id' => 100, 'type' => 'User']],
                            ['id' => 11, 'body' => 'comment 2', 'user' => ['id' => 99999, 'type' => 'Bot']],
                        ]))
                    );
                }

                // GET repos/{owner}/{repo}/pulls/{number}/comments
                if ($method === 'GET' && preg_match('#/pulls/\d+/comments#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            ['id' => 20, 'body' => 'review comment', 'user' => ['id' => 100, 'type' => 'User']],
                        ]))
                    );
                }

                // GET repos/{owner}/{repo}
                if ($method === 'GET' && preg_match('#/repos/[^/]+/[^/]+$#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'id' => 1,
                            'full_name' => 'acme/my-repo',
                            'private' => false,
                        ]))
                    );
                }

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode(['id' => 'fallback']))
                );
            }
        };

        $this->adapter = new GitHubAdapter(
            httpClient: $mockClient,
            webhookSecret: 'test_webhook_secret',
            authToken: 'ghp_test123',
            psrFactory: $this->factory,
        );
    }

    // --- Construction ---

    public function test_get_name(): void
    {
        $this->assertSame('github', $this->adapter->getName());
    }

    public function test_initialize_sets_bot_user_id(): void
    {
        $chat = $this->createMock(Chat::class);
        $this->adapter->initialize($chat);
        $this->assertSame('99999', $this->adapter->getBotUserId());
    }

    // --- Thread IDs ---

    public function test_encode_pr_thread(): void
    {
        $id = $this->adapter->encodeThreadId(['owner' => 'acme', 'repo' => 'app', 'number' => 42]);
        $this->assertSame('github:acme/app:42', $id);
    }

    public function test_encode_issue_thread(): void
    {
        $id = $this->adapter->encodeThreadId(['owner' => 'acme', 'repo' => 'app', 'type' => 'issue', 'number' => 7]);
        $this->assertSame('github:acme/app:issue:7', $id);
    }

    public function test_encode_review_comment_thread(): void
    {
        $id = $this->adapter->encodeThreadId([
            'owner' => 'acme',
            'repo' => 'app',
            'prNumber' => 5,
            'type' => 'review_comment',
            'commentId' => 123,
        ]);
        $this->assertSame('github:acme/app:5:rc:123', $id);
    }

    public function test_decode_pr_thread(): void
    {
        $decoded = $this->adapter->decodeThreadId('github:acme/app:42');
        $this->assertSame('acme', $decoded['owner']);
        $this->assertSame('app', $decoded['repo']);
        $this->assertSame('pr', $decoded['type']);
        $this->assertSame(42, $decoded['number']);
    }

    public function test_decode_issue_thread(): void
    {
        $decoded = $this->adapter->decodeThreadId('github:acme/app:issue:7');
        $this->assertSame('issue', $decoded['type']);
        $this->assertSame(7, $decoded['number']);
    }

    public function test_decode_review_comment_thread(): void
    {
        $decoded = $this->adapter->decodeThreadId('github:acme/app:5:rc:123');
        $this->assertSame('review_comment', $decoded['type']);
        $this->assertSame(5, $decoded['prNumber']);
        $this->assertSame(123, $decoded['commentId']);
    }

    public function test_decode_invalid_thread(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->decodeThreadId('not-github');
    }

    public function test_channel_id_from_thread(): void
    {
        $this->assertSame('github:acme/app', $this->adapter->channelIdFromThreadId('github:acme/app:42'));
        $this->assertSame('github:acme/app', $this->adapter->channelIdFromThreadId('github:acme/app:issue:7'));
    }

    // --- Webhook verification ---

    public function test_verify_valid_signature(): void
    {
        $body = '{"action":"opened"}';
        $hash = hash_hmac('sha256', $body, 'test_webhook_secret');

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-hub-signature-256', "sha256={$hash}")
            ->withBody($this->factory->createStream($body));

        $response = $this->adapter->verifyWebhook($request);
        $this->assertNull($response);
    }

    public function test_verify_invalid_signature(): void
    {
        $body = '{"action":"opened"}';
        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-hub-signature-256', 'sha256=badhash')
            ->withBody($this->factory->createStream($body));

        $response = $this->adapter->verifyWebhook($request);
        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Parse webhook ---

    public function test_parse_issue_comment_on_pr(): void
    {
        $body = json_encode([
            'action' => 'created',
            'issue' => [
                'number' => 42,
                'pull_request' => ['url' => 'https://api.github.com/repos/acme/app/pulls/42'],
            ],
            'comment' => [
                'id' => 1001,
                'body' => 'LGTM',
                'user' => ['id' => 12345, 'login' => 'dev', 'type' => 'User'],
            ],
            'repository' => [
                'full_name' => 'acme/app',
                'owner' => ['login' => 'acme'],
                'name' => 'app',
            ],
            'sender' => ['id' => 12345, 'type' => 'User'],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-github-event', 'issue_comment')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('1001', $message->id);
        $this->assertSame('github:acme/app:42', $message->threadId);
        $this->assertSame('12345', $message->author->id);
        $this->assertSame('LGTM', $message->text);
        $this->assertFalse($message->isDM);
    }

    public function test_parse_issue_comment_on_issue(): void
    {
        $body = json_encode([
            'action' => 'created',
            'issue' => ['number' => 7],
            'comment' => [
                'id' => 2001,
                'body' => 'Bug confirmed',
                'user' => ['id' => 111, 'login' => 'tester', 'type' => 'User'],
            ],
            'repository' => [
                'full_name' => 'acme/app',
                'owner' => ['login' => 'acme'],
                'name' => 'app',
            ],
            'sender' => ['id' => 111, 'type' => 'User'],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-github-event', 'issue_comment')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('github:acme/app:issue:7', $message->threadId);
        $this->assertSame('Bug confirmed', $message->text);
    }

    public function test_parse_review_comment(): void
    {
        $body = json_encode([
            'action' => 'created',
            'pull_request' => ['number' => 5],
            'comment' => [
                'id' => 3001,
                'body' => 'Nit: typo here',
                'user' => ['id' => 222, 'login' => 'reviewer', 'type' => 'User'],
            ],
            'repository' => [
                'full_name' => 'acme/app',
                'owner' => ['login' => 'acme'],
                'name' => 'app',
            ],
            'sender' => ['id' => 222, 'type' => 'User'],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-github-event', 'pull_request_review_comment')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('3001', $message->id);
        $this->assertSame('github:acme/app:5:rc:3001', $message->threadId);
        $this->assertSame('Nit: typo here', $message->text);
    }

    public function test_parse_ping_throws(): void
    {
        $body = json_encode(['zen' => 'Keep it simple']);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-github-event', 'ping')
            ->withBody($this->factory->createStream($body));

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('ping');
        $this->adapter->parseWebhook($request);
    }

    public function test_parse_unsupported_event(): void
    {
        $body = json_encode(['action' => 'opened']);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-github-event', 'push')
            ->withBody($this->factory->createStream($body));

        $this->expectException(UnsupportedOperationException::class);
        $this->adapter->parseWebhook($request);
    }

    public function test_parse_stores_installation_id(): void
    {
        $body = json_encode([
            'action' => 'created',
            'issue' => ['number' => 1, 'pull_request' => ['url' => 'x']],
            'comment' => ['id' => 1, 'body' => 'test', 'user' => ['id' => 1, 'type' => 'User']],
            'repository' => [
                'full_name' => 'acme/app',
                'owner' => ['login' => 'acme'],
                'name' => 'app',
            ],
            'installation' => ['id' => 98765],
            'sender' => ['id' => 1, 'type' => 'User'],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-github-event', 'issue_comment')
            ->withBody($this->factory->createStream($body));

        $this->adapter->parseWebhook($request);
        // Installation ID is stored internally; verified by no exception
        $this->assertTrue(true);
    }

    public function test_parse_detects_bot_sender(): void
    {
        $body = json_encode([
            'action' => 'created',
            'issue' => ['number' => 1, 'pull_request' => ['url' => 'x']],
            'comment' => ['id' => 1, 'body' => 'test', 'user' => ['id' => 1, 'type' => 'User']],
            'repository' => [
                'full_name' => 'acme/app',
                'owner' => ['login' => 'acme'],
                'name' => 'app',
            ],
            'sender' => ['id' => 88888, 'type' => 'Bot'],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-github-event', 'issue_comment')
            ->withBody($this->factory->createStream($body));

        $this->adapter->parseWebhook($request);
        $this->assertSame('88888', $this->adapter->getBotUserId());
    }

    // --- Message operations ---

    public function test_post_message_pr_comment(): void
    {
        $sent = $this->adapter->postMessage('github:acme/app:42', PostableMessage::text('Nice work!'));

        $this->assertSame('42', $sent->id);
        $this->assertSame('github:acme/app:42', $sent->threadId);
    }

    public function test_post_message_issue_comment(): void
    {
        $sent = $this->adapter->postMessage('github:acme/app:issue:7', PostableMessage::text('Confirmed'));

        $this->assertSame('42', $sent->id);
    }

    public function test_post_message_review_comment_reply(): void
    {
        $sent = $this->adapter->postMessage('github:acme/app:5:rc:3001', PostableMessage::text('Fixed'));

        $this->assertSame('99', $sent->id);
    }

    public function test_post_message_with_card(): void
    {
        $card = Card::make()
            ->header('Deploy Ready')
            ->section(fn ($s) => $s->text('Build passed'))
            ->actions([Button::primary('Deploy', 'deploy')]);

        $sent = $this->adapter->postMessage('github:acme/app:42', PostableMessage::card($card));

        $this->assertSame('42', $sent->id);
    }

    public function test_edit_message_issue_comment(): void
    {
        $sent = $this->adapter->editMessage('github:acme/app:42', '42', PostableMessage::text('Updated'));

        $this->assertSame('42', $sent->id);
    }

    public function test_edit_message_review_comment(): void
    {
        $sent = $this->adapter->editMessage('github:acme/app:5:rc:3001', '99', PostableMessage::text('Updated'));

        $this->assertSame('99', $sent->id);
    }

    public function test_delete_message_issue_comment(): void
    {
        $this->adapter->deleteMessage('github:acme/app:42', '42');
        $this->assertTrue(true);
    }

    public function test_delete_message_review_comment(): void
    {
        $this->adapter->deleteMessage('github:acme/app:5:rc:3001', '99');
        $this->assertTrue(true);
    }

    // --- Reactions ---

    public function test_add_reaction(): void
    {
        $this->adapter->addReaction('github:acme/app:42', '42', '👍');
        $this->assertTrue(true);
    }

    public function test_remove_reaction(): void
    {
        $this->adapter->removeReaction('github:acme/app:42', '42', '+1');
        $this->assertTrue(true);
    }

    // --- Stream ---

    public function test_stream_collects_and_posts(): void
    {
        $sent = $this->adapter->stream('github:acme/app:42', ['Hello ', 'World']);
        $this->assertNotNull($sent);
        $this->assertSame('42', $sent->id);
    }

    public function test_stream_empty_returns_null(): void
    {
        $this->assertNull($this->adapter->stream('github:acme/app:42', []));
    }

    // --- Fetch operations ---

    public function test_fetch_messages_issue(): void
    {
        $result = $this->adapter->fetchMessages('github:acme/app:issue:7');
        $this->assertCount(2, $result->messages);
        $this->assertSame('10', $result->messages[0]->id);
    }

    public function test_fetch_messages_pr(): void
    {
        $result = $this->adapter->fetchMessages('github:acme/app:42');
        $this->assertCount(2, $result->messages);
    }

    public function test_fetch_thread_issue(): void
    {
        $info = $this->adapter->fetchThread('github:acme/app:issue:7');
        $this->assertSame('github:acme/app:issue:7', $info->id);
        $this->assertSame(3, $info->messageCount);
    }

    public function test_fetch_thread_pr(): void
    {
        $info = $this->adapter->fetchThread('github:acme/app:42');
        $this->assertSame(4, $info->messageCount);
    }

    public function test_fetch_channel_info(): void
    {
        $info = $this->adapter->fetchChannelInfo('github:acme/my-repo');
        $this->assertSame('github:acme/my-repo', $info->id);
        $this->assertSame('acme/my-repo', $info->name);
        $this->assertFalse($info->isPrivate);
    }

    public function test_fetch_channel_info_invalid(): void
    {
        $this->assertNull($this->adapter->fetchChannelInfo('invalid'));
    }

    // --- User ---

    public function test_get_user(): void
    {
        $user = $this->adapter->getUser('12345');
        $this->assertSame('12345', $user->id);
        $this->assertSame('octocat', $user->name);
    }

    // --- Misc ---

    public function test_open_dm_returns_null(): void
    {
        $this->assertNull($this->adapter->openDM('12345'));
    }

    public function test_start_typing_is_noop(): void
    {
        $this->adapter->startTyping('github:acme/app:42');
        $this->assertTrue(true);
    }

    public function test_get_format_converter(): void
    {
        $this->assertNotNull($this->adapter->getFormatConverter());
    }

    public function test_disconnect_is_noop(): void
    {
        $this->adapter->disconnect();
        $this->assertTrue(true);
    }

    public function test_create_response_returns_null(): void
    {
        $this->assertNull($this->adapter->createResponse());
    }

    public function test_api_call_throws_authentication_exception_on_auth_error(): void
    {
        $factory = new Psr17Factory;
        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $f = new Psr17Factory;

                return $f->createResponse(401)->withBody(
                    $f->createStream(json_encode(['message' => 'Bad credentials']))
                );
            }
        };

        $adapter = new GitHubAdapter(
            httpClient: $mockClient,
            webhookSecret: 'secret',
            authToken: 'ghp_bad',
            psrFactory: $factory,
        );

        $this->expectException(AuthenticationException::class);
        $adapter->postMessage('github:acme/app:42', PostableMessage::text('test'));
    }
}
