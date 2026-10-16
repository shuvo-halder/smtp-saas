<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Mailbox;
use App\Policies\DomainPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\MailboxPolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Domain::class => DomainPolicy::class,
        Invoice::class => InvoicePolicy::class,
        Mailbox::class => MailboxPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
