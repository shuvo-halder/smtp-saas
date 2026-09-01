@extends('layouts.app')
@section('title', 'DNS Setup — ' . $domain->domain_name)

@section('content')
<div class="max-w-3xl mx-auto space-y-6">

    <!-- Header -->
    <div>
        <a href="{{ route('domains.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Domains</a>
        <h1 class="text-2xl font-bold text-gray-900 mt-2">DNS Setup</h1>
        <p class="text-gray-500">
            <span class="font-medium text-gray-700">{{ $domain->domain_name }}</span>
            এর জন্য নিচের DNS records আপনার domain registrar এ যোগ করুন।
        </p>
    </div>

    <!-- Status Banner -->
    @if($domain->isFullyVerified())
    <div class="p-4 bg-green-50 border border-green-200 rounded-xl flex items-center gap-3">
        <svg class="w-6 h-6 text-green-500" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/>
        </svg>
        <div>
            <p class="font-semibold text-green-800">✅ সব DNS records verified!</p>
            <p class="text-sm text-green-600">আপনার domain active। এখন mailbox তৈরি করুন।</p>
        </div>
        <a href="{{ route('domains.show', $domain) }}"
           class="ml-auto px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium">
            Mailbox তৈরি করুন →
        </a>
    </div>
    @else
    <div class="p-4 bg-amber-50 border border-amber-200 rounded-xl">
        <p class="font-medium text-amber-800">⏳ DNS verification pending</p>
        <p class="text-sm text-amber-600 mt-1">
            নিচের records আপনার DNS provider এ যোগ করুন। DNS change propagate হতে সাধারণত <strong>1-48 ঘন্টা</strong> সময় লাগে।
        </p>
    </div>
    @endif

    <!-- ─── DNS Records Table ─────────────────────────────────────────────── -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-900">প্রয়োজনীয় DNS Records</h2>
        </div>

        <div class="divide-y divide-gray-100">
            @foreach($dnsRecords as $key => $record)
            <div class="p-6 {{ $record['verified'] ? 'bg-green-50/30' : '' }}">
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center gap-3">
                        @if($record['verified'])
                        <span class="w-7 h-7 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-sm">✓</span>
                        @else
                        <span class="w-7 h-7 bg-gray-100 text-gray-400 rounded-full flex items-center justify-center text-sm">⋯</span>
                        @endif
                        <div>
                            <span class="font-semibold text-gray-900">{{ strtoupper($key) }} Record</span>
                            <span class="ml-2 px-2 py-0.5 bg-gray-100 text-gray-600 text-xs rounded font-mono">{{ $record['type'] }}</span>
                        </div>
                    </div>
                    @if($record['verified'])
                    <span class="text-xs text-green-600 font-medium bg-green-100 px-2 py-1 rounded-full">✓ Verified</span>
                    @else
                    <span class="text-xs text-yellow-600 font-medium bg-yellow-100 px-2 py-1 rounded-full">Pending</span>
                    @endif
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase mb-1">Host / Name</p>
                        <div class="flex items-center gap-2">
                            <code class="text-sm bg-gray-100 px-2 py-1 rounded font-mono text-gray-800">{{ $record['host'] }}</code>
                            <button onclick="copyToClipboard('{{ $record['host'] }}')"
                                    class="text-gray-400 hover:text-gray-600" title="Copy">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase mb-1">Type</p>
                        <code class="text-sm bg-blue-50 text-blue-700 px-2 py-1 rounded font-mono">{{ $record['type'] }}</code>
                    </div>
                    <div class="sm:col-span-1">
                        <p class="text-xs font-medium text-gray-500 uppercase mb-1">Value</p>
                        <div class="flex items-start gap-2">
                            <code class="text-xs bg-gray-100 px-2 py-1 rounded font-mono text-gray-800 break-all flex-1">{{ $record['value'] }}</code>
                            <button onclick="copyToClipboard('{{ addslashes($record['value']) }}')"
                                    class="text-gray-400 hover:text-gray-600 flex-shrink-0 mt-1" title="Copy">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    <!-- Verify Button -->
    <form method="POST" action="{{ route('domains.verify', $domain) }}" class="flex justify-end">
        @csrf
        <button type="submit"
                class="inline-flex items-center gap-2 px-6 py-3 bg-primary-600 text-white rounded-xl font-medium hover:bg-primary-700 transition shadow-sm">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            DNS Verify করুন
        </button>
    </form>

    <!-- Verification Results -->
    @if(session('verification_results'))
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="font-semibold text-gray-900 mb-4">Verification Results</h3>
        <div class="space-y-3">
            @foreach(session('verification_results') as $type => $result)
            <div class="flex items-start gap-3 p-3 rounded-lg {{ $result['verified'] ? 'bg-green-50' : 'bg-red-50' }}">
                <span class="{{ $result['verified'] ? 'text-green-600' : 'text-red-500' }} text-lg">
                    {{ $result['verified'] ? '✅' : '❌' }}
                </span>
                <div class="flex-1">
                    <p class="font-medium {{ $result['verified'] ? 'text-green-800' : 'text-red-800' }}">
                        {{ strtoupper($type) }}
                    </p>
                    @if(! $result['verified'] && $result['found'])
                    <p class="text-xs text-gray-500 mt-1">
                        Found: <code class="font-mono">{{ $result['found'] }}</code>
                    </p>
                    @endif
                    @if(! $result['verified'])
                    <p class="text-xs text-gray-500">
                        Expected: <code class="font-mono">{{ $result['expected'] }}</code>
                    </p>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

</div>

@push('scripts')
<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-4 right-4 bg-gray-900 text-white px-4 py-2 rounded-lg text-sm z-50';
        toast.textContent = '✓ Copied to clipboard!';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    });
}
</script>
@endpush
@endsection
