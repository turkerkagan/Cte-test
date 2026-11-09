<?php

namespace Tests\Feature;

use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UnitDefinition;
use App\Models\UnitRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitDefinitionBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin = true): User
    {
        $institution = Institution::firstOrCreate(['name' => 'Test Kurumu'], ['is_active' => true]);

        return User::create(['institution_id' => $institution->id, 'full_name' => 'Test Kullanıcı', 'login_name' => uniqid('builder'), 'password' => 'password', 'is_active' => true, 'is_protected_superadmin' => $admin, 'must_change_password' => false, 'password_changed_at' => now()]);
    }

    private function payload(): array
    {
        return ['code' => 'yeni-birim', 'title' => 'Yeni Birim', 'types_text' => "Görüşme\nTakip", 'sort_order' => 0, 'is_active' => 1, 'is_visible' => 1,
            'fields' => [
                ['key' => 'sonuc', 'label' => 'Görüşme sonucu', 'type' => 'select', 'options_text' => "Olumlu\nOlumsuz", 'required' => 1, 'in_table' => 1, 'filterable' => 1],
                ['key' => 'puan', 'label' => 'Puan', 'type' => 'number', 'in_table' => 1],
                ['key' => 'tarih', 'label' => 'Kontrol tarihi', 'type' => 'date'],
            ]];
    }

    public function test_admin_can_build_a_unit_and_use_its_form_table_and_filter(): void
    {
        $admin = $this->user();
        $role = Role::create(['institution_id' => $admin->institution_id, 'name' => 'Birim Personeli', 'code' => 'builder-role', 'is_active' => true]);
        $payload = $this->payload() + ['update_roles' => [$role->id]];
        $this->actingAs($admin)->get(route('admin.unit-definitions.create'))->assertOk()->assertSee('Alan ekle');
        $this->post(route('admin.unit-definitions.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('admin.unit-definitions.index'));
        $definition = UnitDefinition::firstOrFail();
        $this->get(route('admin.unit-definitions.edit', $definition))->assertOk()->assertSee('Görüşme sonucu');
        $this->assertTrue($role->permissions()->where('key', 'custom_yeni-birim.view')->exists());
        $this->assertTrue($role->permissions()->where('key', 'custom_yeni-birim.update')->exists());
        $inmate = InmateFile::create(['institution_id' => $admin->institution_id, 'full_name' => 'Örnek Hükümlü', 'registered_at' => today(), 'entered_at' => today(), 'is_active' => true]);
        $this->get(route('unit-records.create', [$definition->code, $inmate]))->assertOk()->assertSee('Görüşme sonucu');
        $this->get(route('inmates.edit', $inmate))->assertOk()->assertSee('Yeni Birim');
        $this->post(route('unit-records.store', [$definition->code, $inmate]), ['record_type' => 'Görüşme', 'title' => 'Örnek kayıt', 'data' => ['sonuc' => 'Olumlu', 'puan' => 12.5]])->assertSessionHasNoErrors()->assertRedirect();
        $record = UnitRecord::firstOrFail();
        $this->assertEquals(12.5, $record->data['puan']);
        $this->get(route('unit-records.index', ['module' => $definition->code, 'field' => 'sonuc', 'field_value' => 'Olumlu']))->assertOk()->assertSee('Örnek kayıt')->assertSee('<th>Görüşme sonucu</th>', false);
        $this->get(route('unit-records.index', ['module' => $definition->code, 'field' => 'tarih']))->assertSessionHasErrors('field');
        $this->post(route('unit-records.store', [$definition->code, $inmate]), ['record_type' => 'Görüşme', 'title' => 'Hatalı', 'data' => ['sonuc' => 'Geçersiz', 'puan' => 'abc', 'tarih' => 'dün']])->assertSessionHasErrors(['data.sonuc', 'data.puan', 'data.tarih']);
        $this->post(route('unit-records.store', [$definition->code, $inmate]), ['record_type' => 'Görüşme', 'title' => 'Eksik', 'data' => ['unknown' => 'x']])->assertSessionHasErrors(['data', 'data.sonuc']);
        unset($payload['code']);
        $payload['fields'][0]['label'] = 'Yeni başlık';
        $this->put(route('admin.unit-definitions.update', $definition), $payload)->assertSessionHasNoErrors();
        $this->assertSame('Olumlu', $record->fresh()->data['sonuc']);
        array_shift($payload['fields']);
        $this->put(route('admin.unit-definitions.update', $definition), $payload)->assertSessionHasErrors('fields');
    }

    public function test_access_roles_inactive_units_and_admin_boundary(): void
    {
        $admin = $this->user();
        $person = $this->user(false);
        $this->actingAs($person)->get(route('admin.unit-definitions.index'))->assertForbidden();
        $this->post(route('admin.unit-definitions.store'), $this->payload())->assertForbidden();
        $this->actingAs($admin)->post(route('admin.unit-definitions.store'), $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($person)->get(route('unit-records.index', 'yeni-birim'))->assertForbidden();
        $role = Role::create(['institution_id' => $admin->institution_id, 'name' => 'İzleyici', 'code' => 'builder-viewer', 'is_active' => true]);
        $person->update(['role_id' => $role->id]);
        $role->syncPermissionsWithCacheClear([Permission::where('key', 'custom_yeni-birim.view')->firstOrFail()->id]);
        $this->actingAs($person->fresh())->get(route('unit-records.index', 'yeni-birim'))->assertOk();
        $inmate = InmateFile::create(['institution_id' => $admin->institution_id, 'full_name' => 'Kayıt', 'registered_at' => today(), 'is_active' => true]);
        $this->get(route('unit-records.create', ['yeni-birim', $inmate]))->assertForbidden();
        $definition = UnitDefinition::firstOrFail();
        $definition->update(['is_active' => false]);
        $this->actingAs($admin)->get(route('unit-records.index', 'yeni-birim'))->assertNotFound();
    }

    public function test_reserved_codes_duplicate_keys_and_empty_options_are_rejected(): void
    {
        $this->actingAs($this->user());
        $data = $this->payload();
        $data['code'] = 'izin';
        $data['fields'][1]['key'] = 'sonuc';
        $this->post(route('admin.unit-definitions.store'), $data)->assertSessionHasErrors(['code', 'fields.0.key']);
        $data = $this->payload();
        $data['fields'][0]['options_text'] = '';
        $this->post(route('admin.unit-definitions.store'), $data)->assertSessionHasErrors('fields.0.options_text');
    }
}
