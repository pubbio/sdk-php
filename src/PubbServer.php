<?php
declare(strict_types=1);
namespace Pubb;

/** Synchronous backend SDK. Never ship appSecret to browsers or mobile clients. */
final class PubbServer
{
    private string $endpoint;

    public function __construct(
        string $appId,
        private readonly string $appKey,
        private readonly string $appSecret,
        string $apiHost = 'https://api.pubb.io',
        private readonly float $timeout = 30,
    ) {
        foreach ([$appId, $appKey, $appSecret] as $value) {
            self::requireString($value, 'credential');
            if (strpbrk($value, "\r\n") !== false) {
                throw new \InvalidArgumentException('Credentials must not contain newlines');
            }
        }
        $url = parse_url($apiHost);
        if ($url === false || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host'])
            || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment'])) {
            throw new \InvalidArgumentException('apiHost must be an HTTP(S) URL without credentials, query or fragment');
        }
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be positive and finite');
        }
        $this->endpoint = rtrim($apiHost, '/') . '/api/apps/' . rawurlencode($appId) . '/events';
    }

    public function __debugInfo(): array { return ['endpoint' => $this->endpoint]; }

    /** @param string|list<string> $channel HTTP 202 is acceptance, not delivery. */
    public function trigger(string|array $channel, string $name, mixed $data,
        ?string $socketId = null, ?string $idempotencyKey = null): TriggerResponse
    {
        $channels = is_string($channel) ? [$channel] : $channel;
        if (!array_is_list($channels) || count($channels) < 1 || count($channels) > 100) {
            throw new \InvalidArgumentException('Use 1 to 100 channels');
        }
        foreach ($channels as $item) {
            self::requireString($item, 'channel', 200);
            if (str_starts_with($item, 'debug-') || str_starts_with($item, '_')) {
                throw new \InvalidArgumentException('Reserved channel name');
            }
        }
        self::requireString($name, 'name', 200);
        // Encode once, so JsonSerializable cannot change its output between validation and send.
        $encodedData = self::json($data);
        if (strlen($encodedData) > 10240) {
            throw new \InvalidArgumentException('data exceeds 10 KiB of JSON');
        }
        $body = '{"channel":' . self::json($channel) . ',"name":' . self::json($name) . ',"data":' . $encodedData;
        if ($socketId !== null) {
            self::requireString($socketId, 'socketId', 100);
            $body .= ',"socket_id":' . self::json($socketId);
        }
        $body .= '}';
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->appSecret];
        if ($idempotencyKey !== null) {
            if (!preg_match('/\A[\x21-\x7e]{1,128}\z/', $idempotencyKey)) {
                throw new \InvalidArgumentException('Invalid idempotency key');
            }
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
            'timeout' => $this->timeout, 'ignore_errors' => true, 'follow_location' => 0,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @fopen($this->endpoint, 'rb', false, $context);
        if ($stream === false) {
            throw new \RuntimeException('Pubb HTTP request failed');
        }
        try {
            $metadata = stream_get_meta_data($stream);
            $status = 0;
            $retryAfter = null;
            foreach ($metadata['wrapper_data'] ?? [] as $header) {
                if (preg_match('/^HTTP\/\S+ (\d{3})/', $header, $match)) {
                    $status = (int) $match[1];
                    $retryAfter = null;
                } elseif (stripos($header, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($header, strlen('Retry-After:')));
                }
            }
            if ($status !== 202) {
                throw new ApiException($status, $retryAfter);
            }
            $raw = @stream_get_contents($stream, 128 * 1024 + 1);
            if ($raw === false || stream_get_meta_data($stream)['timed_out']) {
                throw new \RuntimeException('Pubb HTTP response could not be read');
            }
            if (strlen($raw) > 128 * 1024) {
                throw new ProtocolException('Pubb response is too large');
            }
        } finally {
            fclose($stream);
        }
        try { $result = json_decode($raw, true, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ProtocolException('Pubb API returned invalid JSON'); }
        if (!is_array($result) || ($result['success'] ?? null) !== true || ($result['accepted'] ?? null) !== true
            || !is_array($result['channels'] ?? null) || !array_is_list($result['channels']) || !$result['channels']
            || !is_string($result['publicationId'] ?? null) || $result['publicationId'] === ''
            || !is_bool($result['duplicate'] ?? null)) {
            throw new ProtocolException('Pubb API returned an invalid acceptance response');
        }
        foreach ($result['channels'] as $item) {
            if (!is_string($item) || $item === '') {
                throw new ProtocolException('Pubb API returned invalid channels');
            }
        }
        return new TriggerResponse(true, true, $result['channels'], $result['publicationId'], $result['duplicate']);
    }

    /** Call only after your backend checks the user's channel permissions. */
    public function authorizeChannel(string $socketId, string $channel): array
    {
        self::validateAuth($socketId, $channel, 'private-');
        return ['auth' => $this->sign($socketId . ':' . $channel)];
    }

    public function authorizePresenceChannel(string $socketId, string $channel, string $userId, mixed $userInfo = null): array
    {
        self::validateAuth($socketId, $channel, 'presence-');
        self::requireString($userId, 'userId');
        $user = ['user_id' => $userId];
        if ($userInfo !== null) { $user['user_info'] = $userInfo; }
        $data = self::json($user);
        return ['auth' => $this->sign($socketId . ':' . $channel . ':' . $data), 'channel_data' => $data];
    }

    private function sign(string $data): string
    {
        return $this->appKey . ':' . hash_hmac('sha256', $data, $this->appSecret);
    }

    private static function json(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function requireString(mixed $value, string $name, ?int $limit = null): void
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException($name . ' must be a nonempty string');
        }
        // JSON's ASCII representation counts surrogate pairs the same way as the API.
        if ($limit !== null) {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $units = preg_replace('/\\\\(?:u[0-9a-fA-F]{4}|.)/', 'x', substr($json, 1, -1));
            if (strlen($units) > $limit) { throw new \InvalidArgumentException($name . ' is too long'); }
        }
    }

    private static function validateAuth(string $socketId, string $channel, string $prefix): void
    {
        self::requireString($socketId, 'socketId', 100);
        self::requireString($channel, 'channel', 200);
        if (!str_starts_with($channel, $prefix)) { throw new \InvalidArgumentException('Expected a ' . $prefix . ' channel'); }
    }
}
