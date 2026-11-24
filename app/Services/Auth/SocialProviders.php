<?php

namespace App\Services\Auth;

use App\Models\SocialAccount;

/**
 * Which sign-in providers this deployment can actually use.
 *
 * A provider is ON when its client id is configured and OFF otherwise — the
 * button is not drawn and its routes answer 404, so a deploy without the
 * Apple keys (they come from the client's developer account, not from us)
 * simply offers Google and e-mail, instead of a button that errors.
 */
final class SocialProviders
{
    /** @var list<string> */
    public const ALL = [SocialAccount::PROVIDER_GOOGLE, SocialAccount::PROVIDER_APPLE];

    /** @return list<string> */
    public static function enabled(): array
    {
        return array_values(array_filter(self::ALL, fn (string $p) => self::isEnabled($p)));
    }

    public static function isEnabled(string $provider): bool
    {
        return in_array($provider, self::ALL, true)
            && trim((string) config("services.{$provider}.client_id")) !== '';
    }
}
