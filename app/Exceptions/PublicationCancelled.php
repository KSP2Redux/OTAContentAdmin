<?php

namespace App\Exceptions;

use RuntimeException;

final class PublicationCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Publication was stopped by an administrator.');
    }
}
