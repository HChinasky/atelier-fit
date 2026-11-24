<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadRequest;
use App\Models\Lead;
use App\Models\MarketingConsent;
use App\Services\Analytics\AnalyticsService;
use App\Services\Lead\LeadNotifier;
use App\Services\Lead\SessionAttribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lead capture. Persists the contact to our own database, then hands it to the
 * LeadNotifier seam for optional external forwarding. The default notifier is a
 * no-op, so right now nothing leaves the system — the lead just lands in `leads`.
 */
class LeadController extends Controller
{
    /**
     * POST /api/lead — store a captured lead.
     */
    public function store(StoreLeadRequest $request, LeadNotifier $notifier, AnalyticsService $analytics): JsonResponse
    {
        $lead = Lead::create([
            'channel' => $request->validated('channel'),
            'contact' => $request->validated('contact'),
            // Every fitting-room lead carries the try-on-user tag.
            'tags' => ['try-on-user'],
            'ip' => $request->ip(),
            // First-touch campaign attribution from the session (A1).
            ...SessionAttribution::forLead($request),
        ]);

        // Analytics identity: from here on this visitor is the lead's
        // pseudonymous hash, and the anonymous pre-contact events are aliased
        // onto it. The contact value itself never reaches the analytics layer.
        $analytics->aliasLead($lead);
        $analytics->capture('contact_submitted', [
            'channel' => $lead->channel,
            'marketing_opt_in' => $request->boolean('marketing_opt_in'),
        ], $analytics->leadDistinctId($lead->id));

        // Marketing permission, if it was actually given. Recorded separately from
        // the contact so the two purposes stay distinguishable: no row here means
        // this contact may be used to run the service and for service messages
        // about it, and for nothing else.
        if ($request->boolean('marketing_opt_in')) {
            MarketingConsent::create([
                'lead_id' => $lead->id,
                'channel' => $lead->channel,
                'consent_version' => (string) config('consent.marketing.version'),
                'consented_at' => now(),
                'ip' => $request->ip(),
            ]);
        }

        // External forwarding is best-effort: the lead is already saved, so a
        // notifier failure (or absence) must never lose it. Swallow + log.
        try {
            $notifier->notify($lead);
        } catch (Throwable $e) {
            Log::warning('[lead] external notification failed; lead is still stored', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'lead_id' => $lead->id,
        ], 201);
    }
}
