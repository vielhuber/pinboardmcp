# 📌pinboardmcp📌

minimal PHP library and MCP server for the complete [Pinboard v1 API](https://pinboard.in/api/), built with [simplemcp](https://github.com/vielhuber/simplemcp). v2 is still a draft and is not used.

## installation

PHP 8.5+, curl, json and mbstring:

```sh
composer config repositories.pinboardmcp vcs https://github.com/vielhuber/pinboardmcp
composer require vielhuber/pinboardmcp:dev-main
```

## configuration

copy `.env.example` to `.env` (keep it private) or provide the API configuration through the process environment:

```ini
PINBOARD_API_TOKEN=username:token
PINBOARD_CACHE_DIR=/private/pinboard-cache
MCP_TOKEN=
```

get the API token from [settings → password](https://pinboard.in/settings/password/). no account password or OAuth is needed. the cache directory defaults to a private temporary directory; use persistent storage to keep limits across restarts. it contains private bookmark data, isolated by token hash, not the token itself.

## PHP

```php
use vielhuber\pinboardmcp\pinboardmcp;

$pinboard = new pinboardmcp(apiToken: 'username:token');
$pinboard->postsAll();
$pinboard->searchBookmarks(query: '"machine learning" python', limit: 20);
$pinboard->postsAdd(url: 'https://example.com/', description: 'Example', shared: false, replace: false);
```

## MCP server

```sh
vendor/bin/mcp-server.php
```

stdio needs no MCP token. for HTTP, set a random `MCP_TOKEN`, serve over HTTPS with `Authorization: Bearer <MCP_TOKEN>`, and deny web access to `.env`, logs and cache files.

all v1 endpoints are exposed (PHP methods use camelCase):

| tools                                                       | endpoints                                                   |
| ----------------------------------------------------------- | ----------------------------------------------------------- |
| `posts_update`, `posts_add`, `posts_delete`, `posts_get`    | `posts/update`, `posts/add`, `posts/delete`, `posts/get`    |
| `posts_dates`, `posts_recent`, `posts_all`, `posts_suggest` | `posts/dates`, `posts/recent`, `posts/all`, `posts/suggest` |
| `tags_get`, `tags_delete`, `tags_rename`                    | `tags/get`, `tags/delete`, `tags/rename`                    |
| `user_secret`, `user_api_token`                             | `user/secret`, `user/api_token`                             |
| `notes_list`, `notes_get`                                   | `notes/list`, `notes/ID`                                    |

API arguments and responses are preserved. booleans become `yes`/`no`; in v1, `posts_add.description` means title and `extended` means description. omission preserves API defaults. updates use `posts_add` with `replace: true`; read first and preserve unchanged fields. v1 has no note creation, editing or deletion endpoints.

## search

`search_bookmarks` searches the full bookmark collection locally, not just a recent window. no 90-day cap. Unicode case-insensitive terms, quoted phrases, `match: all|any`, exact tag intersections (`tag`), date bounds (`fromdt`, `todt`) and pagination (`limit`, `offset`, `next_offset`) are supported.

matches in titles and tags rank above URLs and descriptions; exact titles and whole tags receive a bonus. ties prefer newer bookmarks. results include the total, scores, matched fields, snapshot time and cache age. archived page bodies are not available through v1 and are not searched.

## caching & limits

reads are cached for five minutes. repeated searches reuse the same complete snapshot. `posts_all` returns every bookmark when filters/pagination are omitted; prefer `search_bookmarks` for bounded answers. direct API calls with different arguments have distinct caches but share endpoint cooldowns.

one private file lock and persistent ledger coordinate all processes per token: at least three seconds between requests, five minutes between `posts/all` calls and one minute between `posts/recent` calls. HTTP 429 honors `Retry-After`. safe reads retry transport errors and HTTP 5xx/429 with exponential backoff, at most three attempts. explicitly rate-rejected writes may retry; uncertain writes never do. waits over 30 seconds return a retry-in-seconds error instead of blocking indefinitely; the cooldown remains stored.

writes invalidate cached reads before transmission, including uncertain writes. a full refresh may therefore need to wait for the five-minute cooldown; use `posts_get` for targeted readback. secret/token responses are never cached. TLS certificate verification stays enabled, and credential-bearing requests never follow redirects.
