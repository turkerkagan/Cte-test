<?php

namespace App\Services\People;

final class PersonConflict extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Kişi kaydı başka bir işlemde değişti. Güncel kaydı inceleyip yeniden deneyin.');
    }
}
