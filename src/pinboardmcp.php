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
     * Save a bookmark; description is the title and extended is its description in v1.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'add')]
    public function add(
        string $url,
        string $description,
        ?string $extended = null,
        #[Schema(description: 'Whitespace-separated tags (up to 100).')] ?string $tags = null,
        ?string $dt = null,
        ?bool $replace = null,
        ?bool $shared = null,
        ?bool $toread = null
    ): array {
        return $this->client->request(
            'posts/add',
            compact('url', 'description', 'extended', 'tags', 'dt', 'replace', 'shared', 'toread'),
            write: true
        );
    }

    /**
     * Permanently delete the bookmark identified by exactly one URL or hash.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'delete')]
    public function delete(?string $url = null, #[Schema(pattern: Client::HASH_PATTERN)] ?string $hash = null): array
    {
        $url = $this->client->resolveUrl($url, $hash);
        if ($url === null) {
            throw PinboardException::failed('Provide a bookmark url or hash.');
        }
        return $this->client->request('posts/delete', compact('url'), write: true);
    }

    /**
     * Read by URL or hash, or by date; without these, Pinboard uses the most recent day.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'read')]
    public function read(
        ?string $tag = null,
        ?string $dt = null,
        ?string $url = null,
        ?bool $meta = null,
        #[Schema(pattern: Client::HASH_PATTERN)] ?string $hash = null
    ): array {
        $url = $this->client->resolveUrl($url, $hash);
        return $this->client->request('posts/get', compact('tag', 'dt', 'url', 'meta'));
    }

    /**
     * Update by URL or hash, reading fresh values and preserving every omitted field.
     *
     * @return array<mixed>
     */
    #[McpTool(name: 'update')]
    public function update(
        ?string $url = null,
        ?string $description = null,
        ?string $extended = null,
        ?string $tags = null,
        ?string $dt = null,
        ?bool $shared = null,
        ?bool $toread = null,
        #[Schema(pattern: Client::HASH_PATTERN)] ?string $hash = null
    ): array {
        $url = $this->client->resolveUrl($url, $hash);
        if ($url === null) {
            throw PinboardException::failed('Provide a bookmark url or hash.');
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
     * @return array<mixed>
     */
    #[McpTool(name: 'recent')]
    public function recent(?string $tag = null, #[Schema(minimum: 1, maximum: 100)] ?int $count = null): array
    {
        return $this->client->request('posts/recent', compact('tag', 'count'));
    }

    /**
     * Retrieve every bookmark by default, without a date-range cap; reads are cached for five minutes.
     *
     * @return array<mixed>
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
        return $this->client->request('posts/all', compact('tag', 'start', 'results', 'fromdt', 'todt', 'meta'));
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
            $created = strtotime((string) ($bookmark['time'] ?? ''));
            if (($from !== null && $created < $from) || ($to !== null && $created > $to)) {
                continue;
            }
            $fields = [
                'title' => (string) ($bookmark['description'] ?? ''),
                'tags' => (string) ($bookmark['tags'] ?? ''),
                'url' => ($bookmark['href'] ?? '') . "\n" . rawurldecode((string) ($bookmark['href'] ?? '')),
                'description' => (string) ($bookmark['extended'] ?? '')
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
            $found[] = $bookmark + ['score' => $score, 'matched_fields' => array_keys($matchedFields)];
        }
        usort(
            $found,
            fn($left, $right) => $right['score'] <=> $left['score'] ?:
            strcmp($right['time'] ?? '', $left['time'] ?? '') ?:
            strcmp($left['href'] ?? '', $right['href'] ?? '')
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
