<?php

namespace App\Services\Lead;

use App\Models\Lead;

/**
 * Seam for pushing a captured lead to an external funnel/messenger. The product
 * has no such tool chosen yet, so the default binding is a no-op
 * (NullLeadNotifier). When a tool is picked, a concrete implementation replaces
 * the container binding — the controller and the lead write stay untouched.
 *
 * A notifier should be best-effort from the caller's point of view: the lead is
 * already persisted before notify() runs, so a failure here must never lose it.
 */
interface LeadNotifier
{
    /**
     * Notify the external funnel about a new lead. Implementations that succeed
     * should stamp the lead's notified_at; the no-op default leaves it null.
     */
    public function notify(Lead $lead): void;
}
