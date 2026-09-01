@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="space-y-6">

    <!-- ─── Header ─────────────────────────────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">
                স্বাগতম, {{ auth()->user()->name }}! 👋
            </h1>
            <p class="text-gray-500 mt-1">আপনার email service overview</p>
        </div>
        <a href="{{ route('domains.create') }}"
           class="inline-flex items-center gap-2 px-4 py-2 bg-primary-600 text-white rounded-xl font-medium hover:bg-primary-700 transition shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            নতুন Domain যোগ করুন
        </a>
    </div>

    <!-- ─── Subscription Alert ─────────────────────────────────────────────── -->
    @if(! auth()->user()->isSubscriptionActive())
    <div class="p-4 bg-amber-50 border border-amber-200 rounded-xl flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="text-2xl">⚠️</span>
            <div>
                <p class="font-medium text-amber-900">Subscription নেই বা মেয়াদ উত্তীর্ণ!</p>
                <p class="text-sm text-amber-700">Email service ব্যবহার করতে একটি plan select করুন।</p>
            </div>
        </div>
        <a href="{{ route('billing.plans') }}"
           class="px-4 py-2 bg-amber-500 text-white rounded-lg text-sm font-medium hover:bg-amber-600 transition">
            Plan দেখুন
        </a>
    </div>
    @else
    <!-- Plan info bar -->
    <div class="p-4 bg-blue-50 border border-blue-100 rounded-xl flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="text-2xl">✨</span>
            <div>
                <p class="font-medium text-blue-900">{{ auth()->user()->plan->name }} Plan</p>
                <p class="text-sm text-blue-600">
                    মেয়াদ: {{ auth()->user()->plan_expires_at?->format('d M Y') ?? 'Lifetime' }}
                </p>
            </div>
        </div>
        <a href="{{ route('billing.plans') }}"
           class="text-sm text-blue-600 hover:text-blue-700 font-medium">Upgrade →</a>
    </div>
    @endif

    <!-- ─── Stats Cards ─────────────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @php
        $stats = [
            ['label' => 'মোট Domain', 'value' => auth()->user()->domains()->count(), 'icon' => '🌐', 'color' => 'blue'],
            ['label' => 'Active Domain', 'value' => auth()->user()->activeDomains()->count(), 'icon' => '✅', 'color' => 'green'],
            ['label' => 'মোট Mailbox', 'value' => auth()->user()->totalMailboxes(), 'icon' => '📧', 'color' => 'purple'],
            ['label' => 'Pending Invoice', 'value' => auth()->user()->invoices()->pending()->count(), 'icon' => '💳', 'color' => 'amber'],
        ];
        @endphp

        @foreach($stats as $stat)
        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between mb-3">
                <span class="text-2xl">{{ $stat['icon'] }}</span>
            </div>
            <p class="text-3xl font-bold text-gray-900">{{ $stat['value'] }}</p>
            <p class="text-sm text-gray-500 mt-1">{{ $stat['label'] }}</p>
        </div>
        @endforeach
    </div>

    <!-- ─── Domains Table ──────────────────────────────────────────────────── -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
            <h2 class="font-semibold text-gray-900">আপনার Domains</h2>
            <a href="{{ route('domains.index') }}" class="text-sm text-primary-600 hover:text-primary-700">সব দেখুন →</a>
        </div>

        @if($domains->isEmpty())
        <div class="text-center py-12">
            <div class="text-5xl mb-4">📭</div>
            <h3 class="font-medium text-gray-900 mb-2">কোনো domain নেই</h3>
            <p class="text-gray-500 text-sm mb-4">প্রথম domain যোগ করুন এবং professional email তৈরি করুন।</p>
            <a href="{{ route('domains.create') }}"
               class="inline-flex items-center gap-2 px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700">
                + Domain যোগ করুন
            </a>
        </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-6 py-3 text-left">Domain</th>
                        <th class="px-6 py-3 text-left">Status</th>
                        <th class="px-6 py-3 text-left">DNS</th>
                        <th class="px-6 py-3 text-left">Mailbox</th>
                        <th class="px-6 py-3 text-left">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($domains as $domain)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4">
                            <span class="font-medium text-gray-900">{{ $domain->domain_name }}</span>
                        </td>
                        <td class="px-6 py-4">
                            @php
                            $statusColors = [
                                'active'    => 'bg-green-100 text-green-700',
                                'pending'   => 'bg-yellow-100 text-yellow-700',
                                'suspended' => 'bg-red-100 text-red-700',
                                'failed'    => 'bg-red-100 text-red-700',
                            ];
                            @endphp
                            <span class="px-2.5 py-1 rounded-full text-xs font-medium {{ $statusColors[$domain->status] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ ucfirst($domain->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex gap-1">
                                <span title="MX" class="w-6 h-6 rounded-full text-xs flex items-center justify-center {{ $domain->mx_verified ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400' }}">M</span>
                                <span title="SPF" class="w-6 h-6 rounded-full text-xs flex items-center justify-center {{ $domain->spf_verified ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400' }}">S</span>
                                <span title="DKIM" class="w-6 h-6 rounded-full text-xs flex items-center justify-center {{ $domain->dkim_verified ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400' }}">D</span>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-gray-700">{{ $domain->mailboxes_count }}</span>
                        </td>
                        <td class="px-6 py-4">
                            <a href="{{ route('domains.show', $domain) }}"
                               class="text-primary-600 hover:text-primary-700 text-sm font-medium">Manage →</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <!-- ─── Recent Invoices ────────────────────────────────────────────────── -->
    @if($invoices->isNotEmpty())
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
            <h2 class="font-semibold text-gray-900">সাম্প্রতিক Invoice</h2>
            <a href="{{ route('billing.index') }}" class="text-sm text-primary-600 hover:text-primary-700">সব দেখুন →</a>
        </div>
        <div class="divide-y divide-gray-100">
            @foreach($invoices as $invoice)
            <div class="flex items-center justify-between px-6 py-3">
                <div>
                    <p class="text-sm font-medium text-gray-900">{{ $invoice->invoice_number }}</p>
                    <p class="text-xs text-gray-500">{{ $invoice->created_at->format('d M Y') }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm font-semibold text-gray-900">{{ $invoice->formattedTotal() }}</span>
                    <span class="px-2 py-0.5 rounded-full text-xs {{ $invoice->isPaid() ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                        {{ ucfirst($invoice->status) }}
                    </span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

</div>
@endsection
