<?php

namespace App\Http\Controllers;

use App\Services\Generation\MemberLead;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The single virtual fitting-room page (Phase 2). Server-rendered Blade; all
 * interactivity (catalog fetch, photo preview, selection) is progressive
 * enhancement via Alpine + Dropzone on the client. This controller only renders
 * the screen — the catalog data comes from GET /api/catalog.
 *
 * Stage B3: for a logged-in MEMBER (feature flag on) the render also mints
 * their owned lead + ownership token (MemberLead), so the page can skip the
 * contact modal (the contact was collected at signup — still before any
 * result is shown) and the generate API can debit their credit ledger.
 * Guests get null and the page behaves exactly as before.
 */
class FittingRoomController extends Controller
{
    public function show(Request $request, MemberLead $memberLead): View
    {
        return view('ai-fitting-room', [
            'memberLead' => $memberLead->mint($request->user(), (string) $request->ip()),
        ]);
    }
}
