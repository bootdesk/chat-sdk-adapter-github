# bootdesk/chat-sdk-adapter-github

GitHub adapter for the laravel-bootdesk multi-platform messaging framework.

## Install

```bash
composer require bootdesk/chat-sdk-adapter-github
```

Requires a PSR-18 HTTP client (`guzzlehttp/guzzle`, `symfony/http-client`, etc.) and a PSR-17 factory (`nyholm/psr7` bundled).

## Configuration

| Variable | Description | Example |
|----------|-------------|---------|
| `auth_token` | GitHub Personal Access Token | `ghp_abc123...` |
| `http_client` | PSR-18 HTTP client instance | `new GuzzleHttp\Client` |
| `webhook_secret` | Webhook Secret | `my-secret...` |

```php
use BootDesk\ChatSDK\GitHub\GitHubAdapter;

$adapter = new GitHubAdapter(
    authToken: env('GITHUB_AUTH_TOKEN'),
    httpClient: new \GuzzleHttp\Client,
    webhookSecret: env('GITHUB_WEBHOOK_SECRET'),
);
```

### Laravel

The `ChatServiceProvider` auto-binds `Psr\Http\Client\ClientInterface` to `GuzzleHttp\Client`. Add to `config/chat.php`:

```php
'github' => [
    'auth_token'     => env('GITHUB_AUTH_TOKEN'),
    'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
],
```

## Quick Example

```php
// Post a comment on a pull request
$adapter->postMessage('github:owner/repo:42', 'LGTM!');

// Post a comment on an issue
$adapter->postMessage('github:owner/repo:issue:15', 'Thanks for reporting.');

// Reply to a review comment thread
$adapter->postMessage('github:owner/repo:42:rc:9876543', 'Fixed in abc123.');
```

## Thread ID Format

| Format | Description |
|--------|-------------|
| `github:{owner}/{repo}:{number}` | Pull request or issue |
| `github:{owner}/{repo}:issue:{number}` | Explicit issue |
| `github:{owner}/{repo}:{prNumber}:rc:{reviewCommentId}` | Review comment thread |

## Webhook

GitHub sends webhook events to your endpoint. Verify requests using HMAC-SHA256 verification via the `x-hub-signature-256` header.

**Handled events:** `issue_comment`, `pull_request_review_comment`

## Feature Matrix

| Feature | Supported |
|---------|-----------|
| Post messages | ✓ |
| Edit messages | ✓ |
| Delete messages | ✓ |
| Reactions | ✓ |
| Typing indicator | ✗ |
| Fetch messages | ✓ |
| Fetch thread info | ✓ |
| Fetch channel info | ✓ |
| Get user | ✓ |
| Open DM | ✗ |
| Stream | ✓ |

## Notes

Supports GitHub App or PAT authentication. Handles PR comments, issue comments, and review comments. Includes emoji-to-reaction mapping for reactions like thumbs up, thumbs down, laugh, etc.

## License

MIT
