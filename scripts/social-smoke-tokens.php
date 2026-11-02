<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$path = storage_path('framework/testing/social-smoke.json');
$fixture = json_decode(file_get_contents($path), true);
if (! str_starts_with((string) realpath($fixture['database']), realpath(storage_path('framework/testing')).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('The smoke database must stay inside storage/framework/testing.');
}
config(['database.default' => 'social_smoke', 'database.connections.social_smoke' => ['driver' => 'sqlite', 'database' => $fixture['database'], 'prefix' => ''],
    'jwt.secret' => 'social-smoke-test-only-signing-key-not-for-production-0123456789', 'jwt.ttl' => 1440, 'cache.default' => 'array']);
// These additions only update an older disposable smoke fixture, never the app DB.
if (! Schema::hasColumn('posts', 'court_id')) {
    Schema::table('posts', fn ($t) => $t->unsignedBigInteger('court_id')->nullable());
}
if (! Schema::hasColumn('posts', 'hidden_from_profile')) {
    Schema::table('posts', fn ($t) => $t->boolean('hidden_from_profile')->default(false));
}
foreach ($fixture['users'] as &$user) {
    $user['token'] = auth('api')->login(User::findOrFail($user['id']));
}
file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT));
echo "Smoke tokens refreshed.\n";
