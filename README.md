# 📌pinboardmcp📌

your [pinboard](https://pinboard.in/), ready for php and ai.

## installation

```sh
composer require vielhuber/pinboardmcp
```

## php

```php
use vielhuber\pinboardmcp\pinboardmcp;

$pinboard = new pinboardmcp('username:token');
$bookmarks = $pinboard->get();
$pinboard->search('php');
$bookmark = $pinboard->add(
    url: 'https://example.com',
    description: 'example title',
    extended: 'example description',
    tags: 'php',
    shared: false,
    toread: true,
    replace: false
);
$id = $bookmark->id;
$bookmark = $pinboard->read(url: 'https://example.com');
$bookmark = $pinboard->read(id: $id);
if ($bookmark !== null) {
    echo $bookmark->url;
    $bookmark = $pinboard->update(id: $id, tags: 'php bookmarks');
    $bookmark = $pinboard->update(url: $bookmark->url, description: 'new title');
    $pinboard->delete(id: $id);
}
$bookmarks = $pinboard->read(tag: 'php');
$bookmarks = $pinboard->read(dt: '2026-01-01T00:00:00Z');
$pinboard->updated();
$pinboard->dates();
$bookmarks = $pinboard->recent();
$pinboard->suggest('https://example.com');
$pinboard->tags();
$pinboard->untag('example');
$pinboard->retag('example', 'renamed');
$pinboard->secret();
$pinboard->token();
$pinboard->notes();
$pinboard->note('0123456789abcdef');
```

get your token from [settings → password](https://pinboard.in/settings/password/).

## mcp

the same functions are available as tools. add this to your client's mcp configuration (for claude code: `.mcp.json`):

```json
{
    "mcpServers": {
        "pinboard": {
            "command": "php",
            "args": ["/absolute/path/to/vendor/bin/mcp-server.php"],
            "env": {
                "PINBOARD_API_TOKEN": "username:token"
            }
        }
    }
}
```
