<?php

namespace App\Enums;

enum Tier: string
{
    case Free = 'free';
    case Plus = 'plus';
    case Pro = 'pro';
}
