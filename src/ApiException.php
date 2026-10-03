<?php
declare(strict_types=1);
namespace Pubb;

final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly ?string $retryAfter = null)
    {
        parent::__construct("Pubb API returned HTTP {$status}");
    }
}
