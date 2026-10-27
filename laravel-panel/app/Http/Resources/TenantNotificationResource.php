<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantNotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'category'     => $this->category,
            'severity'     => $this->severity,
            'title'        => $this->title,
            'message'      => $this->message,
            'action_url'   => $this->action_url,
            'data'         => $this->sanitizeData($this->data),
            'is_read'      => $this->read_at !== null,
            'read_at'      => $this->read_at?->toIso8601String(),
            'is_dismissed' => $this->dismissed_at !== null,
            'dismissed_at' => $this->dismissed_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Sanitize notification metadata, ensuring no sensitive credentials, keys, or paths leak.
     */
    protected function sanitizeData(?array $data): ?array
    {
        if (empty($data)) {
            return null;
        }

        $redactedKeys = [
            'password', 'secret', 'token', 'key', 'private_key', 'dkim_private_key',
            'api_key', 'hash', 'salt', 'trace', 'file', 'line', 'evidence'
        ];

        $sanitized = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);
            $shouldRedact = false;
            foreach ($redactedKeys as $redacted) {
                if (str_contains($lowerKey, $redacted)) {
                    $shouldRedact = true;
                    break;
                }
            }

            if (!$shouldRedact && !is_object($value)) {
                $sanitized[$key] = is_array($value) ? $this->sanitizeData($value) : $value;
            }
        }

        return $sanitized;
    }
}
