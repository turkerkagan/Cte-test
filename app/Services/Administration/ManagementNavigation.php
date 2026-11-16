<?php

namespace App\Services\Administration;

final class ManagementNavigation
{
    public function groups(): array
    {
        // Keep the same groups, order, labels and icons as admin/yonetim.php.
        $link = fn ($label, $route, $icon, $parameters = []) => ['label' => $label, 'url' => route($route, $parameters), 'icon' => $icon];

        return [
            ['label' => 'Genel Bakış', 'url' => '#admin-overview', 'children' => [$link('Genel Bakış', 'management.index', 'chart-line')]],
            ['label' => 'Kullanıcılar ve Erişim', 'url' => '#admin-access', 'children' => [
                $link('Kullanıcılar ve Erişim', 'users.index', 'users'),
                $link('Erişim Politikaları', 'page-access.index', 'shield-halved'),
            ]],
            ['label' => 'Kurum Yapısı', 'url' => '#admin-organization', 'children' => [
                $link('Personel Kadrosu', 'management.staff', 'sitemap'),
                $link('Kurum Birimleri', 'organization.index', 'building', 'birimler'),
                $link('Unvanlar', 'organization.index', 'id-badge', 'unvanlar'),
                $link('Telefon Rehberi', 'management.phone', 'phone'),
                $link('Kurul Başlıkları', 'management.boards', 'gavel'),
            ]],
            ['label' => 'İçerik', 'url' => '#admin-content', 'children' => [
                $link('Giriş & Footer Metinleri', 'management.appearance', 'paint-roller'),
            ]],
            ['label' => 'Raporlama ve Denetim', 'url' => '#admin-audit', 'children' => [
                $link('Kullanıcı Girişleri', 'management.logins', 'user-clock'),
                $link('İşlem Kayıtları', 'management.audit', 'clipboard-list'),
            ]],
            ['label' => 'Sistem ve Güvenlik', 'url' => '#admin-system', 'children' => [
                $link('Veritabanı & Sistem', 'management.database', 'database'),
                $link('Kurtarma & Dosya Yöneticisi', 'management.recovery', 'toolbox'),
            ]],
        ];
    }
}
