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
$pinboard->get();
$pinboard->search('php');
$bookmark = $pinboard->add(url: 'https://example.com', description: 'example', shared: false, replace: false);
$bookmark = $pinboard->read(id: $bookmark->id);
$bookmark = $pinboard->update(id: $bookmark->id, description: 'new title');
$pinboard->delete(id: $bookmark->id);
$pinboard->updated();
$pinboard->dates();
$pinboard->recent();
$pinboard->suggest('https://example.com');
$pinboard->tags();
$pinboard->untag('example');
$pinboard->retag('example', 'renamed');
$pinboard->secret();
$pinboard->token();
$pinboard->notes();
$pinboard->note('0123456789abcdef');
```

`add` and `update` return the saved bookmark. `read(url: 'https://example.com')` or `read(id: $id)` returns a bookmark or `null`; date/tag queries return a flat list. `get` and `recent` also return flat lists.

bookmark fields: `->id`, `->url`, `->description`, `->extended`, `->tags`, `->time`, `->shared`, `->toread`, `->meta`. flags are booleans. `description` is the title; `extended` is the description.

`read`, `update` and `delete` accept either `url` or `id`. `update` preserves omitted fields.

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
