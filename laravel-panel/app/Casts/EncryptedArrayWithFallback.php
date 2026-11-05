<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class EncryptedArrayWithFallback implements CastsAttributes
{
    /**
     * Transform the attribute from the underlying model values.
     * Automatically decrypts payload; gracefully falls back to JSON decoding for legacy plaintext rows.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return array|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        try {
            // Unquote JSON string if wrapped for MySQL JSON column compatibility
            $unwrapped = is_string($value) ? (json_decode($value, true) ?? $value) : $value;
            $decrypted = Crypt::decrypt($unwrapped);
            return is_array($decrypted) ? $decrypted : json_decode((string) $decrypted, true);
        } catch (Throwable) {
            // Graceful fallback for legacy records stored as plaintext JSON
            $decoded = is_array($value) ? $value : json_decode((string) $value, true);
            return is_array($decoded) ? $decoded : null;
        }
    }

    /**
     * Transform the attribute to its underlying storage format.
     * Encrypts the payload array with AES-256 via application key (APP_KEY) at rest.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return string|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // MySQL 8.0/8.4 native JSON columns enforce strict JSON syntax at the database engine level.
        // Wrapping the encrypted ciphertext with json_encode() satisfies MySQL JSON column validation
        // while preserving AES-256 ciphertext encryption at rest.
        return json_encode(Crypt::encrypt($value));
    }
}
