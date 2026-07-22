<?php

namespace App\Enums;

enum ArtifactVisibility: string
{
    /** Anyone with the URL can load it. */
    case Public = 'public';

    /** Requires a shared link token (validated by Caddy via forward_auth). */
    case Gated = 'gated';
}
