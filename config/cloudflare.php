<?php

return [
    /*
     * Cloudflare API credentials used to manage DNS records for agent artifact
     * subdomains ({agent.slug}.provisionagents.com). The token needs Zone.DNS
     * edit permission scoped to the artifact domain's zone.
     */
    'api_token' => env('CLOUDFLARE_API_TOKEN'),
    'zone_id' => env('CLOUDFLARE_ZONE_ID'),

    /*
     * The apex domain under which agent artifact subdomains are created. Agents
     * publish web artifacts to {agent.slug}.{artifact_domain}. When null, the
     * artifact-publishing feature is effectively disabled.
     */
    'artifact_domain' => env('ARTIFACT_DOMAIN'),
];
