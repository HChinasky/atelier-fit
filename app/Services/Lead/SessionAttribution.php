<?php

namespace App\Services\Lead;

use App\Http\Middleware\TrackAttribution;
use Illuminate\Http\Request;

/**
 * The first-touch campaign attribution TrackAttribution parked in the session,
 * narrowed to the lead columns. A session value is still input, so only the
 * guarded keys are read and each is cut to the column's width.
 *
 * Shared by every place a lead is written — the try-on lead capture and the
 * pre-launch waiting list — so "where did this contact come from" is answered
 * the same way wherever the contact was left.
 */
final class SessionAttribution
{
    /**
     * @var list<string>
     */
    private const COLUMNS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
        'utm_term', 'landing_path', 'referrer_host',
    ];

    /**
     * @return array<string, string|null>
     */
    public static function forLead(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        $touch = (array) $request->session()->get(TrackAttribution::SESSION_FIRST, []);

        $attribution = [];

        foreach (self::COLUMNS as $column) {
            $value = $touch[$column] ?? null;
            $attribution[$column] = is_string($value) && $value !== '' ? mb_substr($value, 0, 255) : null;
        }

        return $attribution;
    }
}
