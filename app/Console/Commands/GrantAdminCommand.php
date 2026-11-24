<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Otp\EmailAddress;
use Illuminate\Console\Command;

/**
 * Grants or revokes the administrator flag on an account.
 *
 * The flag has no path from the interface, on purpose: a self-service route to
 * privilege would be a whole attack surface built for a tool with one user. So
 * this is how it is set — by someone with shell access to the server, which is
 * the same bar as deploying.
 *
 * The address is never logged and never echoed back. It identifies a person,
 * the command may well be run from a shared terminal with a scrollback, and the
 * account id is enough to confirm the right row was touched.
 */
class GrantAdminCommand extends Command
{
    protected $signature = 'user:admin
                            {email : The account e-mail address}
                            {--revoke : Take the flag away instead of granting it}';

    protected $description = 'Grant or revoke administrator access for an account';

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

        if ($user->isAdmin() === ! $revoking) {
            $this->line(sprintf(
                'Account #%d is already %s.',
                $user->id,
                $revoking ? 'not an administrator' : 'an administrator',
            ));

            return self::SUCCESS;
        }

        // forceFill: is_admin is deliberately absent from $fillable so that no
        // ordinary ->fill() can ever set it. This is the one place that may.
        $user->forceFill(['is_admin' => ! $revoking])->save();

        $this->info(sprintf(
            'Account #%d is %s an administrator.',
            $user->id,
            $revoking ? 'no longer' : 'now',
        ));

        return self::SUCCESS;
    }

    /** Find the account by address, however it was typed (case, spaces). */
    private function find(string $email): ?User
    {
        return User::query()->where('email', EmailAddress::normalize($email))->first();
    }
}
