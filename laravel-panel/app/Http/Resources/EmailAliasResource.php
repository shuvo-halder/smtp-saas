<?php

namespace App\Http\Resources;

use App\Services\PostfixService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailAliasResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $routing = app(PostfixService::class)->verifyAliasRouting($this->source_email);

        $forwardingStatus = 'configured';
        if (! $this->is_active) {
            $forwardingStatus = 'configured';
        } elseif ($this->domain?->status !== 'active') {
            $forwardingStatus = 'configured';
        } else {
            $forwardingStatus = $routing['status'];
        }

        return [
            'id'                => $this->id,
            'domain_id'         => $this->domain_id,
            'domain_name'       => $this->domain?->domain_name,
            'source_email'      => $this->source_email,
            'destination_email' => $this->destination_email,
            'is_active'         => (bool) $this->is_active,
            'forwarding_status' => $forwardingStatus,
            'routing_details'   => [
                'is_routable'     => $routing['is_routable'],
                'terminal_target' => $routing['terminal_target'],
                'hop_count'       => $routing['hop_count'],
                'hops'            => $routing['hops'],
                'warning'         => $routing['warning'],
                'mta_driver'      => 'postfix_mysql_map',
            ],
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}

