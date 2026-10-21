<?php
declare(strict_types=1);

namespace vielhuber\pinboardmcp;

use vielhuber\simplemcp\Attributes\McpTool;
use vielhuber\simplemcp\Attributes\Schema;

final class pinboardmcp
{
    private readonly Client $client;

    /**
     * Reuse environment configuration for both PHP and MCP consumers.
     */
    public function __construct(#[\SensitiveParameter] ?string $apiToken = null, ?string $cacheDirectory = null)
    {
        $this->client = new Client(
            apiToken: $apiToken ?? (getenv('PINBOARD_API_TOKEN') ?: $_ENV['PINBOARD_API_TOKEN'] ?? ''),
            cacheDirectory: $cacheDirectory ??
                (getenv('PINBOARD_CACHE_DIR') ?:
                $_ENV['PINBOARD_CACHE_DIR'] ?? '' ?:
                    sys_get_temp_dir() . '/pinboardmcp')
        );
    }

    /**
     * Check the most recent bookmark addition, update or deletion.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'updated')]
    public function updated(): array
    {
        return $this->client->request('posts/update');
    }

    /**
     * Save and verify supplied fields; description is the title and extended its description.
     */
    #[McpTool(name: 'add')]
    public function add(
        string $url,
        string $description,
        #[Schema(minLength: 0)] ?string $extended = null,
        #[Schema(description: 'Whitespace-separated tags (up to 100).', minLength: 0)] ?string $tags = null,
        ?string $dt = null,
        ?bool $replace = null,
        ?bool $shared = null,
        ?bool $toread = null
    ): Bookmark {
        $this->client->request(
            'posts/add',
            compact('url', 'description', 'extended', 'tags', 'dt', 'replace', 'shared', 'toread'),
            write: true
        );
        try {
            $bookmarks = $this->client->request('posts/get', compact('url'), refresh: true)['posts'];
        } catch (PinboardException $exception) {
            throw PinboardException::failed(
                'Bookmark saved; read before retrying. ' . $exception->getMessage(),
                $exception->getCode()
            );
        }
        if (count($bookmarks) !== 1) {
            throw PinboardException::failed('Bookmark saved but readback failed; read before retrying.');
        }
        $bookmark = new Bookmark($bookmarks[0]);
        $differentFields = [];
        foreach (
            [
                'url' => $url,
                'description' => $description,
                'extended' => $extended,
                'shared' => $shared,
                'toread' => $toread
            ]
            as $field => $expectedValue
        ) {
            if ($expectedValue === null || $bookmark->$field === $expectedValue) {
                continue;
            }
            $differentFields[] = $field;
        }
        if ($tags !== null) {
            $expectedTags = preg_split('/\s+/u', trim($tags), flags: PREG_SPLIT_NO_EMPTY);
            $actualTags = preg_split('/\s+/u', trim($bookmark->tags), flags: PREG_SPLIT_NO_EMPTY);
            if (array_diff($expectedTags, $actualTags) !== [] || array_diff($actualTags, $expectedTags) !== []) {
                $differentFields[] = 'tags';
            }
        }
        if ($dt !== null && strtotime($dt) !== strtotime($bookmark->time)) {
            $differentFields[] = 'time';
        }
        if ($differentFields !== []) {
            throw PinboardException::failed(
                'Bookmark saved but readback differs for: ' .
                    implode(', ', $differentFields) .
                    '. Read before retrying; do not repeat the write blindly.'
            );
        }
        return $bookmark;
    }

    /**
     * Permanently delete the bookmark identified by exactly one URL or ID.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'delete')]
    public function delete(?string $url = null, #[Schema(pattern: Client::ID_PATTERN)] ?string $id = null): array
    {
        $url = $this->client->resolveUrl($url, $id);
        if ($url === null) {
            throw PinboardException::failed('Provide a bookmark url or id.');
        }
        return $this->client->request('posts/delete', compact('url'), write: true);
    }

    /**
     * Return one bookmark or null by URL/ID, or a flat list for date/tag queries.
     *
     * @return Bookmark|list<Bookmark>|null
     */
    #[McpTool(name: 'read')]
    public function read(
        ?string $tag = null,
        ?string $dt = null,
        ?string $url = null,
        ?bool $meta = null,
        #[Schema(pattern: Client::ID_PATTERN)] ?string $id = null
    ): Bookmark|array|null {
        try {
            $url = $this->client->resolveUrl($url, $id);
        } catch (PinboardException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }
            return null;
        }
        $bookmarks = array_map(
            static fn(array $bookmark): Bookmark => new Bookmark($bookmark),
            $this->client->request('posts/get', compact('tag', 'dt', 'url', 'meta'))['posts']
        );
        return $url === null ? $bookmarks : $bookmarks[0] ?? null;
    }

    /**
     * Update by URL or ID, reading fresh values and preserving every omitted field.
     */
    #[McpTool(name: 'update')]
    public function update(
        ?string $url = null,
        #[Schema(minLength: 0)] ?string $description = null,
        #[Schema(minLength: 0)] ?string $extended = null,
        #[Schema(minLength: 0)] ?string $tags = null,
        ?string $dt = null,
        ?bool $shared = null,
        ?bool $toread = null,
        #[Schema(pattern: Client::ID_PATTERN)] ?string $id = null
    ): Bookmark {
        $url = $this->client->resolveUrl($url, $id);
        if ($url === null) {
            throw PinboardException::failed('Provide a bookmark url or id.');
        }
        $bookmarks = $this->client->request('posts/get', compact('url'), refresh: true)['posts'];
        if (count($bookmarks) !== 1) {
            throw PinboardException::failed('Bookmark not found.', 404);
        }
        $bookmark = $bookmarks[0];
        return $this->add(
            url: $bookmark['href'],
            description: $description ?? $bookmark['description'],
            extended: $extended ?? $bookmark['extended'],
            tags: $tags ?? $bookmark['tags'],
            dt: $dt ?? $bookmark['time'],
            replace: true,
            shared: $shared ?? $bookmark['shared'] === 'yes',
            toread: $toread ?? $bookmark['toread'] === 'yes'
        );
    }

    /**
     * Count bookmarks by date, optionally intersecting up to three whitespace-separated tags.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'dates')]
    public function dates(?string $tag = null): array
    {
        return $this->client->request('posts/dates', compact('tag'));
    }

    /**
     * Read recent bookmarks with Pinboard's one-minute endpoint cooldown.
     *
     * @return list<Bookmark>
     */
    #[McpTool(name: 'recent')]
    public function recent(?string $tag = null, #[Schema(minimum: 1, maximum: 100)] ?int $count = null): array
    {
        return array_map(
            static fn(array $bookmark): Bookmark => new Bookmark($bookmark),
            $this->client->request('posts/recent', compact('tag', 'count'))['posts']
        );
    }

    /**
     * Retrieve every bookmark by default, without a date-range cap; reads are cached for five minutes.
     *
     * @return list<Bookmark>
     */
    #[McpTool(name: 'get')]
    public function get(
        #[Schema(description: 'Intersect up to three whitespace-separated tags.')] ?string $tag = null,
        #[Schema(minimum: 0)] ?int $start = null,
        #[Schema(minimum: 1)] ?int $results = null,
        ?string $fromdt = null,
        ?string $todt = null,
        #[Schema(minimum: 0, maximum: 1)] ?int $meta = null
    ): array {
        return array_map(
            static fn(array $bookmark): Bookmark => new Bookmark($bookmark),
            $this->client->request('posts/all', compact('tag', 'start', 'results', 'fromdt', 'todt', 'meta'))
        );
    }

    /**
     * Get popular and account-specific tag suggestions for a URL.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'suggest')]
    public function suggest(string $url): array
    {
        return $this->client->request('posts/suggest', compact('url'));
    }

    /**
     * Read the complete tag vocabulary with usage counts.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'tags')]
    public function tags(): array
    {
        return $this->client->request('tags/get');
    }

    /**
     * Remove a tag from all bookmarks, without deleting the bookmarks themselves.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'untag')]
    public function untag(string $tag): array
    {
        return $this->client->request('tags/delete', compact('tag'), write: true);
    }

    /**
     * Rename a tag globally or fold it into an existing tag.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'retag')]
    public function retag(string $old, string $new): array
    {
        return $this->client->request('tags/rename', compact('old', 'new'), write: true);
    }

    /**
     * Reveal the secret key for private RSS feeds only when explicitly needed.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'secret')]
    public function secret(): array
    {
        return $this->client->request('user/secret');
    }

    /**
     * Reveal the API token only when explicitly needed; this response is never cached.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'token')]
    public function token(): array
    {
        return $this->client->request('user/api_token');
    }

    /**
     * List note metadata; note creation and editing are not part of v1.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'notes')]
    public function notes(): array
    {
        return $this->client->request('notes/list');
    }

    /**
     * Read the complete text of a note selected from notes.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'note')]
    public function note(#[Schema(pattern: '^[a-fA-F0-9]+$')] string $id): array
    {
        if (preg_match('/^[a-fA-F0-9]+$/D', $id) !== 1) {
            throw PinboardException::failed('Use a hexadecimal note ID from notes.');
        }
        return $this->client->request('notes/' . $id);
    }

    /**
     * Search all cached bookmarks locally by Unicode case-insensitive terms and quoted phrases.
     * Terms match titles, tags, URLs and descriptions (not archived page bodies). All terms must match
     * unless match is any. Exact titles and tags rank highest, followed by title, tag, URL and description
     * matches; ties prefer newer bookmarks. Date and tag filters apply locally, without extra API calls.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'search')]
    public function search(
        string $query,
        ?string $tag = null,
        ?string $fromdt = null,
        ?string $todt = null,
        #[Schema(enum: ['all', 'any'])] string $match = 'all',
        #[Schema(minimum: 1, maximum: 500)] int $limit = 50,
        #[Schema(minimum: 0)] int $offset = 0
    ): array {
        if (
            trim($query) === '' ||
            !in_array($match, ['all', 'any'], true) ||
            $limit < 1 ||
            $limit > 500 ||
            $offset < 0
        ) {
            throw PinboardException::failed(
                'Provide a nonempty query, match all/any, limit 1–500 and a nonnegative offset.'
            );
        }
        $from = $fromdt === null ? null : strtotime($fromdt);
        $to = $todt === null ? null : strtotime($todt);
        if ($from === false || $to === false || ($from !== null && $to !== null && $from > $to)) {
            throw PinboardException::failed('Provide valid dates in chronological order.');
        }
        preg_match_all('/"([^"]+)"|(\S+)/u', mb_convert_case($query, MB_CASE_FOLD, 'UTF-8'), $matches, PREG_SET_ORDER);
        $terms = array_values(array_unique(array_map(fn($part) => $part[1] !== '' ? $part[1] : $part[2], $matches)));
        $requiredTags = preg_split(
            '/\s+/u',
            mb_convert_case(trim($tag ?? ''), MB_CASE_FOLD, 'UTF-8'),
            flags: PREG_SPLIT_NO_EMPTY
        );
        $found = [];
        foreach ($this->get() as $bookmark) {
            $created = strtotime($bookmark->time);
            if (($from !== null && $created < $from) || ($to !== null && $created > $to)) {
                continue;
            }
            $fields = [
                'title' => $bookmark->description,
                'tags' => $bookmark->tags,
                'url' => $bookmark->url . "\n" . rawurldecode($bookmark->url),
                'description' => $bookmark->extended
            ];
            $fields = array_map(static fn($value) => mb_convert_case($value, MB_CASE_FOLD, 'UTF-8'), $fields);
            $bookmarkTags = preg_split('/\s+/u', $fields['tags'], flags: PREG_SPLIT_NO_EMPTY);
            if (array_diff($requiredTags, $bookmarkTags) !== []) {
                continue;
            }
            $score = 0;
            $matchedTerms = 0;
            $matchedFields = [];
            foreach ($terms as $term) {
                $termMatched = false;
                foreach (['title' => 8, 'tags' => 6, 'url' => 3, 'description' => 1] as $field => $weight) {
                    if (!str_contains($fields[$field], $term)) {
                        continue;
                    }
                    $termMatched = true;
                    $score += $weight;
                    $matchedFields[$field] = true;
                }
                if ($fields['title'] === $term) {
                    $score += 20;
                }
                if (in_array($term, $bookmarkTags, true)) {
                    $score += 15;
                }
                $matchedTerms += (int) $termMatched;
            }
            if ($matchedTerms === 0 || ($match === 'all' && $matchedTerms !== count($terms))) {
                continue;
            }
            $found[] = (array) $bookmark + ['score' => $score, 'matched_fields' => array_keys($matchedFields)];
        }
        usort(
            $found,
            fn($left, $right) => $right['score'] <=> $left['score'] ?:
            strcmp($right['time'] ?? '', $left['time'] ?? '') ?:
            strcmp($left['url'], $right['url'])
        );
        $total = count($found);
        return [
            'total' => $total,
            'offset' => $offset,
            'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
            'fetched_at' => gmdate('c', $this->client->fetchedAt),
            'cache_age_seconds' => max(0, time() - $this->client->fetchedAt),
            'bookmarks' => array_slice($found, $offset, $limit)
        ];
    }
}
