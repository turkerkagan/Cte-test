<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class SentenceCalculator
{
    /** @return array<string, int|string> */
    public function calculateTool(string $tool, array $input): array
    {
        $start = CarbonImmutable::parse($input['start_date']);
        $years = max(0, (int) ($input['years'] ?? 0));
        $months = max(0, (int) ($input['months'] ?? 0));
        $days = max(0, (int) ($input['days'] ?? 0));
        $totalDays = ($years * 365) + ($months * 30) + $days;
        $creditDays = max(0, (int) ($input['credit_days'] ?? 0));
        $valueDays = max(0, (int) ($input['value_days'] ?? 0));

        return match ($tool) {
            'date-interval' => $this->dateInterval($start, CarbonImmutable::parse($input['end_date'])),
            'apc' => [
                'APC gün sayısı' => $valueDays,
                'APC bitiş tarihi' => $start->addDays($valueDays)->toDateString(),
            ],
            'terror-one-third' => $this->fractionResult($start, $totalDays, 3, $creditDays, '1/3', true),
            'fine' => [
                'Para cezası günü' => $valueDays,
                'Yeni tahliye tarihi' => $start->subDays($valueDays)->toDateString(),
            ],
            'one-tenth' => $this->fractionResult($start, $totalDays, 10, $creditDays, '1/10', false),
            'ds-one' => $this->supervisionResult($start, 365, $valueDays, '1 yıl'),
            'ds-three' => $this->supervisionResult($start, 1095, $valueDays, '3 yıl'),
            'law-7550' => $this->law7550($start, CarbonImmutable::parse($input['end_date'])),
            default => throw new \InvalidArgumentException('Bilinmeyen hesaplama aracı.'),
        };
    }

    /**
     * Eski infaz.1.3.php ve infaz.ondabir.php mantığı.
     * Terör 1/3 (eski): net <= 0 ise "Zaten kazanıldı" ($earnedAtZero = true, çünkü eski kod yalnız net > 0'da tarih ekler).
     * Onda bir (eski): net == 0 ise başlangıç tarihi, net < 0 ise "Zaten infaz edildi" ($earnedAtZero = false).
     *
     * @return array<string, int|string>
     */
    private function fractionResult(CarbonImmutable $start, int $totalDays, int $divisor, int $creditDays, string $label, bool $earnedAtZero): array
    {
        $fractionDays = (int) floor($totalDays / $divisor);
        $netDays = $fractionDays - $creditDays;
        $earned = $earnedAtZero ? $netDays <= 0 : $netDays < 0;

        return [
            'Toplam ceza günü' => $totalDays,
            $label.' gün' => $fractionDays,
            'Mahsup günü' => $creditDays,
            'Net gün' => $netDays,
            'Kazanma / infaz tarihi' => $earned ? 'Zaten kazanıldı / infaz edildi' : $start->addDays($netDays)->toDateString(),
        ];
    }

    /** @return array<string, int|string> */
    private function supervisionResult(CarbonImmutable $conditionalRelease, int $limit, int $previousDays, string $label): array
    {
        $eligibleDays = $limit - $previousDays;

        return [
            'DS üst sınırı' => $label,
            'Daha önce yararlanılan gün' => $previousDays,
            'Yararlanacağı gün' => $eligibleDays,
            'DS çıkış tarihi' => $conditionalRelease->subDays($eligibleDays)->toDateString(),
        ];
    }

    /** @return array<string, int|string> */
    private function law7550(CarbonImmutable $entry, CarbonImmutable $conditionalRelease): array
    {
        $stayDays = (int) $entry->diffInDays($conditionalRelease, false);
        if ($stayDays < 0) {
            throw new \InvalidArgumentException('Şartla tahliye tarihi kurum giriş tarihinden önce olamaz.');
        }
        $oneTenthDays = (int) floor($stayDays / 10) + 1;

        return [
            'Kalacağı süre (gün)' => $stayDays,
            '1/10 süre (gün)' => $oneTenthDays,
            '1/10 bitim tarihi' => $entry->addDays($oneTenthDays)->toDateString(),
            'En az 5 gün süre sonu' => $entry->addDays(5)->toDateString(),
        ];
    }

    /** @return array<string, int|string> */
    private function dateInterval(CarbonImmutable $entry, CarbonImmutable $exit): array
    {
        $days = (int) $entry->diffInDays($exit, false);
        if ($days < 0) {
            throw new \InvalidArgumentException('Çıkış tarihi giriş tarihinden önce olamaz.');
        }

        return ['Tarih aralığı (gün)' => $days];
    }

    public function calculate(array $input): array
    {
        $start = CarbonImmutable::parse($input['start_date']);
        $years = (int) ($input['years'] ?? 0);
        $months = (int) ($input['months'] ?? 0);
        $days = (int) ($input['days'] ?? 0);
        $totalDays = max(0, ($years * 365) + ($months * 30) + $days);
        $ratio = (float) ($input['ratio'] ?? (1 / 3));
        $servedDays = (int) floor($totalDays * $ratio);
        $creditDays = max(0, (int) ($input['credit_days'] ?? 0));
        $fineDays = max(0, (int) ($input['fine_days'] ?? 0));
        $extraSupervisionDays = max(0, (int) ($input['extra_supervision_days'] ?? 0));
        $releaseDays = max(0, $servedDays + $fineDays - $creditDays);
        $conditionalRelease = $start->addDays($releaseDays);
        $supervisionOne = $conditionalRelease->subYear();
        $supervisionThree = $conditionalRelease->subYears(3);
        $oneTenthDays = (int) floor($totalDays / 10);
        $apcDays = max(0, $releaseDays - $extraSupervisionDays);

        return [
            'start_date' => $start->toDateString(),
            'total_days' => $totalDays,
            'served_days' => $servedDays,
            'credit_days' => $creditDays,
            'fine_days' => $fineDays,
            'ratio' => $ratio,
            'conditional_release_date' => $conditionalRelease->toDateString(),
            'supervision_one_year_date' => $supervisionOne->toDateString(),
            'supervision_three_year_date' => $supervisionThree->toDateString(),
            'one_tenth_days' => $oneTenthDays,
            'apc_date' => $start->addDays($apcDays)->toDateString(),
            'extra_supervision_days' => $extraSupervisionDays,
        ];
    }

    public function equivalenceExamples(): array
    {
        return [
            ['name' => 'normal_ornek', 'input' => ['start_date' => '2026-01-01', 'years' => 3, 'ratio' => 1 / 3], 'expected_total_days' => 1095],
            ['name' => 'sinir_sifir', 'input' => ['start_date' => '2026-01-01', 'days' => 0, 'ratio' => 1 / 3], 'expected_total_days' => 0],
            ['name' => 'mahsup_para', 'input' => ['start_date' => '2026-01-01', 'months' => 12, 'fine_days' => 10, 'credit_days' => 5, 'ratio' => 0.5], 'expected_total_days' => 360],
        ];
    }

    public function verifyExamples(): array
    {
        return collect($this->equivalenceExamples())->map(function (array $example): array {
            $result = $this->calculate($example['input']);

            return $example + ['actual_total_days' => $result['total_days'], 'passed' => $result['total_days'] === $example['expected_total_days'], 'result' => $result];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function legacyToolFixtures(): array
    {
        return [
            ['name' => 'apc_ay_sonunu_asar', 'tool' => 'apc', 'input' => ['start_date' => '31-01-2026', 'value_days' => 1], 'expected' => ['APC bitiş tarihi' => '2026-02-01']],
            ['name' => 'tarih_araligi_artik_yil', 'tool' => 'date-interval', 'input' => ['start_date' => '28-02-2024', 'end_date' => '01-03-2024'], 'expected' => ['Tarih aralığı (gün)' => 2]],
            ['name' => 'teror_bolme_asagi_yuvarlar', 'tool' => 'terror-one-third', 'input' => ['start_date' => '2026-01-01', 'years' => 1], 'expected' => ['Toplam ceza günü' => 365, '1/3 gün' => 121]],
            ['name' => 'para_cezasi_tarihten_duser', 'tool' => 'fine', 'input' => ['start_date' => '2026-03-01', 'value_days' => 1], 'expected' => ['Yeni tahliye tarihi' => '2026-02-28']],
            ['name' => 'onda_bir_asagi_yuvarlar', 'tool' => 'one-tenth', 'input' => ['start_date' => '2026-01-01', 'days' => 99], 'expected' => ['1/10 gün' => 9]],
            ['name' => 'onda_bir_mahsup_asimi', 'tool' => 'one-tenth', 'input' => ['start_date' => '2026-01-01', 'days' => 99, 'credit_days' => 10], 'expected' => ['Net gün' => -1, 'Kazanma / infaz tarihi' => 'Zaten kazanıldı / infaz edildi']],
            ['name' => 'onda_bir_net_sifir_baslangic_tarihi', 'tool' => 'one-tenth', 'input' => ['start_date' => '2026-01-01', 'days' => 100, 'credit_days' => 10], 'expected' => ['Net gün' => 0, 'Kazanma / infaz tarihi' => '2026-01-01']],
            ['name' => 'teror_net_sifir_zaten_kazanildi', 'tool' => 'terror-one-third', 'input' => ['start_date' => '2026-01-01', 'years' => 1, 'credit_days' => 121], 'expected' => ['Net gün' => 0, 'Kazanma / infaz tarihi' => 'Zaten kazanıldı / infaz edildi']],
            ['name' => 'ds1_tam_sinir', 'tool' => 'ds-one', 'input' => ['start_date' => '2026-01-01', 'value_days' => 365], 'expected' => ['Yararlanacağı gün' => 0, 'DS çıkış tarihi' => '2026-01-01']],
            ['name' => 'ds3_mahsup', 'tool' => 'ds-three', 'input' => ['start_date' => '2029-01-01', 'value_days' => 100], 'expected' => ['Yararlanacağı gün' => 995, 'DS çıkış tarihi' => '2026-04-12']],
            ['name' => '7550_sifir_gunde_bir_gun', 'tool' => 'law-7550', 'input' => ['start_date' => '2026-01-01', 'end_date' => '2026-01-01'], 'expected' => ['Kalacağı süre (gün)' => 0, '1/10 süre (gün)' => 1, '1/10 bitim tarihi' => '2026-01-02', 'En az 5 gün süre sonu' => '2026-01-06']],
            ['name' => '7550_yuz_gunde_onbir_gun', 'tool' => 'law-7550', 'input' => ['start_date' => '2026-01-01', 'end_date' => '2026-04-11'], 'expected' => ['Kalacağı süre (gün)' => 100, '1/10 süre (gün)' => 11, '1/10 bitim tarihi' => '2026-01-12']],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function verifyLegacyToolFixtures(): array
    {
        return collect($this->legacyToolFixtures())->map(function (array $fixture): array {
            $result = $this->calculateTool($fixture['tool'], $fixture['input']);
            $actual = collect($fixture['expected'])->mapWithKeys(fn ($value, $key) => [$key => $result[$key] ?? null])->all();

            return $fixture + ['actual' => $actual, 'passed' => $actual === $fixture['expected']];
        })->all();
    }
}
