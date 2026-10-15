<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Idempotently seeds standard subscription plans.
     */
    public function run(): void
    {
        $plans = [
            [
                'name'                              => 'Starter',
                'slug'                              => 'starter',
                'description'                       => 'Small business and startup starter email plan',
                'max_domains'                       => 1,
                'max_mailboxes_per_domain'          => 5,
                'storage_mb_per_mailbox'            => 1024,   // 1 GB
                'max_aliases_per_domain'            => 5,
                'daily_outbound_recipients'         => 500,
                'mailbox_daily_outbound_recipients' => 100,
                'price_monthly'                     => 199.00,
                'price_yearly'                      => 1990.00,
                'features'                          => [
                    '5 GB Total Storage',
                    'Spam & Anti-Virus Filtering',
                    'Webmail & IMAP/SMTP Access',
                    'Standard Support',
                ],
                'is_active'                         => true,
                'is_featured'                       => false,
                'sort_order'                        => 1,
            ],
            [
                'name'                              => 'Business',
                'slug'                              => 'business',
                'description'                       => 'Ideal for growing businesses requiring dedicated domains and high reliability',
                'max_domains'                       => 5,
                'max_mailboxes_per_domain'          => 25,
                'storage_mb_per_mailbox'            => 5120,   // 5 GB
                'max_aliases_per_domain'            => 25,
                'daily_outbound_recipients'         => 2500,
                'mailbox_daily_outbound_recipients' => 300,
                'price_monthly'                     => 499.00,
                'price_yearly'                      => 4990.00,
                'features'                          => [
                    '125 GB Total Storage',
                    'Priority Delivery Routing',
                    'Email Aliases & Forwarding',
                    'Spam & Anti-Virus Protection',
                    'Priority Support',
                ],
                'is_active'                         => true,
                'is_featured'                       => true,
                'sort_order'                        => 2,
            ],
            [
                'name'                              => 'Enterprise',
                'slug'                              => 'enterprise',
                'description'                       => 'High-volume infrastructure for organizations with advanced compliance and dedicated resources',
                'max_domains'                       => -1,     // Unlimited
                'max_mailboxes_per_domain'          => -1,     // Unlimited
                'storage_mb_per_mailbox'            => 51200,  // 50 GB
                'max_aliases_per_domain'            => -1,     // Unlimited
                'daily_outbound_recipients'         => 10000,
                'mailbox_daily_outbound_recipients' => 1000,
                'price_monthly'                     => 1499.00,
                'price_yearly'                      => 14990.00,
                'features'                          => [
                    'Unlimited Domains',
                    'Unlimited Mailboxes',
                    '50 GB / Mailbox',
                    'Custom DKIM & SPF Management',
                    'Audit Logging & Compliance',
                    'Dedicated Account Manager',
                ],
                'is_active'                         => true,
                'is_featured'                       => false,
                'sort_order'                        => 3,
            ],
        ];

        foreach ($plans as $planData) {
            Plan::firstOrCreate(['slug' => $planData['slug']], $planData);
        }
    }
}
