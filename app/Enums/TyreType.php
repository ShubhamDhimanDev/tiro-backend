<?php

namespace App\Enums;

/**
 * Fixed tyre-type/tread-design classification (requirements §3.2). No
 * separate lookup entity/admin CRUD — `frontend/` mirrors these values
 * directly.
 */
enum TyreType: string
{
    case Highway = 'highway';
    case AllTerrain = 'all_terrain';
    case MudTerrain = 'mud_terrain';
    case Performance = 'performance';
    case Eco = 'eco';
}
