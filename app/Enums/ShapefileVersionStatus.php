<?php

namespace App\Enums;

enum ShapefileVersionStatus: string
{
    case Staged = 'staged';
    case Active = 'active';
    case Superseded = 'superseded';
    case Rejected = 'rejected';
}
