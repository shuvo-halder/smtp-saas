<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Services\PostfixService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class MailboxController extends Controller
{
    public function __construct(private PostfixService $postfix) {}

    /**
     * Show all mailboxes for a domain
     */
    public function index(Domain $domain)
    {
        $this->authorize('view', $domain);

        $mailboxes = $domain->mailboxes()->latest()->paginate(20);
        return view('mailboxes.index', compact('domain', 'mailboxes'));
    }

    /**
     * Show mailbox creation form
     */
    public function create(Domain $domain)
    {
        $this->authorize('update', $domain);

        if ($domain->status !== 'active') {
            return back()->with('error', 'Domain টি এখনও active হয়নি। আগে DNS setup করুন।');
        }

        if (! Auth::user()->canAddMailbox($domain)) {
            return back()->with('error', 'এই domain এ আর mailbox যোগ করা যাবে না। Plan upgrade করুন।');
        }

        return view('mailboxes.create', compact('domain'));
    }

    /**
     * Create a new mailbox
     */
    public function store(Request $request, Domain $domain)
    {
        $this->authorize('update', $domain);

        $request->validate([
            'local_part'   => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._%+\-]+$/'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'password'     => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'quota_mb'     => ['required', 'integer', 'min:100', 'max:' . Auth::user()->plan->storage_mb_per_mailbox],
        ]);

        $email = strtolower($request->local_part) . '@' . $domain->domain_name;

        // Check if email already exists
        if (Mailbox::where('email', $email)->exists()) {
            return back()->with('error', "Email {$email} ইতিমধ্যে তৈরি আছে।");
        }

        if (! Auth::user()->canAddMailbox($domain)) {
            return back()->with('error', 'Plan limit পূর্ণ।');
        }

        $mailbox = $domain->mailboxes()->create([
            'local_part'   => strtolower($request->local_part),
            'email'        => $email,
            'display_name' => $request->display_name,
            'quota_mb'     => $request->quota_mb,
        ]);

        try {
            $this->postfix->addMailbox($mailbox, $request->password);
        } catch (\Exception $e) {
            $mailbox->delete();
            return back()->with('error', 'Mailbox তৈরি করা সম্ভব হয়নি: ' . $e->getMessage());
        }

        return redirect()
            ->route('domains.show', $domain)
            ->with('success', "Mailbox {$email} সফলভাবে তৈরি হয়েছে!");
    }

    /**
     * Change mailbox password
     */
    public function changePassword(Request $request, Domain $domain, Mailbox $mailbox)
    {
        $this->authorize('update', $domain);

        $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        try {
            $this->postfix->changeMailboxPassword($mailbox, $request->password);
        } catch (\Exception $e) {
            return back()->with('error', 'Password পরিবর্তন সম্ভব হয়নি: ' . $e->getMessage());
        }

        return back()->with('success', "Password সফলভাবে পরিবর্তন হয়েছে।");
    }

    /**
     * Toggle mailbox active/inactive
     */
    public function toggle(Domain $domain, Mailbox $mailbox)
    {
        $this->authorize('update', $domain);

        $mailbox->update(['is_active' => ! $mailbox->is_active]);

        $status = $mailbox->is_active ? 'সক্রিয়' : 'নিষ্ক্রিয়';
        return back()->with('success', "Mailbox {$status} করা হয়েছে।");
    }

    /**
     * Delete a mailbox
     */
    public function destroy(Domain $domain, Mailbox $mailbox)
    {
        $this->authorize('update', $domain);

        try {
            $this->postfix->removeMailbox($mailbox);
        } catch (\Exception $e) {
            return back()->with('error', 'Mailbox মুছতে সমস্যা হয়েছে: ' . $e->getMessage());
        }

        $mailbox->delete();

        return back()->with('success', "Mailbox {$mailbox->email} মুছে ফেলা হয়েছে।");
    }
}
