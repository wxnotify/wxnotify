<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The location is still used by alerts; deleting it would leave them with no location.
 */
class LocationInUse extends RuntimeException
{
    /**
     * @param  list<string>  $alertNames
     */
    public function __construct(public readonly array $alertNames)
    {
        parent::__construct('The location is used by alerts.');
    }
}
