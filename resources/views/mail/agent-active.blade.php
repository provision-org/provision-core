<x-mail::message>
# Your Agent is Live

**{{ $agentName }}** is now active and ready to go.

<x-mail::button :url="$agentUrl">
View Agent
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
