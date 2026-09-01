<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_name' => $this->domain_name,
            'status' => $this->status,
            'mx_verified' => $this->mx_verified,
            'spf_verified' => $this->spf_verified,
            'dkim_verified' => $this->dkim_verified,
            'dmarc_verified' => $this->dmarc_verified,
            'dkim_selector' => $this->dkim_selector,
            'mailboxes_count' => $this->mailboxes_count,
            'required_dns_records' => $this->requiredDnsRecords(),
            'activated_at' => $this->activated_at,
            'created_at' => $this->created_at,
        ];
    }
}
