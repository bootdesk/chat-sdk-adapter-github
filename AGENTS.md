# adapter-github

GitHub adapter for bootdesk/chat-sdk-core (issues / PR comments). Namespace: `BootDesk\ChatSDK\GitHub`

## files
- `GitHubAdapter` — implements `Adapter` using GitHub REST API
- `GitHubFormatConverter` — GitHub Flavored Markdown ↔ CommonMark AST
- `GitHubCards` — Card model → GitHub Markdown table/lists
- `GitHubWebhookVerifier` — HMAC-SHA256 signature verification

## registration
`src/register.php` registers `'github' => GitHubAdapter::class` via `AdapterRegistry`

## constructor
```php
new GitHubAdapter(
    ClientInterface $httpClient,
    string $webhookSecret,
    ?string $authToken = null,
    string $apiUrl = 'https://api.github.com',
    ?string $appId = null,
    ?string $installationId = null,
    ?Psr17Factory $psrFactory = null,
    ?FileUploadConverter $fileUploadConverter = null,
    ?string $privateKey = null,
);
```

## contracts implemented
- `HandlesSlashCommands` — `parseSlashCommand()` for comment commands starting with `/`
- `SupportsEditMessages` / `SupportsDeleteMessages` — edit/delete via GitHub REST API

## auth modes
- **PAT**: pass `authToken` only (default)
- **Single-tenant App**: pass `authToken` (optional), `appId`, `privateKey`, `installationId`
- **Multi-tenant App**: pass `authToken` (optional), `appId`, `privateKey`; installation IDs resolved from webhooks

## thread ID formats
- PR: `github:{owner}/{repo}:{prNumber}`
- Issue: `github:{owner}/{repo}:issue:{issueNumber}`
- Review comment: `github:{owner}/{repo}:{prNumber}:rc:{commentId}`

## webhook flow
1. `verifyWebhook` — verifies `x-hub-signature-256` header
2. `parseWebhook` — handles `issue_comment` and `pull_request_review_comment` events; ignores `ping`
3. Stores installation IDs per repo for multi-tenant GitHub Apps

## features
- Post/edit/delete issue/PR/review comments
- Add/remove reactions (maps emoji to GitHub reaction content types)
- Fetch comments, issue/PR info, repo info
- No DM support (returns null)
- Streaming: accumulates and posts as single comment
- Supports PAT auth or GitHub App installation tokens

## config (laravel)
```php
'github' => [
    'auth_token' => env('GITHUB_TOKEN'),
    'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
],
```
