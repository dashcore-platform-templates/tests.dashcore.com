<x-mail::message>
# You're invited to join {{ config('app.name') }}

**{{ $inviterName }}** has invited you to join {{ config('app.name') }} as a **{{ $role }}**.

Click the button below to accept and set up your account. This invitation expires on {{ $expiresAt->format('M j, Y') }}.

<x-mail::button :url="$acceptUrl">
Accept Invitation
</x-mail::button>

If you weren't expecting this invitation, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
