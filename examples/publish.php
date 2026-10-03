<?php
require __DIR__ . '/../vendor/autoload.php';

$pubb = new Pubb\PubbServer(getenv('PUBB_APP_ID'), getenv('PUBB_APP_KEY'), getenv('PUBB_APP_SECRET'));
$result = $pubb->trigger('updates', 'message', ['text' => 'Hello from PHP']);
echo $result->publicationId . PHP_EOL;
