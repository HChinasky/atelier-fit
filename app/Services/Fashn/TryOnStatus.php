<?php

namespace App\Services\Fashn;

/**
 * Snapshot of a FASHN prediction's state, returned by TryOnProvider::status().
 * Decoupled from the raw HTTP body so the job reasons in domain terms.
 */
final readonly class TryOnStatus
{
    /**
     * @param  list<string>  $outputUrls  Result image URLs (present when completed).
     * @param  string|null  $error  Runtime error message (present when failed).
     */
    public function __construct(
        public string $status,
        public array $outputUrls = [],
        public ?string $error = null,
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Whether polling should stop (completed or failed).
     */
    public function isTerminal(): bool
    {
        return $this->isCompleted() || $this->isFailed();
    }
}
