<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Resuelve el cliente final (comprador) de una demanda publicada por integración de tienda.
 */
class StoreDemandBuyerResolver
{
    /**
     * @return array{user: User, existed: bool}
     */
    public function resolveOrCreate(string $email, ?string $name = null, ?string $phone = null): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('buyer_email_invalid');
        }

        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            $this->maybeUpdateProfile($existing, $name, $phone);

            return ['user' => $existing->fresh(), 'existed' => true];
        }

        $normalizedPhone = $this->normalizePhone($phone);
        if ($normalizedPhone !== null && User::query()->where('phone', $normalizedPhone)->exists()) {
            $normalizedPhone = null;
        }

        $displayName = $this->resolveDisplayName($name, $email);

        $user = User::create([
            'name' => $displayName,
            'email' => $email,
            'phone' => $normalizedPhone,
            'password' => Hash::make(Str::random(40)),
            'type' => 'employer',
            'is_active' => true,
        ]);

        return ['user' => $user, 'existed' => false];
    }

    private function maybeUpdateProfile(User $user, ?string $name, ?string $phone): void
    {
        $updates = [];
        $trimName = trim((string) $name);
        if ($trimName !== '' && (! $user->name || $user->name === $user->email)) {
            $updates['name'] = mb_substr($trimName, 0, 255);
        }

        $normalizedPhone = $this->normalizePhone($phone);
        if ($normalizedPhone !== null && ! $user->phone) {
            $phoneTaken = User::query()
                ->where('phone', $normalizedPhone)
                ->where('id', '!=', $user->id)
                ->exists();
            if (! $phoneTaken) {
                $updates['phone'] = $normalizedPhone;
            }
        }

        if ($updates !== []) {
            $user->update($updates);
        }
    }

    private function resolveDisplayName(?string $name, string $email): string
    {
        $trimName = trim((string) $name);
        if ($trimName !== '') {
            return mb_substr($trimName, 0, 255);
        }

        $local = strstr($email, '@', true);

        return $local !== false && $local !== ''
            ? mb_substr($local, 0, 255)
            : 'Cliente';
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '56') && strlen($digits) >= 11) {
            return '+'.$digits;
        }
        if (strlen($digits) === 9 && $digits[0] === '9') {
            return '+56'.$digits;
        }
        if (strlen($digits) >= 8) {
            return '+'.$digits;
        }

        return null;
    }
}
