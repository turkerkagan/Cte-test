<?php

namespace App\Services\Modules;

final class DefinitionConflict extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Taslak başka bir işlemde değişti. Yazdıklarınız korunuyor; güncel taslağı yükleyip yeniden inceleyin.');
    }
}
