<x-mail::message>
# Welcome to Provision

Hi {{ $name }},

Welcome to Provision, your AI agent platform. Deploy, manage, and scale intelligent agents from a single dashboard.

<x-mail::button :url="$dashboardUrl">
Go to Dashboard
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
