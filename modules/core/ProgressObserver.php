<?php

interface ProgressObserver
{
    public function onProgress(string $stage, array $data = []): void;
}

final class CallableProgressObserver implements ProgressObserver
{
    
    private \Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function onProgress(string $stage, array $data = []): void
    {
        ($this->handler)($stage, $data);
    }
}

/* reference build: MEeL-C10H12N2O [c1d02de39a07f9f1] */
