<?php

namespace Tests\Unit;

use App\Services\SentenceCalculator;
use PHPUnit\Framework\TestCase;

class SentenceCalculatorLegacyEquivalenceTest extends TestCase
{
    public function test_legacy_tools_preserve_their_exact_day_math(): void
    {
        $calculator = new SentenceCalculator;

        $this->assertSame('2026-01-11', $calculator->calculateTool('apc', ['start_date' => '2026-01-01', 'value_days' => 10])['APC bitiş tarihi']);
        $this->assertSame(121, $calculator->calculateTool('terror-one-third', ['start_date' => '2026-01-01', 'years' => 1, 'credit_days' => 0])['1/3 gün']);
        $this->assertSame('2026-01-21', $calculator->calculateTool('fine', ['start_date' => '2026-01-31', 'value_days' => 10])['Yeni tahliye tarihi']);
        $this->assertSame(36, $calculator->calculateTool('one-tenth', ['start_date' => '2026-01-01', 'years' => 1])['1/10 gün']);
        $this->assertSame(265, $calculator->calculateTool('ds-one', ['start_date' => '2027-01-01', 'value_days' => 100])['Yararlanacağı gün']);
        $this->assertSame(995, $calculator->calculateTool('ds-three', ['start_date' => '2029-01-01', 'value_days' => 100])['Yararlanacağı gün']);
        $this->assertSame('2026-01-12', $calculator->calculateTool('law-7550', ['start_date' => '2026-01-01', 'end_date' => '2026-04-11'])['1/10 bitim tarihi']);
        $this->assertSame(10, $calculator->calculateTool('date-interval', ['start_date' => '2026-01-01', 'end_date' => '2026-01-11'])['Tarih aralığı (gün)']);
    }

    public function test_all_legacy_tool_fixtures_pass(): void
    {
        $results = (new SentenceCalculator)->verifyLegacyToolFixtures();

        $this->assertCount(12, $results);
        $this->assertTrue(collect($results)->every(fn (array $fixture) => $fixture['passed']));
    }

    public function test_negative_date_ranges_are_rejected(): void
    {
        $calculator = new SentenceCalculator;

        foreach (['date-interval', 'law-7550'] as $tool) {
            try {
                $calculator->calculateTool($tool, ['start_date' => '2026-01-02', 'end_date' => '2026-01-01']);
                $this->fail($tool.' negatif tarih aralığını reddetmedi.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_generic_calculator_uses_legacy_floor_rule_for_one_tenth(): void
    {
        $result = (new SentenceCalculator)->calculate([
            'start_date' => '2026-01-01',
            'days' => 99,
            'ratio' => 1 / 3,
        ]);

        $this->assertSame(9, $result['one_tenth_days']);
    }
}
