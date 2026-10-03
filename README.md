# Pubb PHP server SDK

Publish events and authorize private/presence channels from PHP 8.1+. No external
runtime dependencies. Requires PHP's HTTP streams (`allow_url_fopen` enabled).

## Install

Until this package is listed on Packagist, add its repository to your application:

```sh
composer config repositories.pubb vcs https://github.com/pubbio/sdk-php
composer require pubb/sdk:dev-main
```

Commit your application's composer.lock to pin the resolved revision.

## Publish

```php
require 'vendor/autoload.php';

$pubb = new Pubb\PubbServer(
    appId: getenv('PUBB_APP_ID'), appKey: getenv('PUBB_APP_KEY'),
    appSecret: getenv('PUBB_APP_SECRET'), apiHost: 'https://api.pubb.io', timeout: 30,
);
$result = $pubb->trigger(['updates', 'notifications'], 'message', ['text' => 'Hello'],
    socketId: '123.456', idempotencyKey: 'message-42');
echo $result->publicationId;
```

Both optional arguments may be omitted. HTTP 202 means accepted for processing,
not delivered. Requests are synchronous and never retried automatically. Preserve
the same idempotency key when retrying a publication. `ApiException` provides
`status` and `retryAfter`; `ProtocolException` indicates an invalid response;
transport failures raise `RuntimeException`. Timeout applies to stream operations.
Redirects are rejected and TLS certificates are verified.

## Authorize subscriptions

Authenticate the user's session and check channel access in your own backend
route, then return this result as JSON:

```php
$pubb->authorizeChannel('123.456', 'private-room');
$pubb->authorizePresenceChannel('123.456', 'presence-room', 'user-1', ['name' => 'Ada']);
```

Use the session's user ID. The helpers sign data but do not check permissions.
Forward `channel_data` unchanged: its exact bytes are signed. Keep the app secret
on your server. This implements Pubb's HTTP API and shared HMAC format, and is not
a drop-in replacement for Pusher's HTTP SDK.

## Development

```sh
composer install
composer test
composer archive --format=zip --dir=dist
```

Tests start a local PHP HTTP server and cover the API contract, signing vectors,
redirects, validation, error handling and timeouts. MIT.
