<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bed;
use App\Models\Block;
use App\Models\Department;
use App\Models\Institution;
use App\Models\LookupGroup;
use App\Models\LookupItem;
use App\Models\MenuItem;
use App\Models\Module;
use App\Models\Permission;
use App\Models\PhoneDirectoryEntry;
use App\Models\Role;
use App\Models\UnitDefinition;
use App\Models\User;
use App\Models\Ward;
use App\Support\ProductionSecurity;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        $roles = Role::with('permissions')->orderBy('name')->get();
        $permissions = Permission::orderBy('module')->orderBy('action')->get();

        return view('admin.dashboard.index', [
            'counts' => [
                'institutions' => Institution::count(),
                'departments' => Department::count(),
                'users' => User::count(),
                'active_users' => User::where('is_active', true)->count(),
                'roles' => Role::count(),
                'permissions' => Permission::count(),
                'modules' => class_exists(Module::class) ? Module::count() : 0,
                'menu_items' => class_exists(MenuItem::class) ? MenuItem::count() : 0,
                'lookup_groups' => class_exists(LookupGroup::class) ? LookupGroup::count() : 0,
                'lookup_items' => class_exists(LookupItem::class) ? LookupItem::count() : 0,
                'blocks' => class_exists(Block::class) ? Block::count() : 0,
                'wards' => class_exists(Ward::class) ? Ward::count() : 0,
                'beds' => class_exists(Bed::class) ? Bed::count() : 0,
                'phone_entries' => class_exists(PhoneDirectoryEntry::class) ? PhoneDirectoryEntry::count() : 0,
                'sessions' => DB::table('sessions')->count(),
            ],
            'customUnitCount' => UnitDefinition::count(),
            'roles' => $roles,
            'permissions' => $permissions,
            'recentUsers' => User::with(['department', 'role'])->latest()->limit(8)->get(),
            'systemChecks' => ProductionSecurity::checks(),
            'securityPasses' => ProductionSecurity::passes(),
            'maintenanceMode' => app()->isDownForMaintenance(),
        ]);
    }
}
