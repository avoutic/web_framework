<?php

namespace WebFramework\Core;

interface CacheService
{
    public function exists(string $path): bool;

    public function get(string $path): mixed;

    public function set(string $path, mixed $obj, ?int $expires_after = null): void;

    public function invalidate(string $path): void;

    public function flush(): void;
}