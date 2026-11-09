@extends('layouts.portal')
@section('title', 'Birim Tanımı')
@section('page-title', 'Birim Tanımı')
@section('content')
<section class="panel">
    <div class="panel-heading table-heading"><h2>{{ $definition->exists ? 'Birimi düzenle' : 'Yeni birim oluştur' }}</h2><a class="secondary-button" href="{{ route('admin.unit-definitions.index') }}">Listeye dön</a></div>
    @if($errors->any())<div class="error-alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="post" action="{{ $definition->exists ? route('admin.unit-definitions.update', $definition) : route('admin.unit-definitions.store') }}" class="stack-form" data-unit-builder>
        @csrf @if($definition->exists) @method('put') @endif
        <div class="form-grid">
            <label>Birim adı<input name="title" value="{{ old('title', $definition->title) }}" required maxlength="255"></label>
            @if($definition->exists)<label>Birim kodu<input value="{{ $definition->code }}" disabled></label>@else<label>Birim kodu<input name="code" value="{{ old('code') }}" required pattern="[a-z][a-z0-9-]*" maxlength="60" placeholder="ornek-birim"><small>Küçük İngilizce harf, rakam ve tire.</small></label>@endif
            <label>Sıra<input type="number" name="sort_order" value="{{ old('sort_order', $definition->sort_order) }}" min="0" max="9999" required></label>
            <label>Açıklama<textarea name="description" maxlength="2000">{{ old('description', $definition->description) }}</textarea></label>
            <label>Kayıt türleri<textarea name="types_text" rows="3" required>{{ old('types_text', implode("\n", $definition->types ?? [])) }}</textarea><small>Her satıra bir tür yazın.</small></label>
        </div>
        <div class="check-row">
            @foreach(['is_active' => 'Aktif', 'is_visible' => 'Menüde göster', 'is_sensitive' => 'Hassas birim'] as $key => $label)
                <label><input type="checkbox" name="{{ $key }}" value="1" @checked(old('_submitted') ? old($key) : $definition->$key)> {{ $label }}</label>
            @endforeach
        </div>
        <input type="hidden" name="_submitted" value="1">
        <div class="panel-heading table-heading"><div><h3>Form alanları ve tablo sütunları</h3><p class="muted-text">Alan sırası tabloya da uygulanır. Başlık değiştirilebilir; alan anahtarı verinin kalıcı kimliğidir.</p></div><button type="button" class="secondary-button" data-add-unit-field>Alan ekle</button></div>
        <div data-unit-fields>
            @foreach(old('fields', $definition->fields ?? []) as $index => $field)
                @include('admin.unit-definitions._field', ['index' => $index, 'field' => $field])
            @endforeach
        </div>
        <template data-unit-field-template>@include('admin.unit-definitions._field', ['index' => '__INDEX__', 'field' => []])</template>
        <h3>Rol yetkileri</h3>
        <p class="muted-text">Düzenleme yetkisi görüntülemeyi de kapsar. Ana yönetici tüm birimlere erişebilir. Kullanıcıya özel izinler Kullanıcılar ekranından yönetilir.</p>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Rol</th><th>Görüntüle</th><th>Kayıt ekle / düzenle / kapat</th></tr></thead><tbody>
        @foreach($roles as $role)<tr><td>{{ $role->name }}</td>@foreach(['view', 'update'] as $action)<td><input type="checkbox" aria-label="{{ $role->name }} {{ $action === 'view' ? 'görüntüleme' : 'düzenleme' }}" name="{{ $action }}_roles[]" value="{{ $role->id }}" @checked(in_array($role->id, old('_submitted') ? old($action.'_roles', []) : $grants[$action]))></td>@endforeach</tr>@endforeach
        </tbody></table></div>
        <button type="submit" class="primary-button">Birimi kaydet</button>
    </form>
</section>
@endsection
