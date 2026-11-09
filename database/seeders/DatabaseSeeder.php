<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $institution = Institution::updateOrCreate(
            ['short_name' => 'CTE'],
            ['name' => 'Ceza İnfaz Kurumu', 'is_active' => true],
        );

        $departmentNames = [
            'yonetim' => 'Yönetim', 'basmemurluk' => 'Sorumlu Başmemurluk',
            'idari' => 'İdari Birim', 'infaz' => 'İnfaz Birimi', 'egitim' => 'Eğitim Servisi',
            'revir' => 'Revir', 'ziyaret' => 'Ziyaret', 'manevi' => 'Manevi Rehberlik',
            'psikososyal' => 'Psikososyal Servis', 'mesem' => 'MESEM', 'mutfak' => 'Mutfak',
        ];
        foreach ($departmentNames as $code => $name) {
            Department::updateOrCreate(
                ['institution_id' => $institution->id, 'code' => $code],
                ['name' => $name, 'is_sensitive' => in_array($code, ['revir', 'psikososyal'], true)],
            );
        }

        $roleNames = [
            'superadmin' => 'Ana Yönetici', 'basmemur' => 'Başmemur', 'sbasmemur' => 'Sorumlu Başmemur',
            'idari' => 'İdari', 'infaz' => 'İnfaz', 'infaz2' => 'İnfaz 2', 'egitim' => 'Eğitim',
            'revir' => 'Revir', 'ziyaret' => 'Ziyaret', 'manevi' => 'Manevi Rehberlik',
            'psikososyal' => 'Psikososyal', 'mesem' => 'MESEM', 'mutfak' => 'Mutfak',
            'standart' => 'Standart Personel',
        ];
        $roles = [];
        foreach ($roleNames as $code => $name) {
            $roles[$code] = Role::updateOrCreate(
                ['institution_id' => $institution->id, 'code' => $code],
                ['name' => $name, 'is_system' => true],
            );
        }

        $modules = [
            'dashboard' => 'Ana Panel', 'inmates' => 'Hükümlüler', 'intake' => 'Sorumlu Başmemurluk',
            'health' => 'Revir', 'psychosocial' => 'Psikososyal', 'execution' => 'İnfaz',
            'leave' => 'İzin', 'notification' => 'Tebliğ', 'referral' => 'Bakanlık Sevk',
            'visit' => 'Ziyaret', 'spiritual' => 'Manevi Rehberlik', 'education' => 'Eğitim',
            'mesem' => 'MESEM', 'evaluation' => 'Değerlendirme', 'counting' => 'Sayım ve Koğuş',
            'duty' => 'Nöbetler', 'committee' => 'Kurul ve Komisyon', 'meal' => 'Yemek',
            'announcement' => 'Duyuru', 'chat' => 'Sohbet', 'phone' => 'Telefon Rehberi',
            'reports' => 'Raporlar', 'statistics' => 'İstatistik', 'admin' => 'Yönetim',
        ];
        $actions = ['view' => 'Görüntüle', 'create' => 'Ekle', 'update' => 'Düzenle', 'delete' => 'Sil', 'print' => 'Yazdır', 'export' => 'Dışa Aktar', 'report' => 'Raporla', 'manage' => 'Yönet'];
        foreach ($modules as $module => $label) {
            DB::table('modules')->updateOrInsert(['code' => $module], ['name' => $label, 'is_sensitive' => in_array($module, ['health', 'psychosocial'], true), 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]);
            foreach ($actions as $action => $actionLabel) {
                Permission::updateOrCreate(
                    ['key' => "$module.$action"],
                    ['module' => $module, 'action' => $action, 'label' => "$label — $actionLabel", 'is_sensitive' => in_array($module, ['health', 'psychosocial'], true)],
                );
            }
        }
        Permission::updateOrCreate(['key' => 'inmates.bulk-delete'], ['module' => 'inmates', 'action' => 'bulk-delete', 'label' => 'Hükümlüler — Toplu Sil', 'is_sensitive' => true]);

        $dashboardPermission = Permission::where('key', 'dashboard.view')->firstOrFail();
        $inmatesViewPermission = Permission::where('key', 'inmates.view')->firstOrFail();
        foreach ($roles as $role) {
            $role->permissions()->syncWithoutDetaching([$dashboardPermission->id, $inmatesViewPermission->id]);
        }

        foreach (['basmemur', 'sbasmemur', 'idari'] as $roleCode) {
            $writePermissions = Permission::where('module', 'inmates')
                ->whereIn('action', ['create', 'update', 'print', 'export', 'report'])
                ->pluck('id');
            $roles[$roleCode]->permissions()->syncWithoutDetaching($writePermissions);
        }

        $grant = function (string $roleCode, array $moduleCodes, array $permissionActions = ['view']) use ($roles): void {
            $permissionIds = Permission::whereIn('module', $moduleCodes)
                ->whereIn('action', $permissionActions)
                ->pluck('id');
            $roles[$roleCode]->permissions()->syncWithoutDetaching($permissionIds);
        };

        $commonPages = ['announcement', 'duty', 'committee', 'meal', 'chat', 'phone', 'counting', 'statistics'];
        foreach (array_keys($roles) as $roleCode) {
            $grant($roleCode, $commonPages, ['view']);
        }

        $unitRoleModules = [
            'basmemur' => ['intake', 'evaluation', 'execution', 'leave', 'notification', 'referral', 'visit', 'spiritual', 'education', 'mesem'],
            'sbasmemur' => ['intake', 'evaluation', 'execution', 'leave', 'notification', 'referral', 'visit', 'spiritual', 'education', 'mesem'],
            'idari' => ['intake', 'evaluation', 'execution', 'leave', 'notification', 'referral', 'visit', 'spiritual', 'education', 'mesem'],
            'infaz' => ['execution', 'leave', 'notification', 'referral'],
            'infaz2' => ['execution', 'leave', 'notification', 'referral'],
            'egitim' => ['education'],
            'revir' => ['health'],
            'ziyaret' => ['visit'],
            'manevi' => ['spiritual'],
            'psikososyal' => ['psychosocial'],
            'mesem' => ['mesem'],
            'mutfak' => ['meal'],
        ];
        foreach ($unitRoleModules as $roleCode => $moduleCodes) {
            $grant($roleCode, $moduleCodes, ['view', 'create', 'update', 'print', 'export', 'report']);
        }

        foreach (['basmemur', 'sbasmemur', 'idari'] as $roleCode) {
            $grant($roleCode, ['announcement', 'duty', 'committee', 'meal', 'chat', 'counting'], ['create', 'update', 'delete', 'print', 'export', 'report']);
        }

        $adminManagePermission = Permission::where('key', 'admin.manage')->firstOrFail();
        $roles['superadmin']->permissions()->syncWithoutDetaching([$adminManagePermission->id]);

        $password = env('CTE_INITIAL_ADMIN_PASSWORD');
        if (! is_string($password) || strlen($password) < 8) {
            throw new RuntimeException('CTE_INITIAL_ADMIN_PASSWORD en az 8 karakter olarak .env dosyasında tanımlanmalıdır.');
        }

        User::updateOrCreate(
            ['login_name' => env('CTE_INITIAL_ADMIN_LOGIN', 'türker')],
            [
                'institution_id' => $institution->id,
                'department_id' => Department::where('code', 'yonetim')->value('id'),
                'role_id' => $roles['superadmin']->id,
                'full_name' => 'Türker',
                'personnel_number' => null,
                'email' => null,
                'password' => $password,
                'is_active' => true,
                'is_protected_superadmin' => true,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ],
        );

        $this->call(LegacyInstitutionDefinitionsSeeder::class);
    }
}
