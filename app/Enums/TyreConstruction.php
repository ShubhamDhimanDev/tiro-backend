<?php

namespace App\Enums;

/**
 * `TyreModel.construction` — left open-ended in the data model doc
 * (`[radial|...]`). AU tyre industry standard construction types: radial
 * covers the overwhelming majority of passenger/SUV/light-truck tyres sold
 * today; bias-ply (cross-ply) still turns up on some 4x4/trailer/
 * agricultural fitments, so it's kept as the one other real option rather
 * than invented.
 */
enum TyreConstruction: string
{
    case Radial = 'radial';
    case BiasPly = 'bias_ply';
}
