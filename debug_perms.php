<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$perms = DB::table('role_permission')->join('permissions','permissions.id','=','role_permission.permission_id')
  ->where('role_permission.role_id', 1)->pluck('key');
echo implode("\n", $perms->all())."\n";

// Simulate auth user
$u = App\Models\User::where('email','admin@demo.test')->first();
auth()->login($u);
$u->load('roles.permissions');
echo 'loaded roles: '.$u->roles->pluck('slug')."\n";
echo 'can: '.var_export($u->canPermission('dashboard.admin'), true)."\n";
