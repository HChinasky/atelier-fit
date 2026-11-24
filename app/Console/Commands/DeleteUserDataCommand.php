<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use App\Services\Otp\EmailAddress;
use App\Services\Privacy\PersonalDataEraser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Carries out an erasure / consent-withdrawal request for one person.
 *
 * The privacy policy grants a right to deletion, and until the account area has a
 * self-service button, that right is exercised by a human answering the privacy
 * mailbox. This command is what makes it real: without it, honouring a request
 * means hand-editing rows and guessing at storage keys, which is how data gets
 * missed.
 *
 * Identify the person by email (their account and/or a captured contact), by
 * phone (a WhatsApp contact left on the guest form), or by a lead id. Whatever
 * matches is erased: stored photos and result images first, then generations,
 * leads, one-time codes, the credit ledger and the account.
 *
 * welcome_grants is NOT erased. It holds an irreversible hash of an e-mail
 * address, is kept forever by design so the one-off welcome bonus cannot be
 * claimed twice, and the privacy policy discloses exactly that. The command says
 * so on every run rather than leaving the operator to wonder whether it was
 * missed.
 */
class DeleteUserDataCommand extends Command
{
    protected $signature = 'app:delete-user-data
                            {--email= : E-mail address of the account and/or a captured contact}
                            {--phone= : E.164 phone number captured as a WhatsApp contact}
                            {--lead= : A single lead id}
                            {--dry-run : Report what would be erased without erasing anything}
                            {--force : Skip the confirmation prompt (for non-interactive use)}';

    protected $description = 'Erase one person\'s data on request (right to deletion / consent withdrawal)';

    public function handle(PersonalDataEraser $eraser, AnalyticsService $analytics): int
    {
        $phone = $this->stringOption('phone');
        $email = $this->stringOption('email');
        $leadId = $this->stringOption('lead');

        if ($phone === null && $email === null && $leadId === null) {
            $this->error('Nothing to identify the person by. Pass --email, --phone or --lead.');

            return self::FAILURE;
        }

        $email = $email === null ? null : EmailAddress::normalize($email);
        $user = $email === null ? null : User::query()->where('email', $email)->first();

        // Leads are matched on the contact value regardless of channel, so an email
        // request finds email leads and a phone request finds WhatsApp ones — plus
        // any lead already owned by the matched account.
        $leads = Lead::query()
            ->when($phone !== null, fn ($q) => $q->orWhere(fn ($q) => $q->where('channel', 'whatsapp')->where('contact', $phone)))
            ->when($email !== null, fn ($q) => $q->orWhere(fn ($q) => $q->where('channel', 'email')->where('contact', $email)))
            ->when($leadId !== null, fn ($q) => $q->orWhere('id', $leadId))
            ->when($user !== null, fn ($q) => $q->orWhere('user_id', $user->id))
            ->get();

        if ($user === null && $leads->isEmpty()) {
            $this->warn('No matching account or contact found — nothing to erase.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Account: %s', $user === null ? 'none' : 'yes'));
        $this->line(sprintf('Leads:   %d', $leads->count()));

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — nothing was erased.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Erase this data permanently?', false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $eraser->resetCounters();

        // Analytics identities must be collected BEFORE erasure: the distinct
        // id is derived from the lead id, and after eraseUser/eraseLead the
        // rows (and with them the ids) are gone.
        $analyticsIds = $leads->pluck('id');
        if ($user !== null) {
            $analyticsIds = $analyticsIds->merge(
                Lead::query()->where('user_id', $user->id)->pluck('id'),
            );
        }
        $analyticsIds = $analyticsIds->unique()
            ->map(fn ($id) => $analytics->leadDistinctId($id))
            ->values();

        $erasedLeads = 0;
        $erasedGenerations = 0;

        // The account first: it takes its own leads with it, so the standalone pass
        // below only has to deal with guest leads that were never linked.
        if ($user !== null) {
            $counts = $eraser->eraseUser($user);
            $erasedLeads += $counts['leads'];
            $erasedGenerations += $counts['generations'];
        }

        foreach ($leads as $lead) {
            if ($lead->exists && $lead->fresh() !== null) {
                $erasedGenerations += $eraser->eraseLead($lead);
                $erasedLeads++;
            }
        }

        $failed = $eraser->failedObjectDeletes();

        $this->info(sprintf(
            'Erased: account=%s leads=%d generations=%d',
            $user === null ? 'no' : 'yes',
            $erasedLeads,
            $erasedGenerations,
        ));

        if ($failed > 0) {
            $this->warn(sprintf('%d stored object(s) could not be deleted; the storage lifecycle rule remains the backstop.', $failed));
        }

        // Analytics erasure (Этап A4): remove the pseudonymous person(s) from
        // PostHog too. Idempotent (an unknown id is a clean no-op) and NEVER
        // blocking — a PostHog hiccup only produces a warning; the database and
        // storage erasure above already happened.
        if ($analytics->enabled()) {
            $missed = $analyticsIds->reject(fn (string $id) => $analytics->deletePerson($id))->count();

            if ($missed > 0) {
                $this->warn(sprintf('%d analytics person(s) could not be confirmed deleted in PostHog — retry later or delete via the PostHog UI.', $missed));
            } else {
                $this->line(sprintf('Analytics: %d pseudonymous person(s) deleted from PostHog.', $analyticsIds->count()));
            }
        }

        $this->line('Kept on purpose: the irreversible e-mail hash in welcome_grants (anti-abuse, disclosed in the privacy policy).');

        // An erasure request must leave an audit trail — of the ACT, never of the
        // data. No phone, email or lead id is logged.
        Log::info('[privacy] erasure request carried out', [
            'account_erased' => $user !== null,
            'leads' => $erasedLeads,
            'generations' => $erasedGenerations,
            'failed_object_deletes' => $failed,
        ]);

        return self::SUCCESS;
    }

    /** A trimmed option value, or null when it was not supplied / was blank. */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
