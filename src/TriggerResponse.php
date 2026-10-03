<?php
declare(strict_types=1);
namespace Pubb;

final class TriggerResponse
{
    /** @param list<string> $channels */
    public function __construct(
        public readonly bool $success,
        public readonly bool $accepted,
        public readonly array $channels,
        public readonly string $publicationId,
        public readonly bool $duplicate,
    ) {}
}
