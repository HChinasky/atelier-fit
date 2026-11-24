<?php

namespace App\Services\Membership;

use App\Services\Credits\CreditService;
use Illuminate\Http\Request;

/**
 * What every account page shares: the plan being viewed, the navigation with
 * its locks, and the real credit balance.
 *
 * Lifted out of MembershipController so a page that lives in a controller of
 * its own (the colour type) draws the same sidebar from the same rules, rather
 * than a copy that drifts.
 *
 * The plan is still the Stage A mock (?plan=, validated against PLANS); the
 * credit balance is the REAL ledger sum. Billing (Stage C) replaces the mock.
 * Outside local/testing there is no plan at all: no switcher, no plan name,
 * and every section open (OPEN_ACCESS) until billing exists.
 */
class AccountShell
{
    /**
     * The phone's bottom tabs (Figma 1872:8303, minus Looks, plus Colours —
     * the owner's call): nav key → icon under public/images/lk-mob. A tab
     * shows only while its nav item does; pages without a tab (looks,
     * stylist) are linked from the profile.
     */
    public const TAB_ICONS = [
        'dashboard' => 'nav-home.svg',
        'color' => 'nav-colours.svg',
        'wardrobe' => 'nav-wardrobe.svg',
        'capsules' => 'nav-capsules.svg',
        'settings' => 'nav-profile.svg',
    ];

    /** Plan catalog: rank drives feature locks (wardrobe/capsules need >= 3). */
    public const PLANS = [
        'fashionista' => ['name' => 'Fashionista', 'price' => 'AED 39', 'rank' => 1, 'total' => 20, 'left' => 12],
        'shopaholic' => ['name' => 'Shopaholic', 'price' => 'AED 99', 'rank' => 2, 'total' => 50, 'left' => 34],
        'couture' => ['name' => 'Couture', 'price' => 'AED 269', 'rank' => 3, 'total' => 150, 'left' => 110],
        'vip' => ['name' => 'Diva VIP', 'price' => 'AED 749', 'rank' => 4, 'total' => 300, 'left' => 240],
    ];

    /** What a real member gets while there is no billing: nothing locked, no plan to name. */
    public const OPEN_ACCESS = ['name' => null, 'price' => null, 'rank' => 3, 'total' => 0, 'left' => 0];

    public function __construct(private readonly CreditService $credits) {}

    /**
     * The ?plan= mock and its switcher: a development aid, on in
     * local/testing only; never on a server.
     */
    public static function planPreview(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /**
     * @return array{plan: ?string, planData: array, section: string, navItems: array, tokensLeft: int, avatarUrl: ?string}
     */
    public function shared(Request $request, string $section): array
    {
        $plan = null;
        if (self::planPreview()) {
            $plan = $request->query('plan');
            if (! is_string($plan) || ! array_key_exists($plan, self::PLANS)) {
                $plan = 'shopaholic';
            }
        }
        $planData = $plan === null ? self::OPEN_ACCESS : self::PLANS[$plan];
        $rank = $planData['rank'];

        $navItems = [
            ['key' => 'dashboard', 'label' => __('account.nav.dashboard'), 'short' => __('account.nav.dashboard_short'), 'route' => 'membership.account.dashboard', 'locked' => false],
            ['key' => 'looks', 'label' => __('account.nav.looks'), 'short' => __('account.nav.looks_short'), 'route' => 'membership.account.looks', 'locked' => false],
        ];
        // The colour type exists only while its engine is switched on: the
        // routes 404 without the flag, so the nav must not point at them.
        if (config('features.color_analysis')) {
            $navItems[] = ['key' => 'color', 'label' => __('account.nav.color'), 'short' => __('account.nav.color_short'), 'route' => 'membership.account.color', 'locked' => false];
        }
        $navItems[] = ['key' => 'wardrobe', 'label' => __('account.nav.wardrobe'), 'short' => __('account.nav.wardrobe_short'), 'route' => 'membership.account.wardrobe', 'locked' => $rank < 3];
        $navItems[] = ['key' => 'capsules', 'label' => __('account.nav.capsules'), 'short' => __('account.nav.capsules_short'), 'route' => 'membership.account.capsules', 'locked' => $rank < 3];
        if ($plan === 'vip') {
            $navItems[] = ['key' => 'vip', 'label' => __('account.nav.vip'), 'short' => __('account.nav.vip_short'), 'route' => 'membership.account.vip', 'locked' => false];
        }
        $navItems[] = ['key' => 'settings', 'label' => __('account.nav.settings'), 'short' => __('account.nav.settings_short'), 'route' => 'membership.account.settings', 'locked' => false];

        return [
            'plan' => $plan,
            'planData' => $planData,
            'section' => $section,
            'navItems' => $navItems,
            'tokensLeft' => $this->credits->balance($request->user()),
            // The key's own name busts the cache when the picture changes.
            'avatarUrl' => $request->user()?->avatar_path
                ? route('membership.account.avatar', ['v' => substr(md5($request->user()->avatar_path), 0, 10)])
                : null,
        ];
    }
}
