<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MailboxResource;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Services\PostfixService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class MailboxApiController extends Controller
{
    use AuthorizesRequests;

    public function index(Domain $domain)
    {
        $this->authorize('view', $domain);

        $mailboxes = $domain->mailboxes()->paginate(15);
        return MailboxResource::collection($mailboxes);
    }

    public function store(Request $request, Domain $domain, PostfixService $postfixService)
    {
        $this->authorize('update', $domain);

        $validated = $request->validate([
            'local_part' => 'required|string|alpha_dash|max:64',
            'password' => 'required|string|min:8',
            'display_name' => 'nullable|string|max:255',
            'quota_mb' => 'nullable|integer|min:1',
        ]);

        if (!$request->user()->canAddMailbox($domain)) {
            return response()->json(['message' => __('api.mailbox_limit_reached')], 403);
        }

        $email = strtolower($validated['local_part']) . '@' . $domain->domain_name;
        
        if (Mailbox::where('email', $email)->exists()) {
            return response()->json(['message' => __('api.email_exists')], 422);
        }

        $salt = \Illuminate\Support\Str::random(16);
        $dovecotPassword = crypt($validated['password'], '$6$' . $salt . '$');

        $mailbox = \DB::transaction(function () use ($domain, $validated, $email, $request, $dovecotPassword, $postfixService) {
            $mb = $domain->mailboxes()->create([
                'local_part' => strtolower($validated['local_part']),
                'email' => $email,
                'password' => $dovecotPassword, // Hashed specifically for Dovecot SHA512-CRYPT
                'display_name' => $validated['display_name'] ?? null,
                'quota_mb' => $validated['quota_mb'] ?? $request->user()->plan->storage_mb_per_mailbox,
                'is_active' => true,
            ]);

            $postfixService->addMailbox($mb);

            return $mb;
        });

        return response()->json(new MailboxResource($mailbox), 201);
    }

    public function changePassword(Request $request, Mailbox $mailbox)
    {
        $this->authorize('update', $mailbox->domain);

        $validated = $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $salt = \Illuminate\Support\Str::random(16);
        $mailbox->update([
            'password' => crypt($validated['password'], '$6$' . $salt . '$')
        ]);

        return response()->json(['message' => __('messages.mailbox_password_changed')]);
    }

    public function toggle(Mailbox $mailbox)
    {
        $this->authorize('update', $mailbox->domain);

        $mailbox->update([
            'is_active' => !$mailbox->is_active
        ]);

        return response()->json(new MailboxResource($mailbox));
    }

    public function destroy(Mailbox $mailbox, PostfixService $postfixService)
    {
        $this->authorize('delete', $mailbox->domain);

        $postfixService->removeMailbox($mailbox);
        $mailbox->delete();

        return response()->json(null, 204);
    }
}
