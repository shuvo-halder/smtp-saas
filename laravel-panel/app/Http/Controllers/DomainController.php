<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\DnsVerificationService;
use App\Services\PostfixService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DomainController extends Controller
{
    public function __construct(
        private PostfixService $postfix,
        private DnsVerificationService $dns,
    ) {}

    /**
     * List all domains for the authenticated user
     */
    public function index()
    {
        $domains = Auth::user()
            ->domains()
            ->withCount('mailboxes')
            ->latest()
            ->paginate(10);

        return view('domains.index', compact('domains'));
    }

    /**
     * Show domain creation form
     */
    public function create()
    {
        if (! Auth::user()->canAddDomain()) {
            return back()->with('error', 'আপনার current plan এ আর domain যোগ করা যাবে না। Upgrade করুন।');
        }

        return view('domains.create');
    }

    /**
     * Store a new domain and provision on mail server
     */
    public function store(Request $request)
    {
        $request->validate([
            'domain_name' => [
                'required',
                'string',
                'max:255',
                'unique:domains,domain_name',
                'regex:/^[a-zA-Z0-9][a-zA-Z0-9\-]{0,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$/',
            ],
        ], [
            'domain_name.regex'  => 'Valid domain name দিন (যেমন: example.com)',
            'domain_name.unique' => 'এই domain টি ইতিমধ্যে registered আছে।',
        ]);

        if (! Auth::user()->canAddDomain()) {
            return back()->with('error', 'Plan limit পূর্ণ হয়ে গেছে।');
        }

        // Create domain record
        $domain = Auth::user()->domains()->create([
            'domain_name' => strtolower(trim($request->domain_name)),
            'status'      => 'pending',
        ]);

        try {
            // Provision on mail server (add to Postfix + generate DKIM)
            $this->postfix->addDomain($domain);
            $domain->refresh(); // Reload to get DKIM key

        } catch (\Exception $e) {
            $domain->update(['status' => 'failed']);
            return back()->with('error', 'Server error: ' . $e->getMessage());
        }

        return redirect()
            ->route('domains.setup', $domain)
            ->with('success', 'Domain যোগ করা হয়েছে! এখন DNS records setup করুন।');
    }

    /**
     * DNS setup instructions page
     */
    public function setup(Domain $domain)
    {
        $this->authorize('view', $domain);

        $dnsRecords = $domain->requiredDnsRecords();
        return view('domains.setup', compact('domain', 'dnsRecords'));
    }

    /**
     * Verify DNS records for a domain
     */
    public function verify(Domain $domain)
    {
        $this->authorize('update', $domain);

        $results = $this->dns->verifyAll($domain);
        $domain->refresh();

        $message = $domain->isFullyVerified()
            ? '✅ সব DNS records verified! Domain এখন active।'
            : '⚠️ কিছু DNS records এখনও verify হয়নি। DNS change propagate হতে 24-48 ঘন্টা লাগতে পারে।';

        return back()->with([
            'verification_results' => $results,
            'message' => $message,
        ]);
    }

    /**
     * Show domain details
     */
    public function show(Domain $domain)
    {
        $this->authorize('view', $domain);

        $mailboxes = $domain->mailboxes()->latest()->paginate(20);
        $aliases   = $domain->aliases()->latest()->get();
        $dnsRecords = $domain->requiredDnsRecords();

        return view('domains.show', compact('domain', 'mailboxes', 'aliases', 'dnsRecords'));
    }

    /**
     * Delete a domain
     */
    public function destroy(Domain $domain)
    {
        $this->authorize('delete', $domain);

        try {
            $this->postfix->removeDomain($domain);
        } catch (\Exception $e) {
            return back()->with('error', 'Server error: ' . $e->getMessage());
        }

        $domain->delete();

        return redirect()
            ->route('domains.index')
            ->with('success', "Domain {$domain->domain_name} মুছে ফেলা হয়েছে।");
    }
}
