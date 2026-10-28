<?php

namespace Database\Seeders;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Idempotent seed run by the container entrypoint: 50 users (user 1 is the Filament admin), each with
 * one web-push subscription whose endpoint is the HTTP stub and whose keys are a real P-256 pair, so
 * minishlink/web-push really encrypts. Sources and posts come from the import jobs.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->count() >= 50) {
            return;
        }

        $password = Hash::make('workbench');

        for ($i = 1; $i <= 50; $i++) {
            $user = User::query()->firstOrCreate(
                ['email' => $i === 1 ? 'admin@workbench.test' : "user{$i}@workbench.test"],
                ['name' => $i === 1 ? 'Admin' : "User {$i}", 'password' => $password],
            );

            if ($user->pushSubscriptions()->doesntExist()) {
                $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
                $ec = openssl_pkey_get_details($key)['ec'];
                $point = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

                PushSubscription::query()->create([
                    'user_id' => $user->id,
                    'endpoint' => rtrim(config('workbench.stub_url'), '/').'/push/'.$user->id,
                    'public_key' => rtrim(strtr(base64_encode($point), '+/', '-_'), '='),
                    'auth_token' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
                ]);
            }
        }
    }
}
