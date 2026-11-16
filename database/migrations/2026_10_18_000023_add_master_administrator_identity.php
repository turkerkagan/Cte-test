<?php

use App\Services\Security\MasterAdministrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_master_admin')->default(false);
        });
        DB::table('users')->where('is_system_admin', true)->orderBy('id')->chunkById(100, function ($users) {
            foreach ($users as $user) {
                if (MasterAdministrator::fromLegacy((array) $user)) {
                    DB::table('users')->where('id', $user->id)->update(['is_master_admin' => true]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_master_admin'));
    }
};
