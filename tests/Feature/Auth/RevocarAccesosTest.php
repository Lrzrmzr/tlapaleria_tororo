<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Auditoría run-1 (hardening): cambiar o restablecer la contraseña no
 * revocaba los tokens Passport (6 meses) ni las demás sesiones, así que quien
 * hubiera robado una sesión o token conservaba el acceso.
 */
class RevocarAccesosTest extends TestCase
{
    use RefreshDatabase;

    private function crearToken(User $user): string
    {
        $id = Str::random(80);
        DB::table('oauth_access_tokens')->insert([
            'id'         => $id,
            'user_id'    => $user->id,
            'client_id'  => (string) Str::uuid(),
            'name'       => 'mobile-app',
            'scopes'     => '[]',
            'revoked'    => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addMonths(6),
        ]);

        return $id;
    }

    private function crearSesion(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id'            => $id,
            'user_id'       => $user->id,
            'payload'       => '',
            'last_activity' => time(),
        ]);
    }

    public function test_cambiar_la_password_revoca_los_tokens_y_rota_remember_token(): void
    {
        $user = User::factory()->create(['remember_token' => 'token-viejo']);
        $this->crearToken($user);

        $this->actingAs($user)->from('/profile')->put('/password', [
            'current_password'      => 'password',
            'password'              => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $user->id)->count());
        $this->assertNotSame('token-viejo', $user->fresh()->remember_token);
    }

    public function test_restablecer_la_password_revoca_los_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->crearToken($user);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/reset-password', [
                'token'                 => $notification->token,
                'email'                 => $user->email,
                'password'              => 'otra-password',
                'password_confirmation' => 'otra-password',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $user->id)->count());
    }

    public function test_con_sesiones_en_bd_cierra_las_demas_y_conserva_la_actual(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $otro = User::factory()->create();
        $this->crearSesion($user, 'sesion-actual');
        $this->crearSesion($user, 'sesion-robada');
        $this->crearSesion($otro, 'sesion-de-otro');

        $user->revocarOtrosAccesos('sesion-actual');

        $this->assertSame(['sesion-actual'], DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all());
        $this->assertTrue(DB::table('sessions')->where('id', 'sesion-de-otro')->exists());
    }
}
