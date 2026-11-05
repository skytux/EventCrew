<?php

declare(strict_types=1);

/**
 * A stand-in for EventMesh's eventmesh_display_title(), for EventNameTest:
 * post 8 has no display title, every other post "Ecstatic Dance (lang)".
 */
function eventmesh_display_title(int|\WP_Post $post, string $lang = ''): string
{
    return 8 === $post ? '' : 'Ecstatic Dance (' . $lang . ')';
}
