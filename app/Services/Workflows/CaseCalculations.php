<?php

namespace App\Services\Workflows;

use DateTimeImmutable;

final class CaseCalculations
{
    public const DEFAULT_EXCLUSIONS = ['EĞİTİM', 'HASTALIK', 'KENDİ İSTEĞİ İLE', 'ASAYİŞ VE GÜVENLİK'];

    public function date(?string $value, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($value === null || ! preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $now->getTimezone());

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    public function days(?string $value, DateTimeImmutable $now): ?int
    {
        $date = $this->date($value, $now);

        return $date !== null && $date > $now ? $now->diff($date)->days : null;
    }

    public function remaining(?string $ds, ?string $release, DateTimeImmutable $now): string
    {
        $dates = array_filter([$this->date($ds, $now), $this->date($release, $now)]);
        if ($dates === []) {
            return 'BELİRSİZ';
        }
        $date = min($dates);

        return $date > $now ? $now->diff($date)->days.' GÜN' : 'GEÇMİŞ TARİH';
    }

    public function transfer(object $row, array $state, DateTimeImmutable $now, ?string $reason): bool
    {
        if (in_array($reason, $state['excluded'], true)) {
            return false;
        }
        // DS being present selects DS alone, even when release would be later.
        $date = $row->infaz_ds_tarihi !== null ? $row->infaz_ds_tarihi : $row->infaz_tahliye_tarihi;
        $days = $this->days($date, $now);
        if ($days === null || $days < $state['days']) {
            return false;
        }
        $birth = $this->date($row->infaz_dogum_tarihi, $now);

        return $state['type'] === 'il_ici' || $birth === null || $now->diff($birth)->y < $state['age'];
    }

    public function sentenceAge(?string $birth, DateTimeImmutable $now): ?int
    {
        $date = $this->date($birth, $now);

        return $date === null ? null : (int) floor(($date->diff($now->setTime(0, 0))->days * ($date > $now->setTime(0, 0) ? -1 : 1)) / 365.25);
    }

    public function suitability(?string $ds, ?string $release, DateTimeImmutable $now, int $weeks): string
    {
        $date = $this->date($ds ?? $release, $now);
        if ($date === null) {
            return '';
        }
        $days = $date > $now ? $now->diff($date)->days : 0;

        return $days >= $weeks * 7 ? 'UYGUN' : 'UYGUN DEĞİL';
    }

    public function mean(object $row): float
    {
        return round((($row->infaz_ceza_yil ?? 0) * 365 + ($row->infaz_ceza_ay ?? 0) * 30 + ($row->infaz_ceza_gun ?? 0)) / 365, 2);
    }
}
