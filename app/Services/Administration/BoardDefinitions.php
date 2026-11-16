<?php

namespace App\Services\Administration;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BoardDefinitions
{
    public function read(): array
    {
        $names = ['discipline' => 'DİSİPLİN KURULU', 'administration' => 'İDARE VE GÖZLEM KURULU', 'education' => 'EĞİTİM KURULU', 'workshop' => 'İŞYURDU YÖNETİM KURULU', 'archive' => 'ARŞİV AYIKLAMA VE İMHA KOMİSYONU', 'letters' => 'MEKTUP OKUMA KOMİSYONU', 'packages' => 'KOLİ AÇMA KOMİSYONU', 'books' => 'KİTAP SAYIM KOMİSYONU', 'general_acceptance' => 'MUAYENE KABUL KOMİSYONU (GENEL BÜTÇE)', 'workshop_acceptance' => 'MUAYENE KABUL KOMİSYONU (İŞYURDU MÜDÜRLÜĞÜ)'];
        $defaults = [];
        foreach ($names as $code => $label) {
            $defaults[] = ['code' => $code, 'label' => $label, 'is_active' => true];
        }

        return app(ManagementSettings::class)->read('board_headers', ['items' => $defaults]);
    }

    public function save(User $actor, array $input): void
    {
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:0'], 'items' => ['required', 'array', 'list', 'min:1', 'max:40'],
            'items.*' => ['array:code,label,is_active'], 'items.*.code' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/D', 'distinct'],
            'items.*.label' => ['required', 'string', 'max:150'], 'items.*.is_active' => ['required', 'boolean']])->validate();
        $old = $this->read()['values']['items'];
        if (array_diff(array_column($old, 'code'), array_column($data['items'], 'code'))) {
            throw ValidationException::withMessages(['items' => 'Kayıtlı başlıkları kaldırmak yerine pasife alın; kurul verileri korunur.']);
        }
        app(ManagementSettings::class)->save($actor, 'board_headers', ['items' => $data['items']], $data['revision']);
    }

    public function fields(): array
    {
        $fields = ['position' => ['label' => 'Sıra', 'type' => 'integer', 'required' => true]];
        foreach ($this->read()['values']['items'] as $item) {
            if ($item['is_active']) {
                $fields[$item['code'].'_top'] = ['label' => $item['label'].' İsim', 'type' => 'text'];
                $fields[$item['code'].'_bottom'] = ['label' => $item['label'].' Ünvan', 'type' => 'text'];
            }
        }

        return $fields;
    }
}
