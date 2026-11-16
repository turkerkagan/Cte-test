<?php

declare(strict_types=1);

namespace App\Services\Institution\Parsers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use XMLReader;

/**
 * UYAP'ın JasperPrint XML çıktısını okur ve hükümlüleri TC kimlik numarasıyla
 * uygulamadaki `tablo` kayıtlarıyla karşılaştırır.
 */
function uyap_tc_normalize(?string $value): string
{
    return preg_replace('/\D+/', '', (string) $value) ?? '';
}

function uyap_name_normalize(?string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
    $value = strtr($value, ['i' => 'İ', 'ı' => 'I']);

    return mb_strtoupper($value, 'UTF-8');
}

function uyap_xml_text_cell(DOMXPath $xpath, DOMElement $text): ?array
{
    $reportElement = ($xpath->query('./*[local-name()="reportElement"]', $text) ?: null)?->item(0);
    $textContent = ($xpath->query('./*[local-name()="textContent"]', $text) ?: null)?->item(0);
    if (! $reportElement instanceof DOMElement || ! $textContent instanceof DOMNode) {
        return null;
    }

    return [
        'x' => (int) $reportElement->getAttribute('x'),
        'y' => (int) $reportElement->getAttribute('y'),
        'value' => preg_replace('/\s+/u', ' ', trim($textContent->textContent)) ?? '',
    ];
}

function uyap_xml_parse(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        throw new RuntimeException('Yüklenen XML dosyası okunamadı.');
    }
    if (! class_exists(XMLReader::class)) {
        throw new RuntimeException('Sunucuda XMLReader desteği etkin değil.');
    }

    $previousErrors = libxml_use_internal_errors(true);
    $reader = new XMLReader;
    $records = [];
    $invalid = [];
    $pageCount = 0;
    $rootSeen = false;
    $xmlErrors = [];

    try {
        if (! $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('Dosya geçerli bir XML belgesi değil.');
        }
        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::VALIDATE, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::DOC_TYPE) {
                throw new RuntimeException('Harici varlık veya belge türü içeren XML dosyaları kabul edilmez.');
            }
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if (! $rootSeen) {
                $rootSeen = true;
                if ($reader->localName !== 'jasperPrint') {
                    throw new RuntimeException('Bu dosya desteklenen UYAP JasperPrint XML formatında değil.');
                }

                continue;
            }
            if ($reader->localName === 'page') {
                $pageCount++;

                continue;
            }
            if ($reader->localName !== 'frame' || $pageCount === 0) {
                continue;
            }

            // Yalnız o anki frame belleğe alınır; büyük XML dosyasının tamamı yüklenmez.
            $frameXml = $reader->readOuterXML();
            if ($frameXml === '') {
                continue;
            }
            $frameDocument = new DOMDocument;
            if (! $frameDocument->loadXML($frameXml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                continue;
            }
            $xpath = new DOMXPath($frameDocument);
            $rows = [];
            $texts = $xpath->query('//*[local-name()="text"]');
            if ($texts === false) {
                continue;
            }
            foreach ($texts as $text) {
                if (! $text instanceof DOMElement) {
                    continue;
                }
                $cell = uyap_xml_text_cell($xpath, $text);
                if ($cell !== null) {
                    $rows[$cell['y']][$cell['x']] = $cell['value'];
                }
            }
            foreach ($rows as $cells) {
                $sequence = trim((string) ($cells[0] ?? ''));
                if ($sequence === '' || ! ctype_digit($sequence)) {
                    continue;
                }
                $rawTc = trim((string) ($cells[30] ?? ''));
                $tc = uyap_tc_normalize($rawTc);
                $record = [
                    'sira_no' => (int) $sequence,
                    'tc' => $tc,
                    'ad_soyad' => trim((string) ($cells[100] ?? '')),
                    'sayfa' => $pageCount,
                ];
                if (strlen($tc) !== 11) {
                    $record['neden'] = $rawTc === '' ? 'TC kimlik numarası boş' : 'TC kimlik numarası 11 haneli değil';
                    $invalid[] = $record;
                } else {
                    $records[] = $record;
                }
            }
        }
    } finally {
        $reader->close();
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
    }

    if ($xmlErrors !== []) {
        throw new RuntimeException('Dosya geçerli ve eksiksiz bir XML belgesi değil.');
    }

    if (! $rootSeen) {
        throw new RuntimeException('Dosya geçerli bir XML belgesi değil.');
    }
    if ($pageCount === 0) {
        throw new RuntimeException('XML içinde UYAP rapor sayfası bulunamadı.');
    }

    if ($records === [] && $invalid === []) {
        throw new RuntimeException('XML içinde beklenen UYAP hükümlü satırları bulunamadı.');
    }

    return ['records' => $records, 'invalid' => $invalid, 'page_count' => $pageCount];
}

function uyap_compare_with_database(array $applicationRows, array $parsed): array
{
    $uyapByTc = [];
    foreach ($parsed['records'] as $record) {
        $uyapByTc[$record['tc']][] = $record;
    }

    $applicationByTc = [];
    $invalidApplication = [];
    foreach ($applicationRows as $row) {
        $tc = uyap_tc_normalize($row['huk_tc'] ?? '');
        $normalized = [
            'id' => (int) $row['id'],
            'tc' => $tc,
            'ad_soyad' => (string) ($row['huk_adi'] ?? ''),
            'blok' => (string) ($row['huk_blok'] ?? ''),
            'kogus' => (string) ($row['huk_kogus'] ?? ''),
            'ranza' => (string) ($row['huk_ranza'] ?? ''),
            'calisma_yeri' => (string) ($row['huk_calis'] ?? ''),
            'calisma_karari' => (string) ($row['huk_karar'] ?? ''),
            'durum' => (string) ($row['huk_durum'] ?? ''),
        ];
        if (strlen($tc) !== 11) {
            $normalized['neden'] = $tc === '' ? 'TC kimlik numarası boş' : 'TC kimlik numarası 11 haneli değil';
            $invalidApplication[] = $normalized;

            continue;
        }
        $applicationByTc[$tc][] = $normalized;
    }

    $uyapOnly = [];
    $applicationOnly = [];
    $matched = [];
    $nameMismatch = [];

    foreach ($uyapByTc as $tc => $records) {
        $uyap = $records[0];
        if (! isset($applicationByTc[$tc])) {
            $uyapOnly[] = [
                'tc' => $tc,
                'uyap_adi' => $uyap['ad_soyad'],
                'uyap_sira_no' => $uyap['sira_no'],
            ];

            continue;
        }

        $application = $applicationByTc[$tc][0];
        $item = [
            'tc' => $tc,
            'uyap_adi' => $uyap['ad_soyad'],
            'uygulama_adi' => $application['ad_soyad'],
            'id' => $application['id'],
            'blok' => $application['blok'],
            'kogus' => $application['kogus'],
            'ranza' => $application['ranza'],
            'calisma_yeri' => $application['calisma_yeri'],
            'calisma_karari' => $application['calisma_karari'],
            'durum' => $application['durum'],
        ];
        $matched[] = $item;
        if (uyap_name_normalize($uyap['ad_soyad']) !== uyap_name_normalize($application['ad_soyad'])) {
            $nameMismatch[] = $item;
        }
    }

    foreach ($applicationByTc as $tc => $records) {
        if (isset($uyapByTc[$tc])) {
            continue;
        }
        $application = $records[0];
        $applicationOnly[] = [
            'tc' => $tc,
            'uygulama_adi' => $application['ad_soyad'],
            'id' => $application['id'],
            'blok' => $application['blok'],
            'kogus' => $application['kogus'],
            'ranza' => $application['ranza'],
            'calisma_yeri' => $application['calisma_yeri'],
            'calisma_karari' => $application['calisma_karari'],
            'durum' => $application['durum'],
        ];
    }

    $uyapDuplicates = [];
    foreach ($uyapByTc as $tc => $records) {
        if (count($records) > 1) {
            $uyapDuplicates[] = [
                'tc' => $tc,
                'ad_soyad' => $records[0]['ad_soyad'],
                'adet' => count($records),
            ];
        }
    }

    $applicationDuplicates = [];
    foreach ($applicationByTc as $tc => $records) {
        if (count($records) > 1) {
            $applicationDuplicates[] = [
                'tc' => $tc,
                'ad_soyad' => $records[0]['ad_soyad'],
                'adet' => count($records),
                'kayit_idleri' => array_column($records, 'id'),
            ];
        }
    }

    $sortByName = static function (array &$items): void {
        usort($items, static function (array $a, array $b): int {
            $nameA = $a['uyap_adi'] ?? $a['uygulama_adi'] ?? $a['ad_soyad'] ?? '';
            $nameB = $b['uyap_adi'] ?? $b['uygulama_adi'] ?? $b['ad_soyad'] ?? '';

            return strcmp(uyap_name_normalize($nameA), uyap_name_normalize($nameB));
        });
    };
    $sortByName($uyapOnly);
    $sortByName($applicationOnly);
    $sortByName($matched);
    $sortByName($nameMismatch);
    $sortByName($uyapDuplicates);
    $sortByName($applicationDuplicates);

    $uyapAll = [];
    foreach ($parsed['records'] as $record) {
        $application = $applicationByTc[$record['tc']][0] ?? null;
        $uyapAll[] = [
            'tc' => $record['tc'],
            'uyap_adi' => $record['ad_soyad'],
            'uygulama_adi' => $application['ad_soyad'] ?? '',
            'id' => $application['id'] ?? null,
            'blok' => $application['blok'] ?? '',
            'kogus' => $application['kogus'] ?? '',
            'ranza' => $application['ranza'] ?? '',
            'calisma_yeri' => $application['calisma_yeri'] ?? '',
            'calisma_karari' => $application['calisma_karari'] ?? '',
            'durum' => $application['durum'] ?? 'Uygulamada kayıtlı değil',
        ];
    }
    foreach ($parsed['invalid'] as $record) {
        $uyapAll[] = [
            'tc' => $record['tc'],
            'uyap_adi' => $record['ad_soyad'],
            'neden' => $record['neden'],
        ];
    }

    $validApplicationRows = $applicationByTc === []
        ? []
        : array_merge(...array_values($applicationByTc));
    $applicationAll = array_map(static function (array $row): array {
        return [
            'id' => $row['id'],
            'tc' => $row['tc'],
            'uygulama_adi' => $row['ad_soyad'],
            'blok' => $row['blok'],
            'kogus' => $row['kogus'],
            'ranza' => $row['ranza'],
            'calisma_yeri' => $row['calisma_yeri'],
            'calisma_karari' => $row['calisma_karari'],
            'durum' => $row['durum'],
        ];
    }, $validApplicationRows);
    foreach ($invalidApplication as $row) {
        $applicationAll[] = $row;
    }

    return [
        'summary' => [
            'uyap_satir' => count($parsed['records']) + count($parsed['invalid']),
            'uyap_gecerli_satir' => count($parsed['records']),
            'uyap_benzersiz_tc' => count($uyapByTc),
            'uygulama_kayit' => count($applicationRows),
            'uygulama_gecerli_tc' => array_sum(array_map('count', $applicationByTc)),
            'uygulama_benzersiz_tc' => count($applicationByTc),
            'eslesen' => count($matched),
            'uyapta_var_uygulamada_yok' => count($uyapOnly),
            'uygulamada_var_uyapta_yok' => count($applicationOnly),
            'ad_soyad_farkli' => count($nameMismatch),
            'uyap_mukerrer_tc' => count($uyapDuplicates),
            'uygulama_mukerrer_tc' => count($applicationDuplicates),
            'uyap_gecersiz_tc' => count($parsed['invalid']),
            'uygulama_gecersiz_tc' => count($invalidApplication),
            'sayfa_sayisi' => (int) $parsed['page_count'],
        ],
        'uyap_all' => $uyapAll,
        'application_all' => $applicationAll,
        'uyap_only' => $uyapOnly,
        'application_only' => $applicationOnly,
        'matched' => $matched,
        'name_mismatch' => $nameMismatch,
        'uyap_duplicates' => $uyapDuplicates,
        'application_duplicates' => $applicationDuplicates,
        'invalid_uyap' => $parsed['invalid'],
        'invalid_application' => $invalidApplication,
    ];
}
