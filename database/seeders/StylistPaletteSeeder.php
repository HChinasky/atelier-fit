<?php

namespace Database\Seeders;

use App\Models\Season;
use App\Models\SeasonColor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Load the stylist's own season palettes.
 *
 *     php artisan db:seed --class=Database\\Seeders\\StylistPaletteSeeder
 *
 * Not wired into DatabaseSeeder, like SeasonPaletteSeeder: writing colours the
 * members will see should be somebody typing a command, not a side effect of
 * deploying. After it, re-run `catalog:measure-colors --rematch` — which pieces
 * suit a season is decided against these colours.
 *
 * What it does, per season and per group the file provides (neutrals, base,
 * accents — her chart has no "avoid" colours):
 *
 *   - writes the colours as source='stylist', is_draft=false;
 *   - deletes the generator's rows in that group, so the page shows her colours
 *     and not hers plus a draft's. SeasonPaletteSeeder, run again, leaves such a
 *     group alone for the same reason;
 *   - drops stylist rows of the same group beyond what the file now holds.
 *
 * Groups the file does not mention (avoid) are not touched. Idempotent: rows
 * are keyed on (season, group, sort).
 */
class StylistPaletteSeeder extends Seeder
{
    public const DATA_PATH = 'seeders/data/stylist_palettes.json';

    public function run(): void
    {
        $document = $this->document();

        DB::transaction(function () use ($document) {
            foreach ($document['seasons'] as $entry) {
                $this->seedSeason($entry);
            }
        });

        $this->command?->info(sprintf(
            'Seeded the stylist palettes for %d seasons. Generator rows in those groups were replaced.',
            count($document['seasons']),
        ));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function seedSeason(array $entry): void
    {
        $season = Season::updateOrCreate(
            ['slug' => $entry['slug']],
            ['sort' => $entry['sort'] ?? 0],
        );

        foreach ($entry['groups'] as $group) {
            $kept = [];

            foreach ($group['colors'] as $color) {
                $row = SeasonColor::updateOrCreate(
                    [
                        'season_id' => $season->id,
                        'group' => $group['name'],
                        'sort' => $color['sort'],
                        'source' => SeasonColor::SOURCE_STYLIST,
                    ],
                    [
                        'hex' => strtoupper($color['hex']),
                        'lab_l' => $color['lab']['L'],
                        'lab_a' => $color['lab']['a'],
                        'lab_b' => $color['lab']['b'],
                        'is_recommended' => (bool) $group['recommended'],
                        'is_draft' => false,
                    ],
                );

                $kept[] = $row->id;
            }

            // Her colours replace the draft's in this group, and a shorter
            // list than last time leaves no stale tail.
            SeasonColor::query()
                ->where('season_id', $season->id)
                ->where('group', $group['name'])
                ->whereNotIn('id', $kept)
                ->delete();
        }
    }

    /**
     * @return array{seasons: list<array<string, mixed>>}
     */
    private function document(): array
    {
        $path = database_path(self::DATA_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Missing {$path}.");
        }

        $document = json_decode((string) file_get_contents($path), true);

        if (! is_array($document) || ! isset($document['seasons']) || ! is_array($document['seasons'])) {
            throw new RuntimeException("{$path} is not a palette file the seeder understands.");
        }

        return $document;
    }
}
