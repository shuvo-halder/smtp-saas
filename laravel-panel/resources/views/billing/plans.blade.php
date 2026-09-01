@extends('layouts.app')
@section('title', 'Plans & Pricing')

@section('content')
<div class="max-w-5xl mx-auto space-y-8">

    <div class="text-center">
        <h1 class="text-3xl font-bold text-gray-900">সঠিক Plan বেছে নিন</h1>
        <p class="text-gray-500 mt-2">কোনো hidden charge নেই। যেকোনো সময় upgrade বা downgrade করুন।</p>

        <!-- Billing cycle toggle -->
        <div class="inline-flex items-center gap-3 mt-6 bg-gray-100 p-1 rounded-xl" x-data="{ cycle: 'monthly' }">
            <button @click="cycle = 'monthly'"
                    :class="cycle === 'monthly' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500'"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition">
                মাসিক
            </button>
            <button @click="cycle = 'yearly'"
                    :class="cycle === 'yearly' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500'"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition">
                বার্ষিক
                <span class="ml-1 bg-green-100 text-green-700 text-xs px-1.5 py-0.5 rounded-full">২০% ছাড়</span>
            </button>
        </div>
    </div>

    <!-- Plans Grid -->
    <div class="grid md:grid-cols-3 gap-6" x-data="{ cycle: 'monthly' }">
        @foreach($plans as $plan)
        <div class="bg-white rounded-2xl border {{ $plan->is_featured ? 'border-primary-500 shadow-lg ring-2 ring-primary-500' : 'border-gray-200 shadow-sm' }} overflow-hidden flex flex-col">

            @if($plan->is_featured)
            <div class="bg-primary-600 text-white text-center py-1.5 text-xs font-medium">⭐ সবচেয়ে জনপ্রিয়</div>
            @endif

            <div class="p-6 flex flex-col flex-1">
                <h3 class="text-xl font-bold text-gray-900">{{ $plan->name }}</h3>
                <p class="text-gray-500 text-sm mt-1">{{ $plan->description }}</p>

                <!-- Price -->
                <div class="mt-4 mb-6">
                    <div x-show="cycle === 'monthly'">
                        <span class="text-4xl font-bold text-gray-900">৳{{ number_format($plan->price_monthly) }}</span>
                        <span class="text-gray-500">/মাস</span>
                    </div>
                    <div x-show="cycle === 'yearly'" style="display:none">
                        <span class="text-4xl font-bold text-gray-900">৳{{ number_format($plan->price_yearly / 12) }}</span>
                        <span class="text-gray-500">/মাস</span>
                        <p class="text-sm text-green-600 mt-1">বার্ষিক পরিশোধ: ৳{{ number_format($plan->price_yearly) }}</p>
                    </div>
                </div>

                <!-- Features -->
                <ul class="space-y-3 flex-1 mb-6">
                    <li class="flex items-center gap-2 text-sm text-gray-700">
                        <span class="text-green-500">✓</span>
                        {{ $plan->maxDomainsLabel() }} Domain
                    </li>
                    <li class="flex items-center gap-2 text-sm text-gray-700">
                        <span class="text-green-500">✓</span>
                        {{ $plan->maxMailboxesLabel() }} Mailbox/Domain
                    </li>
                    <li class="flex items-center gap-2 text-sm text-gray-700">
                        <span class="text-green-500">✓</span>
                        {{ $plan->storage_mb_per_mailbox >= 1024 ? round($plan->storage_mb_per_mailbox/1024, 0).'GB' : $plan->storage_mb_per_mailbox.'MB' }} Storage/Mailbox
                    </li>
                    <li class="flex items-center gap-2 text-sm text-gray-700">
                        <span class="text-green-500">✓</span>
                        IMAP / SMTP সাপোর্ট
                    </li>
                    <li class="flex items-center gap-2 text-sm text-gray-700">
                        <span class="text-green-500">✓</span>
                        Roundcube Webmail
                    </li>
                    <li class="flex items-center gap-2 text-sm text-gray-700">
                        <span class="text-green-500">✓</span>
                        DKIM / SPF / DMARC
                    </li>
                    @foreach($plan->features ?? [] as $feature)
                    <li class="flex items-center gap-2 text-sm text-gray-700">
                        <span class="text-green-500">✓</span>
                        {{ $feature }}
                    </li>
                    @endforeach
                </ul>

                <!-- CTA -->
                @if($currentPlan?->id === $plan->id)
                <div class="w-full py-3 text-center bg-gray-100 text-gray-600 rounded-xl text-sm font-medium">
                    ✓ আপনার বর্তমান Plan
                </div>
                @else
                <form method="POST" action="{{ route('billing.checkout') }}">
                    @csrf
                    <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                    <input type="hidden" name="billing_cycle" x-bind:value="cycle">
                    <button type="submit"
                            class="w-full py-3 {{ $plan->is_featured ? 'bg-primary-600 hover:bg-primary-700 text-white' : 'bg-gray-900 hover:bg-gray-800 text-white' }} rounded-xl text-sm font-medium transition">
                        শুরু করুন →
                    </button>
                </form>
                @endif
            </div>
        </div>
        @endforeach
    </div>

    <!-- FAQ -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="font-semibold text-gray-900 mb-4">সাধারণ প্রশ্ন</h3>
        <div class="space-y-4" x-data="{ open: null }">
            @foreach([
                ['q' => 'আমার বিদ্যমান domain ব্যবহার করতে পারব?', 'a' => 'হ্যাঁ! যেকোনো domain registrar (GoDaddy, Namecheap, Hostinger, BD-র কোনো registrar) এর domain ব্যবহার করতে পারবেন। শুধু DNS records update করতে হবে।'],
                ['q' => 'Payment method কী কী?', 'a' => 'bKash, Nagad, Rocket, Visa/Mastercard সহ সব major বাংলাদেশী payment method গ্রহণযোগ্য।'],
                ['q' => 'Email কি spam হবে?', 'a' => 'না! আমরা DKIM, SPF, DMARC সেটআপ করি এবং dedicated IP ব্যবহার করি যা email deliverability নিশ্চিত করে।'],
                ['q' => 'Data কি safe?', 'a' => 'হ্যাঁ। সব data encrypted এবং আমাদের Bangladesh-based secured server এ store হয়।'],
            ] as $i => $faq)
            <div class="border border-gray-100 rounded-lg overflow-hidden">
                <button @click="open = open === {{ $i }} ? null : {{ $i }}"
                        class="w-full flex items-center justify-between px-4 py-3 text-left font-medium text-gray-900 hover:bg-gray-50">
                    {{ $faq['q'] }}
                    <svg class="w-4 h-4 text-gray-400 transition-transform" :class="open === {{ $i }} ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="open === {{ $i }}" class="px-4 pb-3 text-sm text-gray-600">
                    {{ $faq['a'] }}
                </div>
            </div>
            @endforeach
        </div>
    </div>

</div>
@endsection
