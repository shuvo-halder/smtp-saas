<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'max_domains' => $this->max_domains,
            'max_mailboxes_per_domain' => $this->max_mailboxes_per_domain,
            'storage_mb_per_mailbox' => $this->storage_mb_per_mailbox,
            'max_aliases_per_domain' => $this->max_aliases_per_domain,
            'daily_outbound_recipients' => $this->daily_outbound_recipients,
            'mailbox_daily_outbound_recipients' => $this->mailbox_daily_outbound_recipients,
            'price_monthly' => $this->price_monthly,
            'price_yearly' => $this->price_yearly,
            'features' => $this->features,
            'is_featured' => $this->is_featured,
            'yearly_discount' => $this->yearlyDiscount(),
            'max_domains_label' => $this->maxDomainsLabel(),
            'max_mailboxes_label' => $this->maxMailboxesLabel(),
        ];
    }
}
