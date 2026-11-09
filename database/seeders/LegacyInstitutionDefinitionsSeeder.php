<?php

namespace Database\Seeders;

use App\Models\Bed;
use App\Models\Block;
use App\Models\Institution;
use App\Models\LookupGroup;
use App\Models\LookupItem;
use App\Models\Ward;
use App\Models\WorkPlace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LegacyInstitutionDefinitionsSeeder extends Seeder
{
    public function run(): void
    {
        $institution = Institution::where('short_name', 'CTE')->firstOrFail();
        $this->seedLookups();
        $this->seedHousing($institution);
        $this->seedWorkPlaces($institution);
    }

    private function seedLookups(): void
    {
        $groups = [
            'inmate_status' => ['Hükümlü Durumu', ['YENİ KAYIT', 'MEVCUT', 'MİSAFİR', 'HASTANE', 'ÖĞRENCİ', 'ÖZEL İZİN', 'VERİ DEĞİŞİKLİĞİ']],
            'risk_status' => ['Değerlendirme', ['1', '2', '3']],
            'decision' => ['Çalışma Kararı', ['KARARSIZ', 'MUHTELİF', 'İŞYURTLARI', 'GENEL BÜTÇE']],
        ];

        foreach ($groups as $code => [$name, $labels]) {
            $group = LookupGroup::updateOrCreate(['code' => $code], ['name' => $name, 'is_system' => true, 'is_active' => true]);
            foreach ($labels as $index => $label) {
                LookupItem::updateOrCreate(
                    ['definition_group_id' => $group->id, 'code' => $label],
                    ['label' => $label, 'is_system' => true, 'is_active' => true, 'sort_order' => $index + 1],
                );
            }
        }
    }

    private function seedHousing(Institution $institution): void
    {
        $topology = [
            'A' => array_fill_keys(range(1, 24), 12),
            'B' => array_fill_keys(range(1, 24), 12),
            'C' => [1 => 10, 2 => 10, 3 => 12, 4 => 14, 5 => 18, 6 => 12, 7 => 12, 8 => 128, 'GEÇİCİ KOĞUŞ' => 0],
            'ENGELLİ KOĞUŞU' => ['ENGELLİ KOĞUŞU 1' => 12, 'ENGELLİ KOĞUŞU 2' => 26],
            'HASTANE' => ['SUAT SEREN E.A.H' => 0, 'YEŞİLYURT A.E.A.H' => 0, 'İZMİR ŞEHİR HASTANESİ' => 0, 'BUCA SEYFİ DEMİRSOY HASTANESİ' => 0, 'İZMİR EGE ÜNİVERSİTESİ HASTANESİ' => 0],
            'İZOLASYON KOĞUŞU' => ['İZOLASYON KOĞUŞU' => 0],
            'HAFTA SONU KOĞUŞU' => ['HAFTA SONU KOĞUŞU' => 0],
        ];

        foreach ($topology as $blockIndex => $wards) {
            $block = Block::updateOrCreate(
                ['institution_id' => $institution->id, 'code' => Str::slug((string) $blockIndex)],
                ['name' => (string) $blockIndex, 'sort_order' => array_search($blockIndex, array_keys($topology), true) + 1, 'is_active' => true],
            );
            $wardOrder = 1;
            foreach ($wards as $wardName => $capacity) {
                $ward = Ward::updateOrCreate(
                    ['block_id' => $block->id, 'code' => Str::slug((string) $wardName)],
                    ['name' => (string) $wardName, 'capacity' => $capacity, 'sort_order' => $wardOrder++, 'is_active' => true],
                );
                for ($bedNo = 1; $bedNo <= $capacity; $bedNo++) {
                    Bed::updateOrCreate(['ward_id' => $ward->id, 'label' => (string) $bedNo], ['sort_order' => $bedNo, 'is_active' => true]);
                }
            }
        }
    }

    private function seedWorkPlaces(Institution $institution): void
    {
        $names = [
            'A GRUBU', 'A-B BLOK KAT TEMİZLİK', 'ANONS', 'BAHÇIVAN', 'BELEDİYE TEMİZLİK', 'BERBERHANE', 'BOYACI', 'C BLOK KAT TEMİZLİK',
            'ÇAMAŞIRHANE', 'ÇEVRE TEMİZLİK', 'DEMİR ATÖLYESİ', 'DS KANTİN', 'EĞİTİM BİNASI ÇAY OCAĞI', 'ELEKTRİK ATÖLYESİ', 'FIRIN', 'FIRIN (EKMEK DAĞITIM)',
            'GAZİNO ÇAY OCAĞI', 'GENEL YIKAMA', 'GERİ DÖNÜŞÜM', 'GERİ HİZMET', 'HASTA BAKICI', 'HÜKÜMLÜ BİNA BAHÇE KAPI', 'HÜKÜMLÜ BİNA ÇAY OCAĞI',
            'HÜKÜMLÜ BİNA TEMİZLİK', 'HÜKÜMLÜ TELEFON BİNA TEMİZLİK', 'HÜKÜMLÜ YEMEKHANE', 'İDARİ BİNA ÇAY OCAĞI', 'İNŞAAT ATÖLYESİ',
            'İNŞAAT ATÖLYESİ (TAŞ)', 'KADEME', 'KANTİN', 'KEPÇE', 'LOJMAN TEMİZLİK', 'MAHKUM KABUL', 'MARANGOZ ATÖLYESİ', 'MEMUR POSTA',
            'MEMUR YEMEKHANE', 'MEZARLIK', 'MUTFAK', 'NAKLİYE', 'ÖĞRENCİ', 'ORMAN', 'PARK BAHÇE', 'PEYZAJ', 'REVİR', 'SAVCILIK', 'SERA',
            'SIHHI TESİSAT', 'SOSYAL TESİS', 'SOSYAL TESİS BERBERHANE', 'SOSYAL TESİS TERZİHANE', 'ŞOFÖR', 'TARIM', 'TATLI YAPIM ATÖLYESİ',
            'TERSANE', 'TERSANE ALAYBEY', 'TEKSTİL ATÖLYESİ', 'TERZİ ATÖLYESİ', 'Y.G.C. TEMİZLİK', 'ZİYARET KABUL KAFETERYA',
        ];

        foreach ($names as $index => $name) {
            WorkPlace::updateOrCreate(
                ['institution_id' => $institution->id, 'code' => Str::slug($name)],
                ['name' => $name, 'sort_order' => $index + 1, 'is_active' => true],
            );
        }
    }
}
