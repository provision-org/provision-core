<?php

namespace App\Enums;

enum ArtifactType: string
{
    /** Static files served directly by Caddy from a directory. */
    case Static = 'static';

    /** A long-running app, reverse-proxied to a port the agent runs. */
    case App = 'app';
}
