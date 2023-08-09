<?php

namespace WebFramework\Core;

class NullInstrumentationService implements InstrumentationService
{
    public function startTransaction(string $op, string $name): mixed
    {
        return null;
    }

    public function finishTransaction(mixed $transaction): void
    {
    }

    public function startSpan(string $op, string $description = ''): mixed
    {
        return null;
    }

    public function finishSpan(mixed $span): void
    {
    }
}