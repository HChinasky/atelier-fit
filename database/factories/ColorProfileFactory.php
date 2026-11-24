<?php

namespace Database\Factories;

use App\Models\ColorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ColorProfile>
 */
class ColorProfileFactory extends Factory
{
    /**
     * Default state: freshly queued, nothing measured yet — what the controller
     * creates before the job runs.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'person_ref' => null,
            'lighting' => null,
            'status' => ColorProfile::STATUS_PENDING,
            'reject_reason' => null,
            'samples' => null,
            'zones' => null,
            'white_balance' => null,
            'axes' => null,
            'contrast' => null,
            'season' => null,
            'seasons' => null,
            'classifier' => null,
            'confidence' => null,
            'quiz' => null,
            'algo_version' => null,
            'target_calibrated' => null,
            'source' => 'auto',
            'error' => null,
        ];
    }

    /**
     * A measured profile. The numbers are shaped like the engine's contract —
     * a sample carries L*, a*, b*, C*, h° and a pixel count — so anything
     * reading them back in a test sees the real shape.
     */
    public function measured(): static
    {
        return $this->state(fn (): array => [
            'status' => ColorProfile::STATUS_OK,
            'samples' => [
                'skin' => $this->sample(70.1, 12.4, 16.8),
                'skin_parts' => [
                    'left_cheek' => $this->sample(69.4, 13.1, 17.2),
                    'right_cheek' => $this->sample(70.8, 12.0, 16.4),
                    'forehead' => $this->sample(73.2, 10.9, 15.1),
                ],
                'hair' => $this->sample(24.6, 3.2, 5.1),
                'iris' => $this->sample(41.3, -1.8, -4.2),
                'sclera' => $this->sample(84.7, 0.6, 3.4),
            ],
            'white_balance' => [
                'method' => 'sclera',
                'single_eye' => false,
                'correction_strength' => 0.0184,
                'estimated_cct' => 4180.0,
                // False everywhere until the engine's sclera target is measured
                // on a real reference set. The page says so in a banner.
                'target_calibrated' => false,
            ],
            'axes' => ['temperature' => null, 'depth' => null, 'chroma' => null],
            'algo_version' => '0.1.0',
            'target_calibrated' => false,
        ]);
    }

    /**
     * Measured AND classified: the state the page's season block exists for.
     *
     * Draft, because the engine's classifier is: every anchor point and
     * prototype behind these numbers was reasoned rather than measured against
     * a labelled face. A test that pretended otherwise would be testing a page
     * the site does not have.
     */
    public function classified(
        string $season = 'deep_winter',
        string $runnerUp = 'deep_autumn',
        float $confidence = 0.62,
    ): static {
        return $this->measured()->state(fn (): array => [
            'axes' => ['temperature' => -0.41, 'depth' => 0.68, 'chroma' => 0.12],
            'contrast' => [
                'value' => 0.63,
                'hair_skin' => 45.5,
                'eye_skin' => 28.8,
                'iris_sclera' => 43.4,
            ],
            'season' => $season,
            'seasons' => [
                ['slug' => $season, 'score' => 0.2731],
                ['slug' => $runnerUp, 'score' => 0.1904],
            ],
            'classifier' => [
                'version' => 'draft-1',
                'draft' => true,
                'low_confidence' => $confidence < 0.4,
                'exclusions' => [],
                // The engine publishes the multipliers behind the confidence,
                // and the page shows them. A factory that left them out would
                // let that section pass its tests while rendering nothing.
                'confidence' => [
                    'from_margin' => round($confidence / 0.72, 4),
                    'features_factor' => 0.9,
                    'quality_factor' => 0.8,
                    'final' => $confidence,
                ],
            ],
            'confidence' => $confidence,
        ]);
    }

    /**
     * One shot in a calibration set: the same person under a named light.
     */
    public function forPerson(string $ref, string $lighting = 'daylight'): static
    {
        return $this->state(fn (): array => ['person_ref' => $ref, 'lighting' => $lighting]);
    }

    /**
     * The engine looked and said no. A normal outcome, not an error.
     */
    public function rejected(string $reason = 'no_face'): static
    {
        return $this->state(fn (): array => [
            'status' => ColorProfile::STATUS_REJECTED,
            'reject_reason' => $reason,
            'algo_version' => '0.1.0',
            'target_calibrated' => false,
        ]);
    }

    /**
     * Our environment broke — the service was down, the models were missing.
     * Never a statement about the photograph.
     */
    public function failed(string $error = 'Colour analysis service is unreachable.'): static
    {
        return $this->state(fn (): array => [
            'status' => ColorProfile::STATUS_FAILED,
            'error' => $error,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => ['status' => ColorProfile::STATUS_PROCESSING]);
    }

    /**
     * @return array<string, float|int>
     */
    private function sample(float $lightness, float $a, float $b): array
    {
        return [
            'L' => $lightness,
            'a' => $a,
            'b' => $b,
            'C' => round(sqrt($a ** 2 + $b ** 2), 3),
            'h' => round(fmod(rad2deg(atan2($b, $a)) + 360.0, 360.0), 3),
            'pixels' => fake()->numberBetween(200, 9000),
            'L_iqr' => round(fake()->randomFloat(2, 1, 9), 3),
        ];
    }
}
