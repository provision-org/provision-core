<x-mail::message>
# Team Invitation

You have been invited to join the **{{ $teamName }}** team!

<x-mail::button :url="$acceptUrl">
Accept Invitation
</x-mail::button>

If you did not expect to receive this invitation, you may discard this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
