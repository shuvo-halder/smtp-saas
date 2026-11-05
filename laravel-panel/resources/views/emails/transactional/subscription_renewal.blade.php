@extends('emails.transactional.layout')

@section('content')
    <h2>Subscription Renewal Confirmed</h2>
    <p>Hello,</p>
    <p>Your EmailSaaS subscription has been successfully renewed. Thank you for continuing with us!</p>

    <div class="notice-box">
        <strong>Invoice Number:</strong> {{ $payload['invoice_number'] ?? 'N/A' }}<br>
        <strong>Plan:</strong> {{ $payload['plan_name'] ?? 'EmailSaaS Subscription' }}<br>
        <strong>Amount Paid:</strong> {{ $payload['currency'] ?? 'BDT' }} {{ number_format((float) ($payload['amount'] ?? 0), 2) }}<br>
        @if(!empty($payload['expires_at']))
            <strong>Valid Through:</strong> {{ $payload['expires_at'] }}<br>
        @endif
    </div>

    @if(!empty($payload['invoice_url']))
        <div style="text-align: center;">
            <a href="{{ $payload['invoice_url'] }}" class="button" target="_blank">View Invoice Receipt</a>
        </div>
    @endif

    <p>If you have any questions about this renewal, please contact our support team.</p>
@endsection
