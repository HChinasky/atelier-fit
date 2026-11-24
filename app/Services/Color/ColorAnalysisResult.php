<?php

namespace App\Services\Color;

/**
 * What /color-analysis returned: the engine's own contract, plus the rendered
 * debug views as raw PNG bytes keyed by view name.
 *
 * The result array is deliberately kept whole and unreshaped. The engine owns
 * that schema; picking it apart here would mean changing this class every time
 * the engine grows a field, and the admin page reads it as measurements anyway.
 */
class ColorAnalysisResult
{
    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $images  view name => PNG bytes
     */
    public function __construct(
        public readonly array $result,
        public readonly array $images = [],
    ) {}

    public function status(): string
    {
        $status = $this->result['status'] ?? null;

        return is_string($status) ? $status : 'failed';
    }

    public function rejectReason(): ?string
    {
        $reason = $this->result['reject_reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    public function rejected(): bool
    {
        return $this->status() === 'rejected';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function section(string $key): ?array
    {
        $value = $this->result[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * The best season's slug, or null when the engine did not place one.
     *
     * A rejected photo has no seasons, and so can a measured one: an axis the
     * engine could not compute at all is better reported as no season than as a
     * season derived from defaults.
     */
    public function topSeason(): ?string
    {
        $seasons = $this->section('seasons');
        $slug = $seasons[0]['slug'] ?? null;

        return is_string($slug) ? $slug : null;
    }

    public function algoVersion(): ?string
    {
        $version = $this->result['algo_version'] ?? null;

        return is_string($version) ? $version : null;
    }

    /**
     * Whether the engine's sclera target had been calibrated when this ran.
     * A property of the result, not of today's configuration: the same photo
     * measured after calibration answers differently.
     */
    public function targetCalibrated(): ?bool
    {
        $value = $this->result['white_balance']['target_calibrated'] ?? null;

        return is_bool($value) ? $value : null;
    }
}
