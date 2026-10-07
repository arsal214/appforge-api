<?php

namespace App\Support\Push;

final class PushResult
{
    public function __construct(
        public readonly int $delivered,
        public readonly int $failed,
        public readonly ?string $reason = null,
    ) {}
}
