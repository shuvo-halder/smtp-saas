@extends('emails.transactional.layout')

@section('content')
    <h2>Reset Your Password</h2>
    <p>Hello,</p>
    <p>We received a request to reset your password for your EmailSaaS account. Click the button below to choose a new password:</p>

    @if(!empty($payload['reset_url']))
        <div style="text-align: center;">
            <a href="{{ $payload['reset_url'] }}" class="button" target="_blank">Reset Password</a>
        </div>
    @endif

    <div class="notice-box">
        <p style="margin: 0;">This password reset link will expire in 60 minutes. If you did not request a password reset, please secure your account immediately.</p>
    </div>
@endsection
