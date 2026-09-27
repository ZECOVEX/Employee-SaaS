<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$paths = ['/', '/login', '/register', '/forgot-password'];
foreach ($paths as $path) {
    $req = Illuminate\Http\Request::create($path, 'GET');
    $resp = $kernel->handle($req);
    echo $path.' => '.$resp->getStatusCode().' len='.strlen($resp->getContent())."\n";
    $kernel->terminate($req, $resp);
}

// Authenticated pages as demo admin
$user = App\Models\User::where('email','admin@demo.test')->first();
$authPaths = ['/dashboard', '/dashboard/admin', '/employees', '/departments', '/users', '/audit-logs', '/settings', '/profile'];
foreach ($authPaths as $path) {
    $req = Illuminate\Http\Request::create($path, 'GET');
    $req->setUserResolver(fn () => $user);
    $req->headers->set('X-Requested-With', 'XMLHttpRequest');
    try {
        $resp = $kernel->handle($req);
        $c = $resp->getContent();
        $err = '';
        if (str_contains($c, 'Whoops') || str_contains($c, 'Exception') || str_contains($c, 'server error')) { $err = ' [HAS ERROR MARKER]'; }
        echo $path.' => '.$resp->getStatusCode().' len='.strlen($c).$err."\n";
    } catch (Throwable $e) {
        echo $path.' => EXCEPTION: '.$e->getMessage()."\n";
    }
    $kernel->terminate($req, $resp ?? null);
}