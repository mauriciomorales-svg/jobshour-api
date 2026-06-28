<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$name = $argv[1] ?? 'Mauricio';
$u = App\Models\User::where('name', 'like', "%{$name}%")->first();
if (! $u) {
    echo "No user matching {$name}\n";
    exit(1);
}
echo "User #{$u->id} {$u->name} ({$u->email})\n\n";

$all = App\Models\ServiceRequest::query()
    ->where('client_id', $u->id)
    ->orWhereHas('worker', fn ($q) => $q->where('user_id', $u->id))
    ->orderByDesc('id')
    ->limit(25)
    ->get(['id', 'status', 'client_id', 'worker_id', 'description', 'created_at', 'expires_at']);

$active = $all->filter(fn ($r) => in_array($r->status, ['pending', 'accepted', 'in_progress'], true));

echo "Active (banner count): " . $active->count() . "\n";
foreach ($active as $r) {
    $tab = match (true) {
        in_array($r->status, ['accepted', 'in_progress'], true) => 'En curso',
        $r->status === 'pending' => 'Activas',
        default => '?',
    };
    echo "  #{$r->id} {$r->status} → pestaña «{$tab}» | " . substr((string) $r->description, 0, 50) . "\n";
}

echo "\nRecent all:\n";
foreach ($all->take(10) as $r) {
    echo "  #{$r->id} {$r->status} created {$r->created_at}\n";
}
