<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UsersGenerateUsername extends Command
{
    protected $signature = 'users:generate-username';

    protected $description = 'Generate username for existing users who have null username';

    public function handle(): int
    {
        $users = User::whereNull('username')->get();

        if ($users->isEmpty()) {
            $this->info('All users already have a username.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($users->count());
        $bar->start();

        foreach ($users as $user) {
            $base = strtolower(explode('@', $user->email)[0]);
            $base = preg_replace('/[^a-z0-9_-]/', '', $base);
            if (strlen($base) < 3) {
                $base = 'user' . $base;
            }
            $username = $base;
            $counter = 2;
            while (User::where('username', $username)->exists()) {
                $username = $base . $counter;
                $counter++;
            }
            $user->username = $username;
            $user->save();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Usernames generated successfully for ' . $users->count() . ' users.');

        return self::SUCCESS;
    }
}
