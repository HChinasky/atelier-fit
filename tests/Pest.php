<?php

use App\Models\ColorProfile;
use App\Models\User;
use Database\Seeders\SeasonPaletteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// Feature tests run against the dedicated Postgres test database. RefreshDatabase
// migrates a fresh schema once per run, then wraps every test in a transaction
// that is rolled back afterwards — clean, deterministic state with no leakage.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Load the draft season palettes.
 *
 * Shared rather than local to one file because two suites need it: the seeder's
 * own tests, and the page's — the page cannot show a palette nobody seeded.
 * Never run automatically: a test that wants palettes says so.
 */
/**
 * A stylist account. The flag is not fillable, so factories set it by state.
 */
function stylist(): User
{
    return User::factory()->create(['is_stylist' => true]);
}

/**
 * A measured, classified colour profile — the only kind that can be labelled.
 */
function measured(array $state = []): ColorProfile
{
    return ColorProfile::factory()->create(
        ColorProfile::factory()->classified()->raw($state)
    );
}

function seedPalettes(): void
{
    (new SeasonPaletteSeeder)->run();
}

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
