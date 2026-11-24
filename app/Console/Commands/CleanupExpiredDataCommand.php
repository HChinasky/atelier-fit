<?php

namespace App\Console\Commands;

use App\Models\ColorProfile;
use App\Models\Lead;
use App\Models\MarketingConsent;
use App\Models\PhotoConsent;
use App\Models\User;
use App\Models\WelcomeGrant;
use App\Services\Privacy\PersonalDataEraser;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Enforces the published retention periods (config/privacy.php) by actually
 * deleting what has expired. Without this command the privacy policy would be
 * describing behaviour the system does not have: outside the 72h storage
 * lifecycle rule and OTP expiry, nothing else ever expired on its own.
 *
 * Scheduled daily (routes/console.php). Idempotent — a second run in the same day
 * finds nothing left to do — and safe to interrupt, since each record is erased in
 * its own step rather than in one long transaction.
 *
 * A rule whose retention is configured to 0 or less is DISABLED, not instant: a
 * mistyped env keeps data instead of destroying it. Disabled rules are reported on
 * every run so the state cannot pass unnoticed.
 *
 * welcome_grants is never touched here — the hash it stores is deliberately
 * permanent (see PersonalDataEraser).
 */
class CleanupExpiredDataCommand extends Command
{
    protected $signature = 'app:cleanup-expired-data
                            {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Delete personal data whose published retention period has passed';

    public function handle(PersonalDataEraser $eraser): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $eraser->resetCounters();

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be deleted.');
        }

        $report = [
            'leads' => $this->cleanLeads($eraser, $dryRun),
            'accounts' => $this->cleanAccounts($eraser, $dryRun),
            'consents' => $this->cleanConsents($dryRun),
            'color' => $this->cleanColorProfiles($dryRun),
            'marketing' => $this->cleanWithdrawnMarketing($dryRun),
            'welcome_grant_hashes' => $this->cleanWelcomeGrants($dryRun),
            'log_files' => $this->cleanLogs($dryRun),
        ];

        $failed = $eraser->failedObjectDeletes();

        foreach ($report as $what => $count) {
            $this->line(sprintf('%-10s %s', $what.':', $count === null ? 'rule disabled' : $count));
        }

        if ($failed > 0) {
            // Not fatal — the storage lifecycle rule still reaps them — but it must
            // be visible, because "erased" and "expires eventually" are not the same
            // promise.
            $this->warn(sprintf('%d stored object(s) could not be deleted; the storage lifecycle rule remains the backstop.', $failed));
        }

        if (! $dryRun) {
            Log::info('[privacy] retention cleanup finished', $report + ['failed_object_deletes' => $failed]);
        }

        return self::SUCCESS;
    }

    /**
     * Contacts whose retention has run out, counted from the last time the record
     * was touched — its last contact with us, which is what the policy promises.
     *
     * @return int|null null when the rule is disabled
     */
    private function cleanLeads(PersonalDataEraser $eraser, bool $dryRun): ?int
    {
        $cutoff = $this->cutoff('lead_months', fn (int $n) => now()->subMonths($n));

        if ($cutoff === null) {
            return null;
        }

        $query = Lead::query()->where('updated_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        $count = 0;
        $query->cursor()->each(function (Lead $lead) use ($eraser, &$count) {
            $eraser->eraseLead($lead);
            $count++;
        });

        return $count;
    }

    /**
     * Accounts unused for the whole inactivity window. last_active_at is stamped on
     * every sign-in; the fallbacks cover rows predating that column.
     *
     * @return int|null null when the rule is disabled
     */
    private function cleanAccounts(PersonalDataEraser $eraser, bool $dryRun): ?int
    {
        $cutoff = $this->cutoff('account_inactivity_months', fn (int $n) => now()->subMonths($n));

        if ($cutoff === null) {
            return null;
        }

        $query = User::query()->whereRaw(
            'COALESCE(last_active_at, updated_at, created_at) < ?',
            [$cutoff]
        );

        if ($dryRun) {
            return $query->count();
        }

        $count = 0;
        $query->cursor()->each(function (User $user) use ($eraser, &$count) {
            $eraser->eraseUser($user);
            $count++;
        });

        return $count;
    }

    /**
     * Consent records outlive the photo they authorised on purpose (they are the
     * proof the consent existed), but not forever.
     *
     * @return int|null null when the rule is disabled
     */
    private function cleanConsents(bool $dryRun): ?int
    {
        $cutoff = $this->cutoff('consent_years', fn (int $n) => now()->subYears($n));

        if ($cutoff === null) {
            return null;
        }

        $query = PhotoConsent::query()->where('created_at', '<', $cutoff);

        return $dryRun ? $query->count() : $query->delete();
    }

    /**
     * Colour profiles past their retention period.
     *
     * The photo and the rendered views are long gone by now — those live in
     * hours, swept by app:purge-expired-photos — so what this deletes is the
     * measurements: skin, hair, iris and sclera in CIELAB, against a user id.
     * Not a picture, but still a measurement of somebody's body, which is why
     * it has a period at all rather than living forever by default.
     *
     * The cascade on color_profile_images takes any surviving image rows with
     * it, so this can never leave a row pointing at an object that the photo
     * sweep has already deleted.
     *
     * @return int|null null when the rule is disabled
     */
    private function cleanColorProfiles(bool $dryRun): ?int
    {
        $cutoff = $this->cutoff('color_profile_days', fn (int $n) => now()->subDays($n));

        if ($cutoff === null) {
            return null;
        }

        $query = ColorProfile::query()->where('created_at', '<', $cutoff);

        return $dryRun ? $query->count() : $query->delete();
    }

    /**
     * Withdrawn marketing permissions. An ACTIVE permission is left alone — it is
     * in force, and deleting it would erase the very thing that authorises the
     * next message. Only records that have been opted out of age away, counted
     * from the withdrawal.
     *
     * @return int|null null when the rule is disabled
     */
    private function cleanWithdrawnMarketing(bool $dryRun): ?int
    {
        $cutoff = $this->cutoff('marketing_consent_years', fn (int $n) => now()->subYears($n));

        if ($cutoff === null) {
            return null;
        }

        $query = MarketingConsent::query()
            ->whereNotNull('unsubscribed_at')
            ->where('unsubscribed_at', '<', $cutoff);

        return $dryRun ? $query->count() : $query->delete();
    }

    /**
     * Welcome-grant identity hashes whose account is long gone.
     *
     * Only rows stamped with account_removed_at are considered: a hash belonging
     * to a live account is what stops that account collecting the bonus twice,
     * and must never be swept.
     *
     * @return int|null null when the rule is disabled
     */
    private function cleanWelcomeGrants(bool $dryRun): ?int
    {
        $cutoff = $this->cutoff('welcome_grant_months', fn (int $n) => now()->subMonths($n));

        if ($cutoff === null) {
            return null;
        }

        $query = WelcomeGrant::query()
            ->whereNotNull('account_removed_at')
            ->where('account_removed_at', '<', $cutoff);

        return $dryRun ? $query->count() : $query->delete();
    }

    /**
     * Rotated application logs. Only files are considered, and only by age — the
     * log currently being written is by definition young enough to survive.
     *
     * @return int|null null when the rule is disabled
     */
    private function cleanLogs(bool $dryRun): ?int
    {
        $cutoff = $this->cutoff('log_days', fn (int $n) => now()->subDays($n));

        if ($cutoff === null) {
            return null;
        }

        $directory = storage_path('logs');

        if (! File::isDirectory($directory)) {
            return 0;
        }

        $count = 0;

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'log') {
                continue;
            }

            if (Carbon::createFromTimestamp($file->getMTime())->greaterThanOrEqualTo($cutoff)) {
                continue;
            }

            if (! $dryRun) {
                File::delete($file->getPathname());
            }

            $count++;
        }

        return $count;
    }

    /**
     * Resolve a retention setting into a cutoff instant, or null when the rule is
     * switched off (0 or less). Reporting the disabled state here keeps every rule
     * honest about why it did nothing.
     *
     * @param  callable(int): Carbon  $subtract
     */
    private function cutoff(string $setting, callable $subtract): ?Carbon
    {
        $value = (int) config('privacy.retention.'.$setting);

        if ($value <= 0) {
            $this->warn(sprintf('privacy.retention.%s is %d — rule disabled, nothing deleted for it.', $setting, $value));

            return null;
        }

        return $subtract($value);
    }
}
