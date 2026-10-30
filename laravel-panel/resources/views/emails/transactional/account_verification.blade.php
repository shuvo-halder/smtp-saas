@extends('emails.transactional.layout')

@section('content')
    <h2>Verify Your Email Address</h2>
    <p>Hello {{ $payload['name'] ?? 'there' }},</p>
    <p>Thank you for creating an account on EmailSaaS. Please confirm your email address by clicking the button below:</p>
    
    @if(!empty($payload['verification_url']))
        <div style="text-align: center;">
            <a href="{{ $payload['verification_url'] }}" class="button" target="_blank">Verify Email Address</a>
        </div>
    @endif

    @if(!empty($payload['verification_code']))
        <div class="notice-box">
            <strong>Verification Code:</strong> <code>{{ $payload['verification_code'] }}</code>
        </div>
    @endif

    <p>If you did not initiate this request, no further action is required.</p>
@endsection
