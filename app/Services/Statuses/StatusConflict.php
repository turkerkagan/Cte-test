<?php

namespace App\Services\Statuses;

final class StatusConflict extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Durum başka bir işlemde değişti. Girdileriniz korunuyor; güncel kaydı yeniden inceleyin.');
    }
}
