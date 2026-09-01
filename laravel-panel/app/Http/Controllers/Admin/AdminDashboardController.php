<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (! auth()->user()?->is_admin) {
                abort(403, 'Admin access required.');
            }
            return $next($request);
        });
    }

    /**
     * Admin overview dashboard
     */
    public function index()
    {
        $stats = [
            'total_users'     => User::count(),
            'active_users'    => User::where('status', 'active')->count(),
            'total_domains'   => Domain::count(),
            'active_domains'  => Domain::where('status', 'active')->count(),
            'total_mailboxes' => Mailbox::where('is_active', true)->count(),
            'revenue_month'   => Invoice::paid()
                                    ->whereMonth('paid_at', now()->month)
                                    ->sum('total'),
            'revenue_total'   => Invoice::paid()->sum('total'),
            'pending_invoices'=> Invoice::pending()->count(),
        ];

        $recentUsers = User::latest()->take(10)->with('plan')->get();
        $recentInvoices = Invoice::latest()->take(10)->with(['user', 'plan'])->get();

        return view('admin.dashboard', compact('stats', 'recentUsers', 'recentInvoices'));
    }

    // ─── User Management ────────────────────────────────────────────────────────

    public function users(Request $request)
    {
        $users = User::query()
            ->when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%")
                                                       ->orWhere('email', 'like', "%{$s}%"))
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->with('plan')
            ->withCount('domains')
            ->latest()
            ->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    public function showUser(User $user)
    {
        $user->load(['plan', 'domains.mailboxes', 'invoices.plan']);
        return view('admin.users.show', compact('user'));
    }

    public function suspendUser(User $user)
    {
        $user->update(['status' => 'suspended']);
        $user->domains()->update(['status' => 'suspended']);

        return back()->with('success', "User {$user->email} suspended করা হয়েছে।");
    }

    public function activateUser(User $user)
    {
        $user->update(['status' => 'active']);
        $user->domains()->where('mx_verified', true)->update(['status' => 'active']);

        return back()->with('success', "User {$user->email} activate করা হয়েছে।");
    }

    // ─── Plan Management ────────────────────────────────────────────────────────

    public function plans()
    {
        $plans = Plan::orderBy('sort_order')->get();
        return view('admin.plans.index', compact('plans'));
    }

    public function createPlan()
    {
        return view('admin.plans.create');
    }

    public function storePlan(Request $request)
    {
        $data = $request->validate([
            'name'                     => 'required|string|max:50',
            'slug'                     => 'required|string|unique:plans,slug',
            'description'              => 'nullable|string',
            'max_domains'              => 'required|integer|min:-1',
            'max_mailboxes_per_domain' => 'required|integer|min:-1',
            'storage_mb_per_mailbox'   => 'required|integer|min:100',
            'price_monthly'            => 'required|numeric|min:0',
            'price_yearly'             => 'required|numeric|min:0',
            'is_active'                => 'boolean',
            'is_featured'              => 'boolean',
            'sort_order'               => 'integer',
        ]);

        $data['features'] = $request->input('features', []);
        Plan::create($data);

        return redirect()->route('admin.plans.index')
            ->with('success', 'Plan তৈরি হয়েছে।');
    }

    // ─── Invoice Management ─────────────────────────────────────────────────────

    public function invoices(Request $request)
    {
        $invoices = Invoice::query()
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->with(['user', 'plan'])
            ->latest()
            ->paginate(30);

        return view('admin.invoices.index', compact('invoices'));
    }

    // ─── Server Stats ───────────────────────────────────────────────────────────

    public function serverStats()
    {
        // Read mail queue size
        exec('sudo postqueue -p 2>/dev/null | tail -1', $queueOutput);
        $queueSize = $queueOutput[0] ?? 'N/A';

        // Disk usage for mail
        exec('du -sh /var/mail/vhosts 2>/dev/null', $diskOutput);
        $diskUsage = $diskOutput[0] ?? 'N/A';

        // Active IMAP connections
        exec('doveadm who 2>/dev/null | wc -l', $imap);
        $imapConnections = max(0, ((int)($imap[0] ?? 0)) - 1);

        return view('admin.server-stats', compact('queueSize', 'diskUsage', 'imapConnections'));
    }
}
