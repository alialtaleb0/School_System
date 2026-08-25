<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CleanupUnverifiedUsers extends Command
{
    protected $signature = 'users:cleanup-unverified';
    protected $description = 'Delete users who have not verified their email within 24 hours';

    public function handle(): int
    {
        $deletedCount = User::whereNull('email_verified_at')
            ->where('created_at', '<', now()->subHours(24))
            ->delete();

        $this->info("Cleaned up {$deletedCount} unverified users.");

        return Command::SUCCESS;
    }
}
