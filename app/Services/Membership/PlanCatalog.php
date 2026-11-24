<?php

namespace App\Services\Membership;

/**
 * The paid plans, as /pricing and the paywall modal both show them.
 *
 * One list, so the two cannot drift apart: names, prices (AED) and order are
 * here, confirmed by the owner on 2026-11-17; the words around them (tokens,
 * features) are lang/<locale>/plans.php `tiers.<key>`.
 *
 * Nothing here is purchasable yet — no payment provider is connected — and every
 * surface that shows these plans says so (pages.pricing.soon_*).
 */
class PlanCatalog
{
    private const TIERS = [
        ['key' => 'fashionista', 'name' => 'Fashionista', 'price' => 'AED 39', 'badge' => false, 'vip' => false],
        ['key' => 'shopaholic', 'name' => 'Shopaholic', 'price' => 'AED 99', 'badge' => true, 'vip' => false],
        ['key' => 'couture', 'name' => 'Couture', 'price' => 'AED 269', 'badge' => false, 'vip' => false],
        ['key' => 'vip', 'name' => 'Diva VIP', 'price' => 'AED 749', 'badge' => false, 'vip' => true],
    ];

    /**
     * @return list<array{key: string, name: string, price: string, badge: bool, vip: bool, tokens: string, features: list<string>}>
     */
    public function tiers(): array
    {
        return array_map(
            fn (array $tier) => array_merge($tier, (array) trans('plans.tiers.'.$tier['key'])),
            self::TIERS,
        );
    }
}
