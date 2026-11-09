@csrf
<div class="form-grid">
    <label>Başlık
        <input name="title" value="{{ old('title', $menuItem->title ?? '') }}" required>
        @error('title')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>İkon
        <input name="icon" value="{{ old('icon', $menuItem->icon ?? '') }}" placeholder="⌂, ●, ◆">
        @error('icon')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Üst menü
        <select name="parent_id">
            <option value="">Ana menü</option>
            @foreach($parents as $parent)
                <option value="{{ $parent->id }}" @selected((int) old('parent_id', $menuItem->parent_id ?? 0) === $parent->id)>{{ $parent->title }}</option>
            @endforeach
        </select>
        @error('parent_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Modül kodu
        <select name="module_code">
            <option value="">Bağımsız menü</option>
            @foreach($modules as $module)
                <option value="{{ $module->code }}" @selected(old('module_code', $menuItem->module_code ?? '') === $module->code)>{{ $module->name }} — {{ $module->code }}</option>
            @endforeach
        </select>
        @error('module_code')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Route adı
        <input name="route_name" value="{{ old('route_name', $menuItem->route_name ?? '') }}" placeholder="dashboard, admin.users.index">
        @error('route_name')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Yetki anahtarı
        <select name="permission_key">
            <option value="">Yetki gerektirmez</option>
            @foreach($permissions as $permission)
                <option value="{{ $permission->key }}" @selected(old('permission_key', $menuItem->permission_key ?? '') === $permission->key)>{{ $permission->key }} — {{ $permission->label }}</option>
            @endforeach
        </select>
        @error('permission_key')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Sıra
        <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $menuItem->sort_order ?? 0) }}" required>
        @error('sort_order')<small class="field-error">{{ $message }}</small>@enderror
    </label>
</div>
<div class="check-row">
    <label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $menuItem->is_active ?? true))> Aktif</label>
    <label><input type="checkbox" name="is_visible" value="1" @checked(old('is_visible', $menuItem->is_visible ?? true))> Menüde görünür</label>
</div>
<div class="form-actions">
    <a class="secondary-button" href="{{ route('admin.menus.index') }}">Vazgeç</a>
    <button class="primary-button compact" type="submit">Kaydet</button>
</div>
