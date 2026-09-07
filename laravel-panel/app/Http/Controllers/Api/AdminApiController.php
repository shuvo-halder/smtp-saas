<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PlanResource;
use App\Http\Resources\UserResource;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminApiController extends Controller
{
    public function stats()
    {
        $totalUsers = User::count();
        $activeUsers = User::where('status', 'active')->count();
        $totalDomains = Domain::count();
        $activeDomains = Domain::where('status', 'active')->count();
        $totalMailboxes = Mailbox::count();
        
        $revenueMonth = Invoice::where('status', 'paid')
            ->whereMonth('paid_at', now()->month)
            ->whereYear('paid_at', now()->year)
            ->sum('total');
            
        $revenueTotal = Invoice::where('status', 'paid')->sum('total');
        $pendingInvoices = Invoice::where('status', 'pending')->count();
        
        $recentUsers = User::latest()->take(5)->get();
        $recentInvoices = Invoice::with('plan')->latest()->take(5)->get();

        return response()->json([
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'total_domains' => $totalDomains,
            'active_domains' => $activeDomains,
            'total_mailboxes' => $totalMailboxes,
            'revenue_month' => $revenueMonth,
            'revenue_total' => $revenueTotal,
            'pending_invoices' => $pendingInvoices,
            'recent_users' => UserResource::collection($recentUsers),
            'recent_invoices' => InvoiceResource::collection($recentInvoices),
        ]);
    }

    public function users(Request $request)
    {
        $query = User::query()->with('plan');
        
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $users = $query->latest()->paginate(15);
        return UserResource::collection($users);
    }

    public function showUser(User $user)
    {
        $user->load(['plan', 'domains', 'domains.mailboxes', 'invoices' => function($q) {
            $q->latest()->take(10);
        }]);
        $user->loadCount(['domains', 'mailboxes']);
        return response()->json(new UserResource($user));
    }

    public function domains(Request $request)
    {
        $query = Domain::with('user');
        
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('domain_name', 'like', "%{$search}%");
        }
        
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $domains = $query->latest()->paginate(15);
        return \App\Http\Resources\DomainResource::collection($domains);
    }

    public function mailboxes(Request $request)
    {
        $query = Mailbox::with(['domain', 'domain.user']);
        
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('email', 'like', "%{$search}%");
        }

        if ($request->has('status')) {
            $status = $request->input('status') === 'active' ? 1 : 0;
            $query->where('is_active', $status);
        }

        $mailboxes = $query->latest()->paginate(15);
        return \App\Http\Resources\MailboxResource::collection($mailboxes);
    }

    public function suspendUser(User $user)
    {
        $user->update(['status' => 'suspended']);
        $user->domains()->update(['status' => 'suspended']);
        
        return response()->json(new UserResource($user));
    }

    public function activateUser(User $user)
    {
        $user->update(['status' => 'active']);
        // Only reactivate verified domains (assuming verified domains have required DNS)
        // Adjust logic based on your domain activation flow
        $user->domains()->where('mx_verified', true)->update(['status' => 'active']);
        
        return response()->json(new UserResource($user));
    }

    public function plans()
    {
        $plans = Plan::latest()->get();
        return PlanResource::collection($plans);
    }

    public function storePlan(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:plans,slug',
            'description' => 'nullable|string',
            'max_domains' => 'required|integer',
            'max_mailboxes_per_domain' => 'required|integer',
            'storage_mb_per_mailbox' => 'required|integer',
            'max_aliases_per_domain' => 'required|integer',
            'daily_outbound_recipients' => 'required|integer|min:-1',
            'mailbox_daily_outbound_recipients' => 'required|integer|min:-1',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'required|numeric|min:0',
            'features' => 'nullable|array',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $plan = Plan::create($validated);
        
        return response()->json(new PlanResource($plan), 201);
    }

    public function showPlan(Plan $plan)
    {
        return response()->json(new PlanResource($plan));
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:plans,slug,' . $plan->id,
            'description' => 'nullable|string',
            'max_domains' => 'required|integer',
            'max_mailboxes_per_domain' => 'required|integer',
            'storage_mb_per_mailbox' => 'required|integer',
            'max_aliases_per_domain' => 'required|integer',
            'daily_outbound_recipients' => 'required|integer|min:-1',
            'mailbox_daily_outbound_recipients' => 'required|integer|min:-1',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'required|numeric|min:0',
            'features' => 'nullable|array',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $plan->update($validated);
        
        return response()->json(new PlanResource($plan));
    }

    public function destroyPlan(Plan $plan)
    {
        // Check if plan has related users or invoices before deleting
        if (User::where('plan_id', $plan->id)->exists() || Invoice::where('plan_id', $plan->id)->exists()) {
            return response()->json([
                'message' => __('messages.plan_in_use')
            ], 422);
        }

        $plan->delete();
        return response()->json(null, 204);
    }

    public function invoices(Request $request)
    {
        $query = Invoice::with(['user', 'plan']);
        
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        
        $invoices = $query->latest()->paginate(15);
        return InvoiceResource::collection($invoices);
    }

    public function serverStats()
    {
        // Execute server commands safely
        // Note: These require appropriate permissions on the server
        
        $mailQueue = 0;
        try {
            $mailQueueOutput = shell_exec('postqueue -p | tail -n 1');
            if (preg_match('/-- (\d+) Kbytes in (\d+) Requests/', $mailQueueOutput, $matches)) {
                $mailQueue = (int) $matches[2];
            }
        } catch (\Exception $e) {}

        $diskUsage = 0;
        try {
            $diskOutput = shell_exec("df -h / | tail -1 | awk '{print $5}'");
            $diskUsage = (int) str_replace('%', '', trim($diskOutput));
        } catch (\Exception $e) {}

        $imapConnections = 0;
        try {
            // Assuming dovecot is used
            $imapOutput = shell_exec("doveadm who | grep imap | wc -l");
            $imapConnections = (int) trim($imapOutput);
        } catch (\Exception $e) {}

        return response()->json([
            'mail_queue_size' => $mailQueue,
            'disk_usage_percent' => $diskUsage,
            'imap_connections' => $imapConnections,
        ]);
    }

    public function chartData()
    {
        // 1. Monthly Revenue (Last 6 Months)
        $sixMonthsAgo = now()->subMonths(5)->startOfMonth();
        
        $revenueData = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $sixMonthsAgo)
            ->select(
                DB::raw("DATE_FORMAT(paid_at, '%Y-%m') as month"),
                DB::raw('SUM(total) as revenue')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // 2. Monthly User Signups (Last 6 Months)
        $signupsData = User::where('created_at', '>=', $sixMonthsAgo)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('COUNT(id) as signups')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // 3. Mailbox Status Distribution
        $mailboxesStatus = Mailbox::select(
                DB::raw('is_active'),
                DB::raw('COUNT(id) as count')
            )
            ->groupBy('is_active')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->is_active ? 'active' : 'suspended' => $item->count];
            });

        return response()->json([
            'revenue_by_month' => $revenueData,
            'signups_by_month' => $signupsData,
            'mailboxes_status' => [
                'active' => $mailboxesStatus->get('active', 0),
                'suspended' => $mailboxesStatus->get('suspended', 0)
            ],
        ]);
    }
}
