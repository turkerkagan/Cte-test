@csrf
@php($isProtected = $managedUser->is_protected_superadmin ?? false)
@if($isProtected)
    <input type="hidden" name="login_name" value="{{ $managedUser->login_name }}">
    <input type="hidden" name="role_id" value="{{ $managedUser->role_id }}">
    <input type="hidden" name="is_active" value="1">
@endif
<div class="form-grid">
    <label>Ad soyad
        <input name="full_name" value="{{ old('full_name', $managedUser->full_name ?? '') }}" required>
        @error('full_name')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Sicil numarası
        <input name="personnel_number" value="{{ old('personnel_number', $managedUser->personnel_number ?? '') }}">
        @error('personnel_number')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Özel giriş adı
        <input name="login_name" value="{{ old('login_name', $managedUser->login_name ?? '') }}" @disabled($isProtected)>
        @error('login_name')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>E-posta
        <input type="email" name="email" value="{{ old('email', $managedUser->email ?? '') }}">
        @error('email')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Kurum
        <select name="institution_id" required>
            @foreach($institutions as $institution)
                <option value="{{ $institution->id }}" @selected((int) old('institution_id', $managedUser->institution_id ?? 0) === $institution->id)>{{ $institution->name }}</option>
            @endforeach
        </select>
        @error('institution_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Birim
        <select name="department_id">
            <option value="">Birim seçilmedi</option>
            @foreach($departments as $department)
                <option value="{{ $department->id }}" @selected((int) old('department_id', $managedUser->department_id ?? 0) === $department->id)>{{ $department->name }}</option>
            @endforeach
        </select>
        @error('department_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Rol
        <select name="role_id" required @disabled($isProtected)>
            @foreach($roles as $role)
                <option value="{{ $role->id }}" @selected((int) old('role_id', $managedUser->role_id ?? 0) === $role->id)>{{ $role->name }}</option>
            @endforeach
        </select>
        @error('role_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    @if(! isset($managedUser))
        <label>Geçici parola
            <input type="password" name="password" required>
            @error('password')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label>Geçici parola tekrar
            <input type="password" name="password_confirmation" required>
        </label>
    @endif
</div>
@if(! $isProtected)
<section class="permission-editor">
    <div class="panel-heading"><div><p class="eyebrow">Sayfa yetkileri</p><h3>Rolün yetkisini kullanıcı için özelleştir</h3><p class="muted-text">Rol varsayılanını kullanabilir, bu kullanıcıya özel izin verebilir veya erişimi engelleyebilirsiniz.</p></div></div>
    <div class="permission-presets">
        <button class="secondary-button small-button" type="button" data-permission-preset="readonly">Tümü salt okunur</button>
        <button class="secondary-button small-button" type="button" data-permission-preset="readwrite">Okuma + yazma</button>
        <button class="secondary-button small-button" type="button" data-permission-preset="inherit">Rol ayarına dön</button>
    </div>
    @php($overrides = isset($managedUser) ? $managedUser->permissionOverrides->keyBy('permission_id') : collect())
    @php($rolePermissionIds = isset($managedUser) ? $managedUser->role->permissions->pluck('id')->all() : [])
    <div class="permission-grid">
        @foreach($permissionGroups as $module => $permissions)
            <article class="permission-group" data-permission-group="{{ $module }}">
                <strong>{{ str($permissions->first()->label)->before(' — ') }}</strong>
                @foreach($permissions as $permission)
                    @php($override = $overrides->get($permission->id))
                    @php($selected = old("permissions.{$permission->id}", $override ? ($override->allowed ? 'allow' : 'deny') : 'inherit'))
                    <label>
                        <span>{{ str($permission->label)->after(' — ') }} <small>{{ in_array($permission->id, $rolePermissionIds, true) ? 'Rol: izinli' : 'Rol: kapalı' }}</small></span>
                        <select name="permissions[{{ $permission->id }}]" data-permission-action="{{ $permission->action }}">
                            <option value="inherit" @selected($selected === 'inherit')>Rolü kullan</option>
                            <option value="allow" @selected($selected === 'allow')>İzin ver</option>
                            <option value="deny" @selected($selected === 'deny')>Engelle</option>
                        </select>
                    </label>
                @endforeach
            </article>
        @endforeach
    </div>
</section>
@endif
<div class="check-row">
    <label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $managedUser->is_active ?? true)) @disabled($isProtected)> Aktif kullanıcı</label>
    <label><input type="checkbox" name="must_change_password" value="1" @checked(old('must_change_password', $managedUser->must_change_password ?? true))> İlk girişte parola değiştirsin</label>
</div>
<div class="form-actions">
    <a class="secondary-button" href="{{ route('admin.users.index') }}">Vazgeç</a>
    <button class="primary-button compact" type="submit">Kaydet</button>
</div>
