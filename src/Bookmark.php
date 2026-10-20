<?php
declare(strict_types=1);

namespace vielhuber\pinboardmcp;

final readonly class Bookmark
{
    public string $id;
    public string $url;
    public string $description;
    public string $extended;
    public string $tags;
    public string $time;
    public bool $shared;
    public bool $toread;
    public ?string $meta;

    /**
     * Translate Pinboard's URL hash and yes/no flags into public bookmark fields.
     *
     * @param array<string, mixed> $bookmark
     */
    public function __construct(array $bookmark)
    {
        $this->id = $bookmark['hash'];
        $this->url = $bookmark['href'];
        $this->description = $bookmark['description'];
        $this->extended = $bookmark['extended'];
        $this->tags = $bookmark['tags'];
        $this->time = $bookmark['time'];
        $this->shared = $bookmark['shared'] === 'yes';
        $this->toread = $bookmark['toread'] === 'yes';
        $this->meta = $bookmark['meta'] ?? null;
    }
}
