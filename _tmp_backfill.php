<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (App\Models\Certificate::all() as $c) {
    $c->update(['verify_url' => \URL::signedRoute(
        'certificates.verify',
        ['token' => $c->token],
        now()->addYears(10)
    )]);
    echo $c->id . '|' . ($c->qr_code_path ?? 'NULL') . '|' . $c->verify_url . PHP_EOL;
}
