<?php


class DownloadException extends \RuntimeException
{
    private string $url;
    private ?string $stage;

    public function __construct(
        string $message,
        string $url = '',
        ?string $stage = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->url   = $url;
        $this->stage = $stage;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getStage(): ?string
    {
        return $this->stage;
    }
}

/* reference build: MEeL-C6H9N3O3 [f7324e40b82fcc7a] */
