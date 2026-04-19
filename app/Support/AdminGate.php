<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

class AdminGate
{
    public static function assert(Request $request): void
    {
        $user = $request->user();
        $ids = config('admin.user_ids', [21]);
        if (! $user instanceof User || ! in_array($user->id, $ids, true)) {
            abort(403, 'No autorizado');
        }
    }
}
