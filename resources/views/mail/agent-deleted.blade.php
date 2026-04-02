<x-mail::message>
# Agent Removed

**{{ $agentName }}** has been removed from the **{{ $teamName }}** team.

<x-mail::button :url="$agentsUrl">
View Agents
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
