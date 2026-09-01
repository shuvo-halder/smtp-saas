<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'EmailSaaS') — Professional Business Email</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Hind+Siliguri:wght@400;500;600&display=swap" rel="stylesheet">

    <!-- Tailwind CSS CDN (replace with compiled in production) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'Hind Siliguri', 'sans-serif'] },
                    colors: {
                        primary: { 50:'#eff6ff', 500:'#3b82f6', 600:'#2563eb', 700:'#1d4ed8' },
                        success: '#22c55e', danger: '#ef4444', warning: '#f59e0b',
                    }
                }
            }
        }
    </script>
    <!-- Alpine.js for interactivity -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    @stack('styles')
</head>
<body class="bg-gray-50 font-sans">

<!-- ─── Top Navigation ──────────────────────────────────────────────────────── -->
<nav class="bg-white border-b border-gray-200 shadow-sm sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <!-- Logo -->
            <div class="flex items-center">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                    <div class="w-8 h-8 bg-primary-600 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <span class="text-xl font-bold text-gray-900">EmailSaaS</span>
                </a>
            </div>

            <!-- Desktop Nav -->
            <div class="hidden md:flex items-center gap-1">
                <a href="{{ route('dashboard') }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('dashboard') ? 'bg-primary-50 text-primary-700' : 'text-gray-600 hover:bg-gray-100' }}">
                    📊 Dashboard
                </a>
                <a href="{{ route('domains.index') }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('domains.*') ? 'bg-primary-50 text-primary-700' : 'text-gray-600 hover:bg-gray-100' }}">
                    🌐 Domains
                </a>
                <a href="{{ route('billing.index') }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('billing.*') ? 'bg-primary-50 text-primary-700' : 'text-gray-600 hover:bg-gray-100' }}">
                    💳 Billing
                </a>
                @if(auth()->user()->is_admin)
                <a href="{{ route('admin.dashboard') }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium text-purple-700 bg-purple-50">
                    ⚙️ Admin
                </a>
                @endif
            </div>

            <!-- User menu -->
            <div class="flex items-center gap-3" x-data="{ open: false }">
                <!-- Webmail button -->
                <a href="{{ config('app.roundcube_url') }}" target="_blank"
                   class="hidden sm:flex items-center gap-1 px-3 py-2 bg-green-50 text-green-700 rounded-lg text-sm font-medium hover:bg-green-100 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                    Webmail
                </a>

                <div class="relative">
                    <button @click="open = !open"
                            class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-100 transition">
                        <div class="w-8 h-8 bg-primary-600 rounded-full flex items-center justify-center text-white text-sm font-medium">
                            {{ substr(auth()->user()->name, 0, 1) }}
                        </div>
                        <span class="hidden sm:block text-sm font-medium text-gray-700">{{ auth()->user()->name }}</span>
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div x-show="open" @click.away="open = false"
                         class="absolute right-0 mt-2 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-1 z-50">
                        <div class="px-4 py-2 border-b border-gray-100">
                            <p class="text-sm font-medium text-gray-900">{{ auth()->user()->name }}</p>
                            <p class="text-xs text-gray-500">{{ auth()->user()->email }}</p>
                        </div>
                        <a href="{{ route('billing.index') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            💳 Subscription
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                🚪 Logout
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- ─── Flash Messages ──────────────────────────────────────────────────────── -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
    @if(session('success'))
        <div class="flex items-center gap-3 p-4 bg-green-50 border border-green-200 text-green-800 rounded-xl mb-4">
            <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/>
            </svg>
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="flex items-center gap-3 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl mb-4">
            <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"/>
            </svg>
            {{ session('error') }}
        </div>
    @endif

    @if(session('warning'))
        <div class="flex items-center gap-3 p-4 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-xl mb-4">
            ⚠️ {{ session('warning') }}
        </div>
    @endif
</div>

<!-- ─── Main Content ────────────────────────────────────────────────────────── -->
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @yield('content')
</main>

<!-- ─── Footer ─────────────────────────────────────────────────────────────── -->
<footer class="mt-12 border-t border-gray-200 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <div class="flex flex-col sm:flex-row justify-between items-center gap-4 text-sm text-gray-500">
            <p>© {{ date('Y') }} EmailSaaS. সকল অধিকার সংরক্ষিত।</p>
            <div class="flex gap-4">
                <a href="#" class="hover:text-gray-700">Support</a>
                <a href="#" class="hover:text-gray-700">Privacy Policy</a>
                <a href="#" class="hover:text-gray-700">Terms</a>
            </div>
        </div>
    </div>
</footer>

@stack('scripts')
</body>
</html>
