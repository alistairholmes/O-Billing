<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * CLI counterpart of the panel's Users page, for bootstrapping a fresh install
 * (the very first login has to come from somewhere) without tinker.
 */
class UserProvision extends Command
{
    protected $signature = 'user:provision
        {name : Display name for the user}
        {email : Login email address}
        {--password= : Password (omit to generate a random one, printed once)}
        {--admin : Grant the administrator flag (can manage panel users)}
        {--municipality=* : Municipality id, code or name to attach (repeatable; defaults to all when there is exactly one)}';

    protected $description = 'Create a panel user and attach municipalities, without tinker';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email '{$email}' already exists. Use user:make-admin or the panel's Users page to change them.");

            return self::FAILURE;
        }

        $municipalities = $this->resolveMunicipalities();
        if ($municipalities === null) {
            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(16));

        $user = User::create([
            'name' => (string) $this->argument('name'),
            'email' => $email,
            'password' => $password, // hashed by the model cast
            'is_admin' => (bool) $this->option('admin'),
        ]);
        $user->municipalities()->attach($municipalities->modelKeys());

        $this->info("Created {$user->name} <{$user->email}>".($user->is_admin ? ' (administrator)' : ''));
        $this->line('Municipalities: '.$municipalities->pluck('name')->implode(', '));
        if (! $this->option('password')) {
            $this->warn("Generated password (shown once, share it securely): {$password}");
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Municipality>|null */
    private function resolveMunicipalities(): ?\Illuminate\Database\Eloquent\Collection
    {
        $wanted = array_map(strval(...), (array) $this->option('municipality'));

        if ($wanted === []) {
            $all = Municipality::all();
            if ($all->count() <= 1) {
                // Single-tenant install, or a fresh one with no municipality
                // yet — Filament will prompt the first login to register it.
                return $all;
            }

            $this->error('Pass --municipality= (id, code or name). Available: '.$all->pluck('name')->implode(', '));

            return null;
        }

        $resolved = Municipality::query()->get()->filter(
            fn (Municipality $m) => collect($wanted)->contains(
                fn (string $w) => strcasecmp($w, (string) $m->id) === 0
                    || strcasecmp($w, (string) $m->code) === 0
                    || strcasecmp($w, $m->name) === 0
            )
        )->values();

        if ($resolved->count() !== count($wanted)) {
            $this->error('Some municipalities were not found. Available: '.Municipality::pluck('name')->implode(', '));

            return null;
        }

        return $resolved;
    }
}
