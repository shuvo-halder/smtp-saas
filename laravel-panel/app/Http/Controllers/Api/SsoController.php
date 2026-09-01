<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SsoController extends Controller
{
    /**
     * Generate a one-time Dovecot Master OTP and return Roundcube autologin URL.
     */
    public function webmailSso(Request $request)
    {
        $user = $request->user();

        // Retrieve the user's primary mailbox (first active mailbox across their domains)
        $mailbox = Mailbox::whereIn('domain_id', $user->domains->pluck('id'))
            ->where('is_active', true)
            ->first();

        if (!$mailbox) {
            return response()->json([
                'message' => __('api.no_mailbox_found', [
                    'default' => 'You have not created an active mailbox yet. Please create an inbox first.'
                ])
            ], 400);
        }

        // Generate a cryptographically secure 32-character OTP
        $otp = Str::random(32);

        // Store the OTP in Redis/Cache for exactly 60 seconds.
        // Dovecot's authentication script must be configured to check this cache key.
        $cacheKey = 'dovecot_otp_' . $mailbox->email;
        Cache::put($cacheKey, $otp, 60);

        // Construct the Webmail URL 
        // Note: For GET-based Roundcube autologin, a plugin like 'autologin' must be active on the Roundcube server.
        $webmailBaseUrl = env('NEXT_PUBLIC_WEBMAIL_URL', 'https://webmail.mailsaas.com');
        
        $loginUrl = $webmailBaseUrl . '/?_task=login&_action=login' . 
                    '&_user=' . urlencode($mailbox->email) . 
                    '&_pass=' . urlencode($otp);

        return response()->json([
            'url' => $loginUrl,
            'email' => $mailbox->email,
            'expires_in' => 60
        ]);
    }
}
