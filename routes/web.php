<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\HousingController;
use App\Http\Controllers\Admin\InstitutionProfileController;
use App\Http\Controllers\Admin\JobPlaceController;
use App\Http\Controllers\Admin\LookupController;
use App\Http\Controllers\Admin\MenuModuleController;
use App\Http\Controllers\Admin\PhoneDirectoryController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\CountingController;
use App\Http\Controllers\HousingAssignmentController;
use App\Http\Controllers\InmateBulkActionController;
use App\Http\Controllers\InmateFileController;
use App\Http\Controllers\InmateIdentityPhotoController;
use App\Http\Controllers\InmatePermanentDeleteController;
use App\Http\Controllers\InmatePrivateFileController;
use App\Http\Controllers\InmateShortcutController;
use App\Http\Controllers\LegacyReportController;
use App\Http\Controllers\OperationModuleController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\ToolboxController;
use App\Http\Controllers\UnitRecordController;
use App\Http\Controllers\WorkAssignmentController;
use Illuminate\Support\Facades\Route;

Route::get('/status', StatusController::class)->name('status');

Route::middleware('guest')->group(function () {
    Route::get('/giris', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/giris', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', [InmateFileController::class, 'index'])->middleware('permission:inmates.view')->name('dashboard');
    Route::post('/cikis', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/hesabim/sifre', [PasswordController::class, 'edit'])->name('profile.password.edit');
    Route::put('/hesabim/sifre', [PasswordController::class, 'update'])->name('profile.password.update');
    Route::get('/telefon-rehberi', [PhoneDirectoryController::class, 'index'])->middleware('permission:phone.view')->name('phone-directory.index');
    Route::get('/sayim', [CountingController::class, 'index'])->middleware('permission:counting.view')->name('counting.index');
    Route::post('/sayim', [CountingController::class, 'store'])->middleware('permission:counting.update')->name('counting.store');
    Route::get('/hukumlu-kisayollari', [InmateShortcutController::class, 'index'])->middleware('permission:inmates.update')->name('inmates.shortcuts.index');
    Route::post('/hukumlu-kisayollari', [InmateShortcutController::class, 'update'])->middleware('permission:inmates.update')->name('inmates.shortcuts.update');
    Route::prefix('raporlar')->name('reports.')->group(function () {
        Route::get('izin', [LegacyReportController::class, 'leave'])->middleware('permission:leave.view')->name('leave');
        Route::get('sevk', [LegacyReportController::class, 'transfer'])->middleware('permission:execution.view')->name('transfer');
        Route::get('sevk/xlsx', [LegacyReportController::class, 'transferExport'])->middleware('permission:execution.view')->name('transfer.export');
        Route::get('teblig', [LegacyReportController::class, 'notification'])->middleware('permission:notification.view')->name('notification');
        Route::get('bakanlik-sevk', [LegacyReportController::class, 'ministryReferral'])->middleware('permission:notification.view')->name('ministry-referral');
    });

    Route::prefix('araclar')->name('tools.')->group(function () {
        Route::get('infaz-hesaplayici', [ToolboxController::class, 'calculator'])->middleware('permission:execution.view')->name('calculator');
        Route::get('raporlar', [ToolboxController::class, 'reports'])->middleware('permission:reports.view')->name('reports');
        Route::post('raporlar', [ToolboxController::class, 'storeReport'])->middleware('permission:reports.update')->name('reports.store');
        Route::get('disa-aktar', [ToolboxController::class, 'export'])->middleware('permission:inmates.export')->name('export');
        Route::get('istatistik', [ToolboxController::class, 'statistics'])->middleware('permission:statistics.view')->name('statistics');
        Route::get('mesem-istatistik', [ToolboxController::class, 'mesemStatistics'])->middleware('permission:mesem.view')->name('mesem-statistics');
    });

    Route::group([], function () {
        Route::get('operasyon/nobet/otomatik-planla', [OperationModuleController::class, 'autoPlanForm'])->name('operations.nobet.auto-plan-form');
        Route::post('operasyon/nobet/otomatik-planla', [OperationModuleController::class, 'autoPlan'])->name('operations.nobet.auto-plan');
    });

    Route::group([], function () {
        Route::get('operasyon/yemek/belge/{file}/indir', [OperationModuleController::class, 'downloadFile'])->name('operations.yemek.download-file');
        Route::post('operasyon/sohbet/gonder', [OperationModuleController::class, 'chatSend'])->name('operations.chat.send');
        Route::post('operasyon/sohbet/okundu/{record}', [OperationModuleController::class, 'chatMarkAsRead'])->name('operations.chat.read');
    });

    Route::prefix('operasyon/{module}')->name('operations.')->group(function () {
        Route::get('/', [OperationModuleController::class, 'index'])->name('index');
        Route::get('yeni', [OperationModuleController::class, 'create'])->name('create');
        Route::post('/', [OperationModuleController::class, 'store'])->name('store');
        Route::post('duyuru/{record}/okundu', [OperationModuleController::class, 'markAsRead'])->name('announcements.read');
        Route::post('duyurular/tumu-okundu', [OperationModuleController::class, 'markAllAsRead'])->name('announcements.read-all');
        Route::get('kayit/{record}/duzenle', [OperationModuleController::class, 'edit'])->name('edit');
        Route::put('kayit/{record}', [OperationModuleController::class, 'update'])->name('update');
        Route::patch('kayit/{record}/kapat', [OperationModuleController::class, 'close'])->name('close');
        Route::delete('kayit/{record}', [OperationModuleController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('birim-modulleri/{module}')->name('unit-records.')->group(function () {
        Route::get('/', [UnitRecordController::class, 'index'])->name('index');
        Route::get('hukumlu/{inmate}/yeni', [UnitRecordController::class, 'create'])->name('create');
        Route::post('hukumlu/{inmate}', [UnitRecordController::class, 'store'])->name('store');
        Route::get('kayit/{record}/duzenle', [UnitRecordController::class, 'edit'])->name('edit');
        Route::put('kayit/{record}', [UnitRecordController::class, 'update'])->name('update');
        Route::patch('kayit/{record}/kapat', [UnitRecordController::class, 'close'])->name('close');
    });

    Route::prefix('hukumlu-dosyalari/toplu')->name('inmates.bulk.')->group(function () {
        Route::get('/', [InmateBulkActionController::class, 'index'])->middleware('permission:inmates.update')->name('index');
        Route::post('ice-aktar', [InmateBulkActionController::class, 'import'])->middleware('permission:inmates.create')->name('import');
        Route::patch('durum', [InmateBulkActionController::class, 'status'])->middleware('permission:inmates.update')->name('status');
        Route::post('konum', [InmateBulkActionController::class, 'housing'])->middleware('permission:inmates.update')->name('housing');
        Route::delete('kalici-sil', [InmateBulkActionController::class, 'destroy'])->middleware('permission:inmates.delete')->name('destroy');
    });

    Route::get('hukumlu-dosyalari', [InmateFileController::class, 'index'])->middleware('permission:inmates.view')->name('inmates.index');
    Route::get('hukumlu-dosyalari/yeni', [InmateFileController::class, 'create'])->middleware('permission:inmates.create')->name('inmates.create');
    Route::get('kimlik-kartlari', [InmateIdentityPhotoController::class, 'batch'])->middleware('permission:inmates.print')->name('inmates.identity-cards');
    Route::post('hukumlu-dosyalari', [InmateFileController::class, 'store'])->middleware('permission:inmates.create')->name('inmates.store');
    Route::get('hukumlu-dosyalari/{inmate}/duzenle', [InmateFileController::class, 'edit'])->middleware('permission:inmates.view')->name('inmates.edit');
    Route::match(['put', 'patch'], 'hukumlu-dosyalari/{inmate}', [InmateFileController::class, 'update'])->middleware('permission:inmates.update')->name('inmates.update');
    Route::prefix('hukumlu-dosyalari/{inmate}/kimlik')->name('inmates.identity-photo.')->group(function () {
        Route::get('fotograf', [InmateIdentityPhotoController::class, 'create'])->middleware('permission:inmates.update')->name('create');
        Route::post('fotograf', [InmateIdentityPhotoController::class, 'store'])->middleware('permission:inmates.update')->name('store');
        Route::get('fotograf/{file}/goruntu', [InmateIdentityPhotoController::class, 'image'])->middleware('permission:inmates.view')->name('image');
        Route::delete('fotograf/{file}', [InmateIdentityPhotoController::class, 'destroy'])->middleware('permission:inmates.update')->name('destroy');
    });
    Route::get('hukumlu-dosyalari/{inmate}/kimlik-karti', [InmateIdentityPhotoController::class, 'card'])->middleware('permission:inmates.view')->name('inmates.identity-card');
    Route::middleware('permission:inmates.delete')->prefix('hukumlu-dosyalari/{inmate}/kalici-sil')->name('inmates.permanent-delete.')->group(function () {
        Route::get('/', [InmatePermanentDeleteController::class, 'create'])->name('create');
        Route::delete('/', [InmatePermanentDeleteController::class, 'destroy'])->name('destroy');
    });
    Route::middleware('permission:inmates.update')->prefix('hukumlu-dosyalari/{inmate}/konum')->name('inmates.housing.')->group(function () {
        Route::get('yeni', [HousingAssignmentController::class, 'create'])->name('create');
        Route::post('/', [HousingAssignmentController::class, 'store'])->name('store');
        Route::get('{assignment}/duzenle', [HousingAssignmentController::class, 'edit'])->name('edit');
        Route::put('{assignment}', [HousingAssignmentController::class, 'update'])->name('update');
        Route::patch('{assignment}/kapat', [HousingAssignmentController::class, 'close'])->name('close');
    });
    Route::middleware('permission:inmates.update')->prefix('hukumlu-dosyalari/{inmate}/gorev')->name('inmates.jobs.')->group(function () {
        Route::get('yeni', [WorkAssignmentController::class, 'create'])->name('create');
        Route::post('/', [WorkAssignmentController::class, 'store'])->name('store');
        Route::get('{assignment}/duzenle', [WorkAssignmentController::class, 'edit'])->name('edit');
        Route::put('{assignment}', [WorkAssignmentController::class, 'update'])->name('update');
        Route::patch('{assignment}/kapat', [WorkAssignmentController::class, 'close'])->name('close');
    });
    Route::prefix('hukumlu-dosyalari/{inmate}/private-dosyalar')->name('inmates.private-files.')->group(function () {
        Route::post('/', [InmatePrivateFileController::class, 'store'])->middleware('permission:inmates.update')->name('store');
        Route::get('{file}/indir', [InmatePrivateFileController::class, 'download'])->middleware('permission:inmates.view')->name('download');
    });

    Route::middleware('permission:admin.manage')->prefix('yonetim')->name('admin.')->group(function () {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::resource('birim-tanimlari', \App\Http\Controllers\Admin\UnitDefinitionController::class)->parameters(['birim-tanimlari' => 'unitDefinition'])->names('unit-definitions')->except(['show', 'destroy']);
        Route::get('kurum-profili', [InstitutionProfileController::class, 'edit'])->name('institution.edit');
        Route::put('kurum-profili', [InstitutionProfileController::class, 'update'])->name('institution.update');
        Route::resource('menuler', MenuModuleController::class)->parameters(['menuler' => 'menu'])->names('menus')->except(['show']);
        Route::resource('gorev-yerleri', JobPlaceController::class)->parameters(['gorev-yerleri' => 'place'])->names('job-places')->except(['show', 'destroy']);
        Route::get('telefon-rehberi', [PhoneDirectoryController::class, 'index'])->name('phone-directory.index');
        Route::get('telefon-rehberi/yeni', [PhoneDirectoryController::class, 'create'])->name('phone-directory.create');
        Route::post('telefon-rehberi', [PhoneDirectoryController::class, 'store'])->name('phone-directory.store');
        Route::get('telefon-rehberi/{entry}/duzenle', [PhoneDirectoryController::class, 'edit'])->name('phone-directory.edit');
        Route::put('telefon-rehberi/{entry}', [PhoneDirectoryController::class, 'update'])->name('phone-directory.update');
        Route::patch('telefon-rehberi/{entry}/durum', [PhoneDirectoryController::class, 'toggle'])->name('phone-directory.toggle');
        Route::get('yerlesim', [HousingController::class, 'index'])->name('housing.index');
        Route::get('yerlesim/blok/yeni', [HousingController::class, 'createBlock'])->name('housing.blocks.create');
        Route::post('yerlesim/blok', [HousingController::class, 'storeBlock'])->name('housing.blocks.store');
        Route::get('yerlesim/blok/{block}/duzenle', [HousingController::class, 'editBlock'])->name('housing.blocks.edit');
        Route::put('yerlesim/blok/{block}', [HousingController::class, 'updateBlock'])->name('housing.blocks.update');
        Route::get('yerlesim/kogus/yeni', [HousingController::class, 'createWard'])->name('housing.wards.create');
        Route::post('yerlesim/kogus', [HousingController::class, 'storeWard'])->name('housing.wards.store');
        Route::get('yerlesim/kogus/{ward}/duzenle', [HousingController::class, 'editWard'])->name('housing.wards.edit');
        Route::put('yerlesim/kogus/{ward}', [HousingController::class, 'updateWard'])->name('housing.wards.update');
        Route::get('yerlesim/ranza/yeni', [HousingController::class, 'createBed'])->name('housing.beds.create');
        Route::post('yerlesim/ranza', [HousingController::class, 'storeBed'])->name('housing.beds.store');
        Route::get('yerlesim/ranza/{bed}/duzenle', [HousingController::class, 'editBed'])->name('housing.beds.edit');
        Route::put('yerlesim/ranza/{bed}', [HousingController::class, 'updateBed'])->name('housing.beds.update');
        Route::get('tanimlar', [LookupController::class, 'index'])->name('lookups.index');
        Route::get('tanimlar/grup/yeni', [LookupController::class, 'createGroup'])->name('lookups.groups.create');
        Route::post('tanimlar/grup', [LookupController::class, 'storeGroup'])->name('lookups.groups.store');
        Route::get('tanimlar/grup/{group}/duzenle', [LookupController::class, 'editGroup'])->name('lookups.groups.edit');
        Route::put('tanimlar/grup/{group}', [LookupController::class, 'updateGroup'])->name('lookups.groups.update');
        Route::patch('tanimlar/grup/{group}/durum', [LookupController::class, 'toggleGroup'])->name('lookups.groups.toggle');
        Route::get('tanimlar/grup/{group}/kalem/yeni', [LookupController::class, 'createItem'])->name('lookups.items.create');
        Route::post('tanimlar/grup/{group}/kalem', [LookupController::class, 'storeItem'])->name('lookups.items.store');
        Route::get('tanimlar/grup/{group}/kalem/{item}/duzenle', [LookupController::class, 'editItem'])->name('lookups.items.edit');
        Route::put('tanimlar/grup/{group}/kalem/{item}', [LookupController::class, 'updateItem'])->name('lookups.items.update');
        Route::patch('tanimlar/grup/{group}/kalem/{item}/durum', [LookupController::class, 'toggleItem'])->name('lookups.items.toggle');
        Route::resource('kullanicilar', UserController::class)->parameters(['kullanicilar' => 'user'])->names('users')->except(['show', 'destroy']);
        Route::patch('kullanicilar/{user}/parola', [UserController::class, 'resetPassword'])->name('users.password.update');
        Route::patch('kullanicilar/{user}/durum', [UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::delete('kullanicilar/{user}/oturumlar', [UserController::class, 'destroySessions'])->name('users.sessions.destroy');
    });
});
