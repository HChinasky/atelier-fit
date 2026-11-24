<?php

namespace App\Services\Lead;

use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Default LeadNotifier: does nothing. No external tool is configured yet, so the
 * lead simply stays in our own database. It makes NO network calls and does NOT
 * stamp notified_at (the lead remains "not yet pushed"). The real notifier is a
 * later binding swap. We only record a debug breadcrumb so it is visible that a
 * lead was captured but not forwarded.
 */
class NullLeadNotifier implements LeadNotifier
{
    public function notify(Lead $lead): void
    {
        Log::debug('[lead] captured but not forwarded — no external notifier configured', [
            'lead_id' => $lead->id,
            'channel' => $lead->channel,
        ]);

        // Intentionally no-op: no HTTP, notified_at left null.
    }
}
