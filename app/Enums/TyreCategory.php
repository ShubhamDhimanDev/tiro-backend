<?php

namespace App\Enums;

/**
 * Fixed browse-by-tyre-type category (requirements §3.2). No separate
 * lookup entity/admin CRUD — `frontend/` mirrors these values directly.
 */
enum TyreCategory: string
{
    case Car = 'car';
    case Suv = 'suv';
    case FourByFour = '4x4';
    case LightTruck = 'light_truck';
}
