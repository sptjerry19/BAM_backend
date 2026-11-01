<?php

use App\Models\Member;
use App\Models\MemberEquipment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Local smoke-test database only. Never touches the configured development DB.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$directory = storage_path('framework/testing');
if (! is_dir($directory)) {
    mkdir($directory, 0775, true);
}
$database = $directory.'/social-smoke-'.getmypid().'.sqlite';
touch($database);
config(['database.default' => 'social_smoke', 'database.connections.social_smoke' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true],
    'cache.default' => 'array', 'scout.driver' => null, 'jwt.secret' => 'social-smoke-test-only-signing-key-not-for-production-0123456789']);
foreach (['2014_10_12_000000_create_users_table', '2024_01_01_000003_create_members_table', '2026_08_03_000004_add_role_and_member_id_to_users_table', '2024_01_01_000002_create_products_table', '2024_01_01_000007_create_member_equipments_table', '2026_10_31_100000_create_social_profile_tables'] as $migration) {
    (require database_path('migrations/'.$migration.'.php'))->up();
}
$tokens = [];
foreach (['a', 'b', 'c'] as $name) {
    $member = Member::create(['name' => 'Hồ sơ kiểm thử '.strtoupper($name), 'email' => 'social-'.$name.'@example.test', 'join_date' => '2026-10-31', 'equipment' => [], 'points' => 120, 'matches' => 8, 'wins' => 5]);
    $user = User::create(['name' => $member->name, 'email' => $member->email, 'password' => 'social-smoke-password', 'role' => 'user', 'member_id' => $member->id]);
    $tokens[$name] = ['id' => $user->id, 'token' => auth('api')->login($user)];
    DB::table('user_profiles')->insert(['user_id' => $user->id, 'bio' => 'Cùng chơi cầu lông, chia sẻ kinh nghiệm và kết nối bạn chơi.', 'city' => 'TP. Hồ Chí Minh', 'club' => 'JBadminton', 'created_at' => now(), 'updated_at' => now()]);
    MemberEquipment::create(['member_id' => $member->id, 'name' => 'Vợt kiểm thử '.strtoupper($name), 'category' => 'racket', 'brand' => 'Yonex', 'price' => 1000000, 'notes' => 'Ghi chú riêng của chủ hồ sơ']);
}
file_put_contents($directory.'/social-smoke.json', json_encode(['database' => $database, 'users' => $tokens], JSON_PRETTY_PRINT));
echo "Smoke fixture ready: storage/framework/testing/social-smoke.json\n";
