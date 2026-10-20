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
$pinboard->add(url: 'https://example.com/', description: 'example', shared: false, replace: false);
$hash = $pinboard->read(url: 'https://example.com/')['posts'][0]['hash'];
$pinboard->update(hash: $hash, description: 'new title');
$pinboard->delete(hash: $hash);
$pinboard->updated();
$pinboard->dates();
$pinboard->recent();
$pinboard->suggest('https://example.com/');
$pinboard->tags();
$pinboard->untag('example');
$pinboard->retag('example', 'renamed');
$pinboard->secret();
$pinboard->token();
$pinboard->notes();
$pinboard->note('0123456789abcdef');
```

`read`, `update` and `delete` accept either `url` or the `hash` returned by pinboard. `update` preserves omitted fields.

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
