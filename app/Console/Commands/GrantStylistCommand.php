<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Otp\EmailAddress;
use Illuminate\Console\Command;

/**
 * Grants or revokes the stylist flag on an account.
 *
 * Same shape and the same reasoning as {@see GrantAdminCommand}: no path from
 * the interface, because a self-service route to privilege would be an attack
 * surface built for a tool with a handful of users, and the bar for setting it
 * is shell access to the server — which is the bar for deploying anyway.
 *
 * The address is never logged and never echoed back. It identifies a person,
 * the command may well be run from a shared terminal with a scrollback, and the
 * account id is enough to confirm the right row was touched.
 *
 * Granting this does NOT grant administrator access. It opens one screen: the
 * labelling queue and the form on it.
 */
class GrantStylistCommand extends Command
{
    protected $signature = 'user:stylist
                            {email : The account e-mail address}
                            {--revoke : Take the flag away instead of granting it}';

    protected $description = 'Grant or revoke stylist (colour labelling) access for an account';

    public function handle(): int
    {
        $revoking = (bool) $this->option('revoke');

        $user = $this->find((string) $this->argument('email'));
        if ($user === null) {
            // No address in the message: a wrong one typed at a shared terminal
            // should not end up in its scrollback either.
            $this->error('No account found for that address.');

            return self::FAILURE;
        }

        // Reads the raw column, not isStylist(), which is true for every
        // administrator. Reporting "already a stylist" to somebody about to
        // grant the flag explicitly would be answering a different question.
        if ((bool) $user->is_stylist === ! $revoking) {
            $this->line(sprintf(
                'Account #%d is already %s.',
                $user->id,
                $revoking ? 'not a stylist' : 'a stylist',
            ));

            return self::SUCCESS;
        }

        // forceFill: is_stylist is deliberately absent from $fillable so that
        // no ordinary ->fill() can ever set it. This is the one place that may.
        $user->forceFill(['is_stylist' => ! $revoking])->save();

        $this->info(sprintf(
            'Account #%d is %s a stylist.',
            $user->id,
            $revoking ? 'no longer' : 'now',
        ));

        if (! $revoking && $user->isAdmin()) {
            $this->line('(It was already an administrator, which counts as a stylist anyway.)');
        }

        return self::SUCCESS;
    }

    /** Find the account by address, however it was typed (case, spaces). */
    private function find(string $email): ?User
    {
        return User::query()->where('email', EmailAddress::normalize($email))->first();
    }
}
