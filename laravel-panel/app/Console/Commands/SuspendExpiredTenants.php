<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SuspendExpiredTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:suspend-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Suspends tenants whose subscription has expired and immediately disables their mail daemons.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for expired tenants...');

        $expiredTenants = User::where('status', 'active')
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<', now())
            ->get();

        if ($expiredTenants->isEmpty()) {
            $this->info('No expired tenants found.');
            return 0;
        }

        DB::beginTransaction();

        try {
            foreach ($expiredTenants as $tenant) {
                // Suspend the tenant (User)
                $tenant->update(['status' => 'suspended']);

                // Suspend all domains associated with the tenant
                // Postfix/Dovecot are configured to instantly reject/disable mail 
                // for domains where status != 'active'
                $tenant->domains()->update(['status' => 'suspended']);

                // Explicitly disable mailboxes as well for thoroughness
                foreach ($tenant->domains as $domain) {
                    $domain->mailboxes()->update(['is_active' => false]);
                }

                Log::info("Suspended expired tenant ID: {$tenant->id}, Email: {$tenant->email}");
                $this->info("Suspended tenant: {$tenant->email}");
            }

            DB::commit();
            $this->info('Successfully suspended expired tenants.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to suspend expired tenants: ' . $e->getMessage());
            $this->error('Failed to suspend expired tenants. Check logs.');
            return 1;
        }

        return 0;
    }
}
