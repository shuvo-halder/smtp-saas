<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Default Plans ──────────────────────────────────────────────────────

        $plans = [
            [
                'name'                     => 'Starter',
                'slug'                     => 'starter',
                'description'              => 'ছোট ব্যবসার জন্য আদর্শ',
                'max_domains'              => 1,
                'max_mailboxes_per_domain' => 5,
                'storage_mb_per_mailbox'   => 1024,   // 1 GB
                'max_aliases_per_domain'   => 5,
                'price_monthly'            => 199.00,
                'price_yearly'             => 1990.00,
                'features'                 => ['5 GB মোট Storage', 'Spam Protection', 'Email Support'],
                'is_active'                => true,
                'is_featured'              => false,
                'sort_order'               => 1,
            ],
            [
                'name'                     => 'Business',
                'slug'                     => 'business',
                'description'              => 'বেশিরভাগ business এর জন্য পরিপূর্ণ',
                'max_domains'              => 3,
                'max_mailboxes_per_domain' => 20,
                'storage_mb_per_mailbox'   => 5120,   // 5 GB
                'max_aliases_per_domain'   => 20,
                'price_monthly'            => 499.00,
                'price_yearly'             => 4990.00,
                'features'                 => ['100 GB মোট Storage', 'Priority Support', 'Email Aliases', 'Anti-Virus Scanning'],
                'is_active'                => true,
                'is_featured'              => true,   // Highlighted
                'sort_order'               => 2,
            ],
            [
                'name'                     => 'Enterprise',
                'slug'                     => 'enterprise',
                'description'              => 'বড় প্রতিষ্ঠানের জন্য unlimited সুবিধা',
                'max_domains'              => -1,     // Unlimited
                'max_mailboxes_per_domain' => -1,     // Unlimited
                'storage_mb_per_mailbox'   => 51200,  // 50 GB
                'max_aliases_per_domain'   => -1,
                'price_monthly'            => 1499.00,
                'price_yearly'             => 14990.00,
                'features'                 => ['Unlimited Domains', 'Unlimited Mailboxes', '50 GB/Mailbox', 'Dedicated Support', 'Custom DKIM', 'SLA Guarantee'],
                'is_active'                => true,
                'is_featured'              => false,
                'sort_order'               => 3,
            ],
        ];

        foreach ($plans as $planData) {
            Plan::firstOrCreate(['slug' => $planData['slug']], $planData);
        }

        echo "✅ Plans seeded.\n";

        // ─── Default Admin User ─────────────────────────────────────────────────

        User::firstOrCreate(
            ['email' => 'admin@yourdomain.com'],
            [
                'name'     => 'Admin',
                'password' => Hash::make('ChangeMe@1234!'),
                'is_admin' => true,
                'status'   => 'active',
            ]
        );

        echo "✅ Admin user seeded (email: admin@yourdomain.com, password: ChangeMe\@1234!)\n";
        echo "⚠️  প্রথম login এর পরে password পরিবর্তন করুন!\n";
    }
}
