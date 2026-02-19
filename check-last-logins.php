<?php
// Script para ver los últimos usuarios que iniciaron sesión
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "🔍 Últimos usuarios que iniciaron sesión:\n\n";

$logins = DB::table('personal_access_tokens')
    ->join('users', 'personal_access_tokens.tokenable_id', '=', 'users.id')
    ->select([
        'users.id',
        'users.name',
        'users.email',
        'users.type',
        'personal_access_tokens.created_at as login_at',
        'personal_access_tokens.last_used_at',
        'personal_access_tokens.name as token_name'
    ])
    ->orderBy('personal_access_tokens.created_at', 'desc')
    ->limit(20)
    ->get();

if ($logins->isEmpty()) {
    echo "❌ No se encontraron logins recientes.\n";
    exit(0);
}

echo sprintf("%-5s %-30s %-40s %-10s %-20s %-20s\n", 
    "ID", "Nombre", "Email", "Tipo", "Login", "Último uso");
echo str_repeat("-", 130) . "\n";

foreach ($logins as $login) {
    $loginAt = $login->login_at ? date('Y-m-d H:i:s', strtotime($login->login_at)) : 'N/A';
    $lastUsed = $login->last_used_at ? date('Y-m-d H:i:s', strtotime($login->last_used_at)) : 'Nunca';
    
    echo sprintf("%-5s %-30s %-40s %-10s %-20s %-20s\n",
        $login->id,
        substr($login->name, 0, 28),
        substr($login->email, 0, 38),
        $login->type ?? 'N/A',
        $loginAt,
        $lastUsed
    );
}

echo "\n✅ Total: " . $logins->count() . " logins encontrados\n";
