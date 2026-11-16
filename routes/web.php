<?php

use App\Http\Controllers\AccountRequestController;
use App\Http\Controllers\BulkStatusController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ComparisonController;
use App\Http\Controllers\ExcelMergeController;
use App\Http\Controllers\FoundationController;
use App\Http\Controllers\HousingController;
use App\Http\Controllers\InstitutionController;
use App\Http\Controllers\LegacyWorkflowController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\MealsController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\ModuleDesignerController;
use App\Http\Controllers\ModuleRuntimeController;
use App\Http\Controllers\OptionCatalogController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PageAccessController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PersonalMessagesController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PersonDocumentsController;
use App\Http\Controllers\PersonNotesController;
use App\Http\Controllers\PersonPhotoController;
use App\Http\Controllers\PlacementController;
use App\Http\Controllers\PortalHealthController;
use App\Http\Controllers\RoomListsController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StaffTreeController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\UserAdministrationController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\WorkspaceController;
use App\Services\People\PortalIndicators;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', FoundationController::class)->name('foundation');
Route::middleware('guest')->group(function (): void {
    Route::post('/misafir', [SessionController::class, 'guest'])->middleware('throttle:10,1')->name('login.guest');
    foreach (['application' => 'basvuru', 'password' => 'sifre-talebi'] as $kind => $path) {
        Route::get('/'.$path, [AccountRequestController::class, 'index'])->defaults('request_kind', $kind)->name('account-request.'.$kind);
        Route::post('/'.$path, [AccountRequestController::class, 'store'])->defaults('request_kind', $kind)->middleware('throttle:5,10');
    }
    Route::get('/giris', [SessionController::class, 'create'])->name('login');
    Route::post('/giris', [SessionController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
});
Route::middleware(['auth', 'portal.session'])->group(function (): void {
    Route::get('/calisma-merkezi', [WorkspaceController::class, 'index'])->name('workspace.index');
    Route::match(['GET', 'POST'], '/calisma-merkezi/yedek-provalari', [WorkspaceController::class, 'rehearsal']);
    Route::get('/calisma-merkezi/yetkiler', [WorkspaceController::class, 'permissions']);
    Route::get('/calisma-merkezi/veri-kalitesi', [WorkspaceController::class, 'quality']);
    Route::get('/calisma-merkezi/veri-kokeni/{person}', [WorkspaceController::class, 'provenance'])->whereNumber('person');
    Route::post('/calisma-merkezi/nobet-senaryolari/{kind}', [WorkspaceController::class, 'scenario'])->where('kind', 'mudurnobet|vardiyanobet|infaznobet');

    Route::middleware('can:manage-modules')->group(function (): void {
        foreach (['overview' => ['yonetim', 'index'], 'appearance' => ['yonetim/metinler', 'appearance'], 'boards' => ['yonetim/kurul-basliklari', 'boards'], 'logins' => ['yonetim/girisler', 'logins'], 'audit' => ['yonetim/islem-kayitlari', 'audit'], 'requests' => ['yonetim/talepler', 'requests'], 'fields' => ['yonetim/alanlar', 'fields'], 'database' => ['yonetim/veritabani', 'database'], 'recovery' => ['yonetim/kurtarma', 'recovery']] as $mode => [$path,$name]) {
            Route::get('/'.$path, [ManagementController::class, 'index'])->defaults('management_mode', $mode)->name('management.'.$name);
            Route::post('/'.$path, [ManagementController::class, 'mutate'])->defaults('management_mode', $mode);
        }
        Route::get('/yonetim/sql-indir', [ManagementController::class, 'sqlDownload']);
        Route::get('/yonetim/yedekler/{id}/indir', [ManagementController::class, 'download'])->whereNumber('id')->name('management.backup.download');
        Route::get('/yonetim/dosya-indir', [ManagementController::class, 'file']);
        Route::post('/yonetim/dosya-yukle', [ManagementController::class, 'upload']);
        Route::get('/yonetim/personel-kadrosu', [StaffTreeController::class, 'index'])->defaults('management', true)->name('management.staff');
        Route::post('/yonetim/personel-kadrosu', [StaffTreeController::class, 'save']);
        Route::put('/yonetim/personel-kadrosu/{node}', [StaffTreeController::class, 'save'])->whereNumber('node');
        Route::get('/yonetim/telefon', [InstitutionController::class, 'index'])->defaults('management', true)->defaults('institution', 'telefon')->name('management.phone');
        Route::post('/yonetim/telefon', [InstitutionController::class, 'mutate'])->defaults('institution', 'telefon');
        Route::post('/yonetim/telefon/{record}', [InstitutionController::class, 'mutate'])->whereNumber('record')->defaults('institution', 'telefon');
        Route::get('/yonetim/telefon/yazdir', [InstitutionController::class, 'print'])->defaults('management', true)->defaults('institution', 'telefon');
    });

    Route::get('/yonetim/saglik', PortalHealthController::class)->middleware('can:manage-modules')->name('health.index');
    foreach (json_decode(file_get_contents(resource_path('defaults/legacy-route-map.json')), true, 64, JSON_THROW_ON_ERROR) as $file => $alias) {
        Route::get('/'.$file, LegacyWorkflowController::class)->defaults('legacy_file', $file);
    }
    Route::get('/portal/bildirimler', fn (Request $request) => response()->json(app(PortalIndicators::class)->read($request->user()))->header('Cache-Control', 'private, no-store'))->name('portal.indicators');
    Route::post('/infaz/hesapla', [WorkflowController::class, 'calculations']);
    Route::get('/ana-kayitlar/degerler', [PersonController::class, 'homeValues'])->name('home.values');
    Route::get('/ana-kayitlar/{person}/foto', [PersonController::class, 'homePhoto'])->whereNumber('person')->name('home.photo');
    Route::get('/ana-kayitlar/cikti', [PersonController::class, 'homeExport'])->name('home.export');
    Route::post('/ana-kayitlar', [PersonController::class, 'homeSave'])->name('home.store');
    Route::get('/ana-kayitlar/{person}', [PersonController::class, 'homeDetail'])->whereNumber('person');
    Route::put('/ana-kayitlar/{person}', [PersonController::class, 'homeSave'])->whereNumber('person');
    Route::get('/index.php', LegacyWorkflowController::class);
    Route::get('/mesajlar', [PersonalMessagesController::class, 'index'])->name('personal-messages.index');
    Route::post('/mesajlar', [PersonalMessagesController::class, 'mutate']);
    Route::get('/mesajlar/{message}', [PersonalMessagesController::class, 'read'])->whereNumber('message');
    Route::get('/mesaj-ekleri/{attachment}', [PersonalMessagesController::class, 'download'])->whereNumber('attachment');
    Route::get('/excel-birlestir', [ExcelMergeController::class, 'index'])->name('excel-merge.index');
    Route::post('/excel-birlestir', [ExcelMergeController::class, 'merge']);
    Route::get('/excel-birlestir/indir', [ExcelMergeController::class, 'download']);
    Route::get('/koguslar', [RoomListsController::class, 'index'])->name('rooms.index');
    Route::get('/koguslar/cikti', [RoomListsController::class, 'export']);
    Route::put('/koguslar/{room}', [RoomListsController::class, 'rename'])->whereNumber('room');
    Route::get('/toplu-durum', [BulkStatusController::class, 'index'])->name('bulk-status.index');
    Route::post('/toplu-durum', [BulkStatusController::class, 'mutate']);
    foreach (['teblig', 'bakanlik_sevk', 'kimlik', 'fotograf'] as $kind) {
        Route::get('/belgeler/'.$kind, [PersonDocumentsController::class, 'index'])->defaults('document', $kind)->name('documents.'.$kind.'.index');
    }
    Route::get('/belgeler/{document}/kisiler/{person}/foto', [PersonPhotoController::class, 'show'])->where('document', 'kimlik|fotograf')->whereNumber('person')->name('documents.photo');
    Route::post('/belgeler/fotograf/kisiler/{person}/foto', [PersonPhotoController::class, 'save'])->defaults('document', 'fotograf')->whereNumber('person');
    Route::delete('/belgeler/fotograf/kisiler/{person}/foto', [PersonPhotoController::class, 'save'])->defaults('document', 'fotograf')->whereNumber('person');

    foreach (['sayim' => ['sayim', 'count-report'], 'uyap_karsilastirma' => ['uyap-karsilastirma', 'uyap-comparison']] as $kind => [$path,$name]) {
        Route::get('/'.$path, [ComparisonController::class, 'index'])->defaults('comparison', $kind)->name($name.'.index');
        Route::post('/'.$path, [ComparisonController::class, 'upload'])->defaults('comparison', $kind);
    }
    Route::get('/istatistik', [StatisticsController::class, 'index'])->defaults('statistics', 'istatistik')->name('statistics.index');
    Route::get('/mesem-istatistik', [StatisticsController::class, 'index'])->defaults('statistics', 'mesemstat')->name('mesem-statistics.index');
    Route::get('/kurum/yemek', [MealsController::class, 'index'])->name('meals.index');
    Route::post('/kurum/yemek', [MealsController::class, 'save']);
    Route::get('/kurum/yemek/dosyalar/{file}', [MealsController::class, 'file'])->whereNumber('file');
    Route::get('/kurum/sohbet', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/kurum/sohbet/mesajlar', [ChatController::class, 'messages']);
    Route::post('/kurum/sohbet', [ChatController::class, 'mutate']);
    Route::get('/kurum/telefon/rehber', [InstitutionController::class, 'directory'])->name('institution.telefon.directory');
    foreach (['telefon', 'duyurular', 'kurul', 'mudurnobet', 'vardiyanobet', 'infaznobet', 'kismiarama'] as $kind) {
        Route::get('/kurum/'.$kind, [InstitutionController::class, 'index'])->defaults('institution', $kind)->name('institution.'.$kind.'.index');
        Route::get('/kurum/'.$kind.'/cikti', [InstitutionController::class, 'export'])->defaults('institution', $kind);
        Route::get('/kurum/'.$kind.'/yazdir', [InstitutionController::class, 'print'])->defaults('institution', $kind);
        Route::post('/kurum/'.$kind, [InstitutionController::class, 'mutate'])->defaults('institution', $kind);
        Route::post('/kurum/'.$kind.'/{record}', [InstitutionController::class, 'mutate'])->whereNumber('record')->defaults('institution', $kind);
    }
    Route::get('/kurum/duyurular/{record}/okuyanlar', [InstitutionController::class, 'readers'])->whereNumber('record');
    foreach (['mudurnobet', 'vardiyanobet', 'infaznobet'] as $kind) {
        Route::get('/kurum/'.$kind.'/takvim', [InstitutionController::class, 'calendar'])->defaults('institution', $kind);
    }
    Route::get('/personel-kadrosu', [StaffTreeController::class, 'index'])->name('staff-tree.index');
    Route::post('/personel-kadrosu', [StaffTreeController::class, 'save']);
    Route::put('/personel-kadrosu/{node}', [StaffTreeController::class, 'save'])->whereNumber('node');
    foreach (['custom_rapor' => ['ozel-rapor', 'custom-report'], 'egitim' => ['egitim', 'services.egitim'], 'kurslar' => ['kurslar', 'services.kurslar'], 'revir' => ['revir', 'services.revir'], 'ziyaret' => ['ziyaret', 'services.ziyaret'], 'manevi' => ['manevi', 'services.manevi'], 'mesem' => ['mesem', 'services.mesem'], 'hijyen_isg' => ['hijyen-isg', 'services.hijyen_isg'], 'psikososyal' => ['psikososyal', 'services.psikososyal'], 'calisma' => ['calisma', 'services.calisma'], 'ilkgorus' => ['ilk-gorus', 'interviews'], 'infaz' => ['infaz', 'sentences'], 'izin' => ['izin', 'leaves'], 'izin_rapor' => ['izin-raporu', 'leave-report'], 'infaz_sevk_raporu' => ['sevk-raporu', 'transfer-report']] as $workflow => [$path, $name]) {
        Route::get('/'.$path, [WorkflowController::class, 'index'])->defaults('workflow', $workflow)->name($name.'.index');
        Route::get('/'.$path.'/cikti', [WorkflowController::class, 'export'])->defaults('workflow', $workflow)->name($name.'.export');
        Route::get('/'.$path.'/yazdir', [WorkflowController::class, 'print'])->defaults('workflow', $workflow)->name($name.'.print');
        if (! in_array($workflow, ['izin_rapor', 'infaz_sevk_raporu'], true)) {
            Route::get('/'.$path.'/sutun-degerleri', [WorkflowController::class, 'columnValues'])->defaults('workflow', $workflow);
        }
        if (! in_array($workflow, ['izin_rapor', 'infaz_sevk_raporu'], true)) {
            Route::get('/'.$path.'/kisiler/{person}', [WorkflowController::class, 'edit'])->whereNumber('person')->defaults('workflow', $workflow)->name($name.'.edit');
            Route::put('/'.$path.'/kisiler/{person}', [WorkflowController::class, 'update'])->whereNumber('person')->defaults('workflow', $workflow)->name($name.'.update');
        }
    }
    Route::get('/infaz/kisiler/{person}/foto', [PersonPhotoController::class, 'show'])->whereNumber('person')->defaults('document', 'infaz')->name('sentences.photo');
    Route::get('/ilk-gorus/kisiler/{person}/foto', [PersonPhotoController::class, 'show'])->whereNumber('person')->name('interviews.photo');
    Route::post('/ilk-gorus/kisiler/{person}/foto', [PersonPhotoController::class, 'save'])->whereNumber('person');
    Route::delete('/ilk-gorus/kisiler/{person}/foto', [PersonPhotoController::class, 'save'])->whereNumber('person');
    Route::post('/mesem/kisiler/{person}/hizli', [WorkflowController::class, 'mesemQuick'])->whereNumber('person');
    Route::post('/ilk-gorus/ds-sifirla', [WorkflowController::class, 'reset'])->name('interviews.reset');
    Route::get('/yonetim/is-akisi-secenekleri', [WorkflowController::class, 'options'])->middleware('can:manage-modules')->name('workflow-options.index');
    Route::post('/yonetim/is-akisi-secenekleri', [WorkflowController::class, 'optionCreate'])->middleware('can:manage-modules');
    Route::put('/yonetim/is-akisi-secenekleri/{id}', [WorkflowController::class, 'optionUpdate'])->whereNumber('id')->middleware('can:manage-modules')->name('workflow-options.update');

    Route::middleware('can:manage-housing')->prefix('yonetim/yerlesim')->group(function (): void {
        Route::get('/', [HousingController::class, 'index'])->name('housing.index');
        Route::post('/toplu', [HousingController::class, 'bulk']);
        Route::post('/{kind}', [HousingController::class, 'store'])->where('kind', 'blocks|rooms')->name('housing.store');
        Route::put('/{kind}/{id}', [HousingController::class, 'update'])->where('kind', 'blocks|rooms')->whereNumber('id')->name('housing.update');
    });
    Route::middleware('can:manage-housing')->prefix('yonetim/durumlar')->group(function (): void {
        Route::get('/', [StatusController::class, 'index'])->name('statuses.index');
        Route::post('/', [StatusController::class, 'store'])->name('statuses.store');
        Route::put('/{id}', [StatusController::class, 'update'])->whereNumber('id')->name('statuses.update');
    });
    Route::get('/kisiler/{person}/notlar', [PersonNotesController::class, 'index'])->whereNumber('person');
    Route::post('/kisiler/{person}/notlar', [PersonNotesController::class, 'store'])->whereNumber('person');
    Route::get('/portal', [PersonController::class, 'index'])->name('portal');
    Route::get('/kisiler/{person}/yerlesim', [PlacementController::class, 'edit'])->whereNumber('person')->name('placement.edit');
    Route::put('/kisiler/{person}/yerlesim', [PlacementController::class, 'update'])->whereNumber('person')->name('placement.update');
    Route::get('/kisiler/yeni', [PersonController::class, 'create'])->name('people.create');
    Route::get('/kisiler/{person}/duzenle', [PersonController::class, 'edit'])->whereNumber('person')->name('people.edit');
    Route::post('/kisiler', [PersonController::class, 'store'])->name('people.store');
    Route::put('/kisiler/{person}', [PersonController::class, 'update'])->whereNumber('person')->name('people.update');
    Route::get('/moduller/{code}', [ModuleRuntimeController::class, 'show'])->where('code', '[a-z][a-z0-9-]*')->name('module-runtime.show');
    Route::get('/moduller/{code}/cikti', [ModuleRuntimeController::class, 'export'])->where('code', '[a-z][a-z0-9-]*')->name('module-runtime.export');
    Route::get('/moduller/{code}/yazdir', [ModuleRuntimeController::class, 'print'])->where('code', '[a-z][a-z0-9-]*')->name('module-runtime.print');
    Route::get('/moduller/{code}/kisiler', [ModuleRuntimeController::class, 'people'])->where('code', '[a-z][a-z0-9-]*')->name('module-runtime.people');
    Route::get('/moduller/{code}/yeni', [ModuleRuntimeController::class, 'create'])->where('code', '[a-z][a-z0-9-]*')->name('module-runtime.create');
    Route::get('/moduller/{code}/kayitlar/{record}/duzenle', [ModuleRuntimeController::class, 'edit'])->where('code', '[a-z][a-z0-9-]*')->whereNumber('record')->name('module-runtime.edit');
    Route::post('/moduller/{code}/kayitlar', [ModuleRuntimeController::class, 'store'])->where('code', '[a-z][a-z0-9-]*')->name('module-runtime.store');
    Route::put('/moduller/{code}/kayitlar/{record}', [ModuleRuntimeController::class, 'update'])->where('code', '[a-z][a-z0-9-]*')->whereNumber('record')->name('module-runtime.update');
    Route::get('/yonetim/menu', [MenuController::class, 'index'])->middleware('can:manage-modules')->name('menu.index');
    Route::post('/yonetim/menu/sabit', [MenuController::class, 'builtin'])->middleware('can:manage-modules');
    Route::put('/yonetim/menu', [MenuController::class, 'update'])->middleware('can:manage-modules')->name('menu.update');
    Route::middleware('can:manage-modules')->prefix('yonetim/secenek-listeleri')->group(function (): void {
        Route::get('/', [OptionCatalogController::class, 'index'])->name('options.index');
        Route::post('/', [OptionCatalogController::class, 'store'])->name('options.store');
        Route::put('/{id}', [OptionCatalogController::class, 'update'])->whereNumber('id')->name('options.update');
    });
    Route::middleware('can:manage-modules')->prefix('yonetim/moduller')->group(function (): void {
        Route::get('/', [ModuleDesignerController::class, 'index'])->name('modules.index');
        Route::post('/', [ModuleDesignerController::class, 'store'])->name('modules.store');
        Route::get('/{id}', [ModuleDesignerController::class, 'show'])->whereNumber('id')->name('modules.show');
        Route::put('/{id}/taslak', [ModuleDesignerController::class, 'update'])->whereNumber('id')->name('modules.update');
        Route::post('/{id}/taslak', [ModuleDesignerController::class, 'fork'])->whereNumber('id')->name('modules.fork');
        Route::post('/{id}/yayinla', [ModuleDesignerController::class, 'publish'])->whereNumber('id')->name('modules.publish');
    });
    Route::get('/admin/politikalar.php', [PageAccessController::class, 'legacy'])->middleware('can:manage-page-access');
    Route::get('/yonetim/politikalar', [PageAccessController::class, 'legacy'])->middleware('can:manage-page-access');
    Route::middleware('can:manage-page-access')->prefix('yonetim/erisim-politikalari')->group(function (): void {
        Route::post('/sabit-sayfalar', [PageAccessController::class, 'sync']);
        Route::get('/{id}/alanlar', [PageAccessController::class, 'fields'])->whereNumber('id');
        Route::get('/', [PageAccessController::class, 'index'])->name('page-access.index');
        Route::post('/', [PageAccessController::class, 'store'])->name('page-access.store');
        Route::post('/toplu', [PageAccessController::class, 'batch'])->name('page-access.batch');
        Route::put('/{id}', [PageAccessController::class, 'update'])->whereNumber('id')->name('page-access.update');
        Route::delete('/{id}', [PageAccessController::class, 'archive'])->whereNumber('id')->name('page-access.archive');
        Route::post('/{id}/geri-al', [PageAccessController::class, 'restore'])->whereNumber('id')->name('page-access.restore');
        Route::get('/{id}/ac', [PageAccessController::class, 'open'])->whereNumber('id')->name('page-access.open');
    });
    Route::post('/yonetim/kullanicilar/{id}/sayfa-erisimleri', [PageAccessController::class, 'personal'])->whereNumber('id')->middleware('can:manage-page-access')->name('users.page-access');
    Route::middleware('can:manage-organization')->prefix('yonetim/{kind}')->where(['kind' => 'birimler|unvanlar'])->group(function (): void {
        Route::get('/', [OrganizationController::class, 'index'])->name('organization.index');
        Route::post('/', [OrganizationController::class, 'store'])->name('organization.store');
        Route::put('/{id}', [OrganizationController::class, 'update'])->whereNumber('id')->name('organization.update');
        Route::delete('/{id}', [OrganizationController::class, 'destroy'])->whereNumber('id')->name('organization.destroy');
    });
    Route::middleware('can:manage-users')->prefix('yonetim/kullanicilar')->group(function (): void {
        Route::get('/', [UserAdministrationController::class, 'index'])->name('users.index');
        Route::get('/yeni', [UserAdministrationController::class, 'create'])->name('users.create');
        Route::post('/', [UserAdministrationController::class, 'store'])->name('users.store');
        Route::get('/{id}', [UserAdministrationController::class, 'show'])->whereNumber('id')->name('users.show');
        Route::delete('/{id}', [UserAdministrationController::class, 'delete'])->whereNumber('id')->name('users.delete');
        Route::put('/{id}', [UserAdministrationController::class, 'update'])->whereNumber('id')->name('users.update');
        Route::post('/{id}/parola-sifirla', [UserAdministrationController::class, 'reset'])->whereNumber('id')->middleware('throttle:6,1')->name('users.reset');
        Route::post('/{id}/oturumlari-sonlandir', [UserAdministrationController::class, 'terminate'])->whereNumber('id')->middleware('throttle:6,1')->name('users.terminate');
    });
    Route::post('/cikis', [SessionController::class, 'destroy'])->name('logout');
    Route::get('/parola', [PasswordController::class, 'edit'])->name('password.edit');
    Route::post('/parola', [PasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
    Route::post('/profil', [PasswordController::class, 'profile'])->name('profile.update');
    Route::get('/profil.php', fn () => redirect()->route('password.edit'));
    Route::get('/change_password.php', fn () => redirect()->route('password.edit'));
});
