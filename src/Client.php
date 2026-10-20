<?php
declare(strict_types=1);

namespace vielhuber\pinboardmcp;

final class Client
{
    public const HASH_PATTERN = '^[a-fA-F0-9]{32}$';

    private const CACHE_SECONDS = 300;
    private const MAX_ATTEMPTS = 3;
    private const MAX_WAIT_SECONDS = 30;

    public int $fetchedAt = 0;

    private readonly string $directory;

    /**
     * Share private caches and rate limits between processes using the same token.
     */
    public function __construct(#[\SensitiveParameter] private readonly string $apiToken, string $cacheDirectory)
    {
        if (preg_match('/^[^:\s]+:[^:\s]+$/D', $apiToken) !== 1) {
            throw PinboardException::failed('Configure PINBOARD_API_TOKEN as username:token.');
        }
        $this->directory = rtrim($cacheDirectory, '/') . '/' . hash('sha256', $apiToken);
    }

    /**
     * Preserve API responses while serializing requests, caching reads and retrying safe failures.
     *
     * @param array<string, string|int|bool|null> $parameters
     * @return array<mixed>
     */
    public function request(string $method, array $parameters = [], bool $write = false, bool $refresh = false): array
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw PinboardException::failed('Cannot create the private Pinboard cache directory.');
        }
        chmod($this->directory, 0700);
        $lock = fopen($this->directory . '/lock', 'c+');
        if ($lock === false) {
            throw PinboardException::failed('Cannot lock the Pinboard cache.');
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw PinboardException::failed('Cannot lock the Pinboard cache.');
        }
        chmod($this->directory . '/lock', 0600);
        try {
            $parameters = array_filter($parameters, fn($value) => $value !== null);
            $parameters = array_map(fn($value) => is_bool($value) ? ($value ? 'yes' : 'no') : $value, $parameters);
            ksort($parameters);
            $cachePath = $this->directory . '/cache-' . hash('sha256', $method . json_encode($parameters)) . '.json';
            $cached = $write || $refresh ? null : $this->readJson($cachePath);
            $statePath = $this->directory . '/state.json';
            $state = $this->readJson($statePath) ?? [];
            if ($cached !== null && time() - $cached['fetched_at'] < self::CACHE_SECONDS) {
                $this->fetchedAt = $cached['fetched_at'];
                $this->rememberUrls($method, $cached['response']);
                return $cached['response'];
            }
            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
                $wait = max(
                    0,
                    ($state['last_request'] ?? 0) + 3 - microtime(true),
                    ($state['methods'][$method] ?? 0) - microtime(true),
                    ($state['blocked_until'] ?? 0) - microtime(true)
                );
                if ($wait > self::MAX_WAIT_SECONDS) {
                    throw PinboardException::failed(
                        'Pinboard rate limit: retry in ' . (int) ceil($wait) . ' seconds.',
                        429
                    );
                }
                if ($wait > 0) {
                    usleep((int) ceil($wait * 1_000_000));
                }
                if ($write && $attempt === 0) {
                    foreach (glob($this->directory . '/cache-*.json') ?: [] as $path) {
                        if (!unlink($path)) {
                            throw PinboardException::failed('Cannot invalidate the Pinboard cache.');
                        }
                    }
                }
                $state['last_request'] = microtime(true);
                $state['methods'][$method] =
                    microtime(true) +
                    match ($method) {
                        'posts/all' => 300,
                        'posts/recent' => 60,
                        default => 3
                    };
                $this->writeJson($statePath, $state);
                $handle = curl_init(
                    'https://api.pinboard.in/v1/' .
                        $method .
                        '?' .
                        http_build_query(
                            $parameters + ['auth_token' => $this->apiToken, 'format' => 'json'],
                            encoding_type: PHP_QUERY_RFC3986
                        )
                );
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_USERAGENT => 'pinboardmcp/1.0 (+https://github.com/vielhuber/pinboardmcp)',
                    CURLOPT_HTTPHEADER => ['Accept: application/json']
                ]);
                $response = curl_exec($handle);
                $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
                $curlError = curl_errno($handle);
                $headers = $response === false ? '' : substr($response, 0, $headerSize);
                $body = $response === false ? '' : substr($response, $headerSize);
                $retryable = $status === 429 || (!$write && ($response === false || $status >= 500));
                if ($retryable) {
                    $delay = 3 * 2 ** $attempt;
                    if (preg_match('/^Retry-After:\s*(.+)$/mi', $headers, $matches) === 1) {
                        $retryAfter = trim($matches[1]);
                        $delay = max(
                            $delay,
                            ctype_digit($retryAfter) ? (int) $retryAfter : (strtotime($retryAfter) ?: time()) - time()
                        );
                    }
                    $state['blocked_until'] = microtime(true) + $delay;
                    $this->writeJson($statePath, $state);
                    if ($attempt + 1 < self::MAX_ATTEMPTS) {
                        continue;
                    }
                }
                if ($response === false || $status < 200 || $status >= 300) {
                    throw PinboardException::failed(
                        'Pinboard request failed (' .
                            ($status > 0 ? 'HTTP ' . $status : 'cURL ' . $curlError) .
                            ').' .
                            ($write ? ' Read the current state before retrying an uncertain write.' : ''),
                        $status
                    );
                }
                try {
                    $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw PinboardException::failed(
                        'Pinboard returned invalid JSON.' .
                            ($write ? ' The write may have succeeded; read before retrying.' : '')
                    );
                }
                if (!is_array($decoded)) {
                    throw PinboardException::failed('Pinboard returned an unexpected response.');
                }
                if (isset($decoded['result_code']) && $decoded['result_code'] !== 'done') {
                    throw PinboardException::failed(
                        'Pinboard rejected the operation: ' .
                            str_replace($this->apiToken, '[redacted]', (string) $decoded['result_code'])
                    );
                }
                $this->fetchedAt = time();
                $this->rememberUrls($method, $decoded);
                if (!$write && !str_starts_with($method, 'user/')) {
                    $this->writeJson($cachePath, ['fetched_at' => $this->fetchedAt, 'response' => $decoded]);
                }
                return $decoded;
            }
            throw PinboardException::failed('Pinboard retries exhausted.');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Resolve one bookmark identifier without guessing URL equivalences.
     */
    public function resolveUrl(?string $url, ?string $hash): ?string
    {
        if ($url !== null && $hash !== null) {
            throw PinboardException::failed('Provide either url or hash, not both.');
        }
        if ($url !== null && trim($url) === '') {
            throw PinboardException::failed('Provide a nonempty bookmark URL.');
        }
        if ($hash === null) {
            return $url;
        }
        if (preg_match('/' . self::HASH_PATTERN . '/D', $hash) !== 1) {
            throw PinboardException::failed('Use the 32-character bookmark hash returned by Pinboard.');
        }
        $hash = strtolower($hash);
        $path = $this->directory . '/urls.json';
        $resolvedUrl = $this->readJson($path)[$hash] ?? null;
        if ($resolvedUrl === null) {
            $this->request('posts/all');
            $resolvedUrl = $this->readJson($path)[$hash] ?? null;
        }
        return $resolvedUrl ?? throw PinboardException::failed('Bookmark hash not found.', 404);
    }

    /**
     * Retain exact URLs across cache invalidation and process restarts.
     *
     * @param array<mixed> $response
     */
    private function rememberUrls(string $method, array $response): void
    {
        if (!in_array($method, ['posts/all', 'posts/get', 'posts/recent'], strict: true)) {
            return;
        }
        $path = $this->directory . '/urls.json';
        $urls = $this->readJson($path) ?? [];
        $updated = $urls;
        foreach ($method === 'posts/all' ? $response : $response['posts'] as $bookmark) {
            $updated[strtolower($bookmark['hash'])] = $bookmark['href'];
        }
        if ($updated !== $urls) {
            $this->writeJson($path, $updated);
        }
    }

    /**
     * Fail closed on corrupt state instead of silently forgetting rate limits.
     *
     * @return array<mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw PinboardException::failed('Pinboard cache state is invalid.');
        }
        if (!is_array($decoded)) {
            throw PinboardException::failed('Pinboard cache state is invalid.');
        }
        return $decoded;
    }

    /**
     * Atomically replace private cache data without storing the API token.
     *
     * @param array<mixed> $value
     */
    private function writeJson(string $path, array $value): void
    {
        $temporary = tempnam($this->directory, '.pending-');
        if ($temporary === false) {
            throw PinboardException::failed('Cannot create a Pinboard cache file.');
        }
        try {
            chmod($temporary, 0600);
            if (
                file_put_contents($temporary, json_encode($value, JSON_THROW_ON_ERROR)) === false ||
                !rename($temporary, $path)
            ) {
                throw PinboardException::failed('Cannot save Pinboard cache state.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
