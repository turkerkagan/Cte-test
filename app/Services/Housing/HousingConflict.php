<?php

namespace App\Services\Housing;

final class HousingConflict extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Yerleşim ayarı başka bir işlemde değişti. Girdileriniz korunuyor; güncel ayarı yeniden inceleyin.');
    }
}
