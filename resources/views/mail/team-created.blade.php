<x-mail::message>
# Your Team is Ready

The **{{ $teamName }}** team has been created and is ready to use.

You can now invite members and start deploying agents.

<x-mail::button :url="$teamUrl">
Team Settings
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
