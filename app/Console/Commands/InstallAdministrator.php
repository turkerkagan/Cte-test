<?php

namespace App\Console\Commands;

use App\Services\Security\InitialAdministrator;
use Illuminate\Console\Command;
use Throwable;

final class InstallAdministrator extends Command
{
    protected $signature = 'portal:install-admin {username} {--name= : İlk yöneticinin ad soyadı}';

    protected $description = 'Boş hedefte ilk yöneticiyi bir kez oluşturur; parolayı gizli terminal girdisiyle alır';

    public function handle(InitialAdministrator $installer): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('İlk yönetici kurulumu etkileşimli terminal gerektirir.');

            return self::FAILURE;
        }
        $password = $this->secret('Yeni yönetici parolası (en az 6 karakter)', false);
        $confirmation = $this->secret('Parolayı tekrar girin', false);
        if (! is_string($password) || $password !== $confirmation) {
            $this->error('Parolalar eşleşmedi; hesap oluşturulmadı.');

            return self::FAILURE;
        }
        try {
            $installer->create((string) $this->argument('username'), $password, $this->option('name'));
            $this->info('İlk yönetici oluşturuldu. Parola açık metin tutulmadı; örnek kurum kaydı eklenmedi.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Kurulum tamamlanamadı. Boş hedef şemasını ve kullanıcı/parola kurallarını kontrol edin.');

            return self::FAILURE;
        }
    }
}
