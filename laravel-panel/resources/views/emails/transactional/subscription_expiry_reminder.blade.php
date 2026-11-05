@extends('emails.transactional.layout')

@section('content')
    <h2 style="color: #d97706;">Subscription Expiring Soon</h2>
    <p>Hello,</p>
    <p>Your EmailSaaS subscription is scheduled to expire in <strong>{{ $payload['days_remaining'] ?? 'a few' }} days</strong>@if(!empty($payload['expires_at'])) ({{ $payload['expires_at'] }})@endif.</p>

    <div class="notice-box" style="border-left-color: #d97706;">
        <strong>Plan:</strong> {{ $payload['plan_name'] ?? 'EmailSaaS Subscription' }}<br>
        @if(!empty($payload['expires_at']))
            <strong>Expiration Date:</strong> {{ $payload['expires_at'] }}<br>
        @endif
        <p style="margin: 8px 0 0 0;">To prevent any disruption to your outgoing mail delivery, domain routing, or mailbox access, please renew your subscription before the expiration date.</p>
    </div>

    @if(!empty($payload['renew_url']))
        <div style="text-align: center;">
            <a href="{{ $payload['renew_url'] }}" class="button" style="background-color: #d97706;" target="_blank">Renew Subscription Now</a>
        </div>
    @endif

    <p>Thank you for using EmailSaaS.</p>
@endsection
