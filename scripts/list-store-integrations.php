<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "INTEGRATIONS\n";
$rows = App\Models\StoreDemandIntegration::query()->get(['id', 'name', 'user_id', 'active', 'default_category_id']);
if ($rows->isEmpty()) {
    echo "none\n";
} else {
    foreach ($rows as $i) {
        echo "{$i->id}|{$i->name}|user={$i->user_id}|cat={$i->default_category_id}|active={$i->active}\n";
    }
}

echo "USERS_EMPLOYER\n";
App\Models\User::query()
    ->where('type', 'employer')
    ->orderByDesc('id')
    ->limit(15)
    ->get(['id', 'name', 'email'])
    ->each(fn ($u) => print("{$u->id}|{$u->email}|{$u->name}\n"));

echo "USER_2\n";
$u2 = App\Models\User::query()->find(2);
echo $u2 ? "{$u2->id}|{$u2->email}|{$u2->name}|type={$u2->type}\n" : "missing\n";

echo "CATEGORIES_ERRAND\n";
App\Models\Category::query()
    ->orderBy('id')
    ->limit(30)
    ->get(['id', 'slug', 'display_name'])
    ->each(fn ($c) => print("{$c->id}|{$c->slug}|{$c->display_name}\n"));
