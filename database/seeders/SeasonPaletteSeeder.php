<?php

namespace Database\Seeders;

use App\Models\Season;
use App\Models\SeasonColor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Load the DRAFT season palettes.
 *
 *     php artisan db:seed --class=Database\\Seeders\\SeasonPaletteSeeder
 *
 * Not wired into DatabaseSeeder and not run by `migrate`. Deliberately: this
 * writes 288 rows of colour nobody has approved, and that should be somebody
 * typing a command, not a side effect of deploying.
 *
 * **Idempotent, and careful about whose rows it touches.** It rewrites
 * source='generator' rows and deletes generator rows the file no longer
 * mentions. It never reads, edits or deletes a source='stylist' row — those are
 * a person's judgement, and a seeder that could overwrite one would make
 * "re-run the seeder" a sentence nobody could say without checking first.
 *
 * The file it reads comes from the engine:
 *
 *     cd services/color-engine
 *     color-engine palettes draft --out ../../database/seeders/data/season_palettes.json
 */
class SeasonPaletteSeeder extends Seeder
{
    public const DATA_PATH = 'seeders/data/season_palettes.json';

    public function run(): void
    {
        $document = $this->document();

        DB::transaction(function () use ($document) {
            foreach ($document['seasons'] as $entry) {
                $this->seedSeason($entry, (bool) ($document['draft'] ?? true));
            }
        });

        $this->command?->info(sprintf(
            'Seeded %d seasons from generator %s (%s). Stylist-chosen colours were not touched.',
            count($document['seasons']),
            $document['generator_version'] ?? 'unknown',
            ($document['draft'] ?? true) ? 'DRAFT — not checked by a stylist' : 'approved',
        ));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function seedSeason(array $entry, bool $draft): void
    {
        $season = Season::updateOrCreate(
            ['slug' => $entry['slug']],
            ['sort' => $entry['sort'] ?? 0],
        );

        $kept = [];

        foreach ($entry['groups'] as $group) {
            // A group the stylist has filled stays hers: writing the draft's
            // colours back beside them would show both on the page.
            $stylistOwns = SeasonColor::query()
                ->where('season_id', $season->id)
                ->where('group', $group['name'])
                ->where('source', SeasonColor::SOURCE_STYLIST)
                ->exists();

            if ($stylistOwns) {
                continue;
            }

            foreach ($group['colors'] as $color) {
                // Keyed on (season, group, sort) rather than on the hex: a
                // regenerated palette changes the colour at a position, and
                // keying on the colour would insert a new row and orphan the old
                // one every time a rule moved by a degree.
                $row = SeasonColor::updateOrCreate(
                    [
                        'season_id' => $season->id,
                        'group' => $group['name'],
                        'sort' => $color['sort'],
                        'source' => SeasonColor::SOURCE_GENERATOR,
                    ],
                    [
                        'hex' => strtoupper($color['hex']),
                        'lab_l' => $color['lab']['L'],
                        'lab_a' => $color['lab']['a'],
                        'lab_b' => $color['lab']['b'],
                        'is_recommended' => (bool) $group['recommended'],
                        'is_draft' => $draft,
                    ],
                );

                $kept[] = $row->id;
            }
        }

        // Generator rows this file no longer produces — a group that shrank, a
        // season that lost one. Scoped to source='generator' twice over, here
        // and in the key above, because the one thing this seeder must never do
        // is delete somebody's work.
        $season->colors()
            ->where('source', SeasonColor::SOURCE_GENERATOR)
            ->whereNotIn('id', $kept)
            ->delete();
    }

    /**
     * @return array{draft?: bool, generator_version?: string, seasons: list<array<string, mixed>>}
     */
    private function document(): array
    {
        $path = database_path(self::DATA_PATH);

        if (! is_file($path)) {
            throw new RuntimeException(
                "Missing {$path}. Generate it with: color-engine palettes draft --out {$path}"
            );
        }

        $document = json_decode((string) file_get_contents($path), true);

        if (! is_array($document) || ! isset($document['seasons']) || ! is_array($document['seasons'])) {
            throw new RuntimeException("{$path} is not a palette file the seeder understands.");
        }

        return $document;
    }
}
