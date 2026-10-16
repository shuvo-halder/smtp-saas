<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'domain_id'       => $this->domain_id,
            'mailbox_id'      => $this->mailbox_id,
            'sender_email'    => $this->sender_email,
            'recipient_email' => $this->recipient_email,
            'queue_id'        => $this->queue_id,
            'status'          => $this->status,
            'dsn_code'        => $this->dsn_code,
            'smtp_code'       => $this->smtp_code,
            'response_text'   => $this->sanitizeResponseText($this->response_text),
            'occurred_at'     => $this->occurred_at?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Sanitizes MTA / remote SMTP response text to prevent leakage of internal system details.
     */
    protected function sanitizeResponseText(?string $text): ?string
    {
        if (empty($text)) {
            return null;
        }

        // Mask internal server paths if present (e.g. /var/vmail/...)
        $sanitized = preg_replace('/(\/(?:var|etc|opt|home|usr)\/[a-zA-Z0-9_\-\.\/]+)/', '[system-path]', $text);

        // Limit maximum response length for clean UI presentation
        return mb_substr($sanitized, 0, 500);
    }
}
