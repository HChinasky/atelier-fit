<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\MarketingConsent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Withdraws marketing permission for a contact.
 *
 * Unsubscribing has to be free and has to work; until the interface offers a
 * one-click link, this is how an operator honours "stop sending me things" the
 * moment it arrives, rather than making the person ask twice.
 *
 * It stamps the consent records instead of deleting them: a deleted record reads
 * as "never subscribed", which loses the very fact that matters — they were
 * subscribed, and then they opted out. The contact itself is untouched; opting out
 * of marketing is not a request to erase everything (that is app:delete-user-data).
 */
class UnsubscribeContactCommand extends Command
{
    protected $signature = 'app:unsubscribe-contact
                            {contact : The phone number or email address to suppress}
                            {--dry-run : Report what would be suppressed without changing anything}';

    protected $description = 'Withdraw marketing permission for a contact (free, immediate, permanent)';

    public function handle(): int
    {
        $contact = trim((string) $this->argument('contact'));

        if ($contact === '') {
            $this->error('Give the phone number or email address to suppress.');

            return self::FAILURE;
        }

        // Matched on the contact VALUE across every lead that carries it: the same
        // person may have arrived several times, and a partial unsubscribe is not
        // an unsubscribe.
        $leadIds = Lead::query()->where('contact', $contact)->pluck('id');

        if ($leadIds->isEmpty()) {
            $this->warn('No contact found — nothing to suppress.');

            return self::SUCCESS;
        }

        $consents = MarketingConsent::query()
            ->whereIn('lead_id', $leadIds)
            ->subscribed()
            ->get();

        if ($consents->isEmpty()) {
            $this->line('Nothing to do: this contact has no active marketing permission.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn(sprintf('DRY RUN — %d active permission(s) would be withdrawn.', $consents->count()));

            return self::SUCCESS;
        }

        $consents->each(fn (MarketingConsent $consent) => $consent->unsubscribe());

        $this->info(sprintf('Withdrawn: %d marketing permission(s). The contact itself is kept.', $consents->count()));

        // The act, not the contact — an unsubscribe log that names people would be
        // its own privacy problem.
        Log::info('[privacy] marketing permission withdrawn', ['permissions' => $consents->count()]);

        return self::SUCCESS;
    }
}
