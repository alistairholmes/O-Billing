<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UserMakeAdmin extends Command
{
    protected $signature = 'user:make-admin
        {email : Email address of an existing user}
        {--revoke : Remove the administrator flag instead of granting it}';

    protected $description = 'Grant (or revoke) the administrator flag, which unlocks the Users page in the panel';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with email '{$email}'.");

            return self::FAILURE;
        }

        $user->update(['is_admin' => ! $this->option('revoke')]);

        $this->info(sprintf(
            '%s (%s) is %s an administrator.',
            $user->name,
            $user->email,
            $user->is_admin ? 'now' : 'no longer',
        ));

        return self::SUCCESS;
    }
}
