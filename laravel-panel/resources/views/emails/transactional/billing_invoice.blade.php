@extends('emails.transactional.layout')

@section('content')
    <h2>Subscription Invoice Ready</h2>
    <p>Hello,</p>
    <p>A new invoice has been generated for your EmailSaaS subscription.</p>

    <div class="notice-box">
        <strong>Invoice Number:</strong> {{ $payload['invoice_number'] ?? 'N/A' }}<br>
        <strong>Amount Due:</strong> {{ $payload['currency'] ?? 'USD' }} {{ number_format((float) ($payload['amount'] ?? 0), 2) }}<br>
        <strong>Due Date:</strong> {{ $payload['due_date'] ?? 'Immediate' }}
    </div>

    @if(!empty($payload['invoice_url']))
        <div style="text-align: center;">
            <a href="{{ $payload['invoice_url'] }}" class="button" target="_blank">View Invoice & Pay</a>
        </div>
    @endif

    <p>Thank you for choosing EmailSaaS.</p>
@endsection
