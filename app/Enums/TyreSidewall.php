<?php

namespace App\Enums;

/**
 * `TyreVariant.sidewall` — left open-ended in the data model doc
 * (`[standard|xl|...]`). AU tyre industry sidewall/load-range markings:
 * Standard (SL), Extra Load (XL), Reinforced (RF — a distinct marking from
 * XL used mainly on van/commercial fitments by some manufacturers), and
 * Commercial (C-range light-truck/van load rating, e.g. "225/65R16C").
 */
enum TyreSidewall: string
{
    case Standard = 'standard';
    case ExtraLoad = 'xl';
    case Reinforced = 'reinforced';
    case Commercial = 'commercial';
}
