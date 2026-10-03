<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use Pubb\{PubbServer, ApiException, ProtocolException};

function check(bool $value, string $message = 'Assertion failed'): void { if (!$value) { throw new RuntimeException($message); } }
function raises(string $class, callable $fn): Throwable {
    try { $fn(); } catch (Throwable $error) { if ($error instanceof $class) { return $error; } throw $error; }
    throw new RuntimeException('Expected ' . $class);
}

$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/pubb-php-test.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/pubb-php-test.log', 'a']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Could not start test server'); }
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(20000);
    }
    check($ready, 'Test server did not start');
    $pubb = new PubbServer('app/id', 'public-key', 'test-secret', 'http://' . $address);
    $tests = [
        'HTTP contract' => function () use ($pubb) {
            $result = $pubb->trigger(['updates', 'room'], 'message', ['text' => 'Merhaba 🌍'], '1.2', 'event-1');
            $request = json_decode($result->publicationId, true);
            check($request['uri'] === '/api/apps/app%2Fid/events');
            check($request['headers']['Authorization'] === 'Bearer test-secret');
            check($request['headers']['Idempotency-Key'] === 'event-1');
            check($request['body'] === ['channel' => ['updates', 'room'], 'name' => 'message', 'data' => ['text' => 'Merhaba 🌍'], 'socket_id' => '1.2']);
            check($result->accepted && !$result->duplicate);
        },
        'duplicate receipt' => fn() => check($pubb->trigger('updates', 'duplicate', null)->duplicate),
        'error metadata' => function () use ($pubb) {
            $e = raises(ApiException::class, fn() => $pubb->trigger('updates', 'rate-limit', null));
            check($e->status === 429 && $e->retryAfter === '5');
            check(!str_contains($e->getMessage(), 'sensitive'));
        },
        'redirect rejected' => function () use ($pubb) {
            check(raises(ApiException::class, fn() => $pubb->trigger('updates', 'redirect', null))->status === 307);
        },
        'only HTTP 202' => fn() => raises(ApiException::class, fn() => $pubb->trigger('updates', 'wrong-status', null)),
        'invalid receipt' => fn() => raises(ProtocolException::class, fn() => $pubb->trigger('updates', 'invalid', null)),
        'private vector' => fn() => check($pubb->authorizeChannel('123.456', 'private-room')['auth'] === 'public-key:f3312a32998c7c174fb5d44146dad485d980398fe616181bf1f758764ccf603a'),
        'presence vector' => function () use ($pubb) {
            check($pubb->authorizePresenceChannel('123.456', 'presence-room', 'user-1', ['name' => 'Ada']) === [
                'auth' => 'public-key:41d9f92ca8a5496a5f346458be7d75c0d79bce9928260f8d139330140140d939',
                'channel_data' => '{"user_id":"user-1","user_info":{"name":"Ada"}}',
            ]);
        },
        'validation' => function () use ($pubb) {
            foreach ([[], [''], ['debug-room'], ['_room'], [str_repeat('🌍', 101)]] as $channels) {
                raises(InvalidArgumentException::class, fn() => $pubb->trigger($channels, 'message', null));
            }
            raises(InvalidArgumentException::class, fn() => $pubb->trigger('room', 'message', str_repeat('é', 5120)));
            raises(InvalidArgumentException::class, fn() => $pubb->trigger('room', 'message', null, idempotencyKey: "bad\r\nkey"));
            raises(JsonException::class, fn() => $pubb->trigger('room', 'message', NAN));
            raises(InvalidArgumentException::class, fn() => $pubb->authorizeChannel('1.2', 'presence-room'));
            raises(InvalidArgumentException::class, fn() => $pubb->authorizePresenceChannel('1.2', 'private-room', 'user'));
        },
        'configuration' => function () {
            foreach (['ftp://example.com', 'https://user@example.com', 'https://example.com?q=1'] as $host) {
                raises(InvalidArgumentException::class, fn() => new PubbServer('id', 'key', 'secret', $host));
            }
            raises(InvalidArgumentException::class, fn() => new PubbServer('id', 'key', "secret\r\nInjected: yes"));
            raises(InvalidArgumentException::class, fn() => new PubbServer('id', 'key', 'secret', timeout: 0));
        },
        'timeout' => function () use ($address) {
            $client = new PubbServer('id', 'key', 'secret', 'http://' . $address, 0.05);
            raises(RuntimeException::class, fn() => $client->trigger('updates', 'timeout', null));
        },
    ];
    foreach ($tests as $name => $test) { $test(); echo "PASS {$name}\n"; }
    echo count($tests) . " tests passed\n";
} finally { fclose($pipes[0]); proc_terminate($process); proc_close($process); }
