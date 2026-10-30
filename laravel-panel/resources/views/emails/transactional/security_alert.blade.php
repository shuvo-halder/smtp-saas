@extends('emails.transactional.layout')

@section('content')
    <h2 style="color: #dc2626;">Security Alert</h2>
    <p>Hello,</p>
    <p>A critical security event has been detected on your account or email infrastructure.</p>

    <div class="notice-box" style="border-left-color: #dc2626;">
        <strong>Event:</strong> {{ $payload['alert_title'] ?? 'Security Incident' }}<br>
        <strong>Severity:</strong> <span style="text-transform: uppercase; color: #dc2626; font-weight: bold;">{{ $payload['severity'] ?? 'CRITICAL' }}</span><br>
        <strong>Details:</strong> {{ $payload['description'] ?? 'Abnormal activity detected on outbound mail pipeline.' }}
    </div>

    @if(!empty($payload['action_url']))
        <div style="text-align: center;">
            <a href="{{ $payload['action_url'] }}" class="button" style="background-color: #dc2626;" target="_blank">Review Security Incident</a>
        </div>
    @endif

    <p>If you have any questions or did not authorize this activity, please contact support immediately.</p>
@endsection
