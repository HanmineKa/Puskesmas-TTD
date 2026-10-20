<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Files\PrivatePhotoStorage;
use App\Services\Logging\ActivityLogger;
use App\Services\Security\BlindIndex;
use Database\Seeders\AdministratorSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_client_side_routes_return_the_spa_shell(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('id="app"', false);
        $this->get('/registrasi/guru')->assertOk()->assertSee('id="app"', false);
        $this->get('/registrasi/siswi')->assertOk()->assertSee('id="app"', false);
    }

    public function test_api_login_validation_uses_indonesian_messages(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['identitas', 'password']);
    }

    public function test_login_rejects_passwords_outside_eight_to_fifteen_characters(): void
    {
        foreach (['1234567', '1234567890123456'] as $password) {
            $this->postJson('/api/login', [
                'identitas' => 'pengguna-uji',
                'password' => $password,
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
    }

    public function test_login_accepts_passwords_at_eight_and_fifteen_character_boundaries(): void
    {
        foreach ([
            ['pengguna-minimal', '12345678'],
            ['pengguna-maksimal', '123456789012345'],
        ] as [$identity, $password]) {
            User::factory()->create([
                'login_hash' => app(BlindIndex::class)->hash('USERNAME:'.$identity),
                'password' => $password,
            ]);

            $this->withHeader('Origin', 'http://localhost')
                ->postJson('/api/login', ['identitas' => $identity, 'password' => $password])
                ->assertOk();
        }
    }

    public function test_private_api_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_session_endpoint_returns_null_for_a_guest(): void
    {
        $this->getJson('/api/session')->assertOk()->assertExactJson([
            'user' => null,
            'requires_account_setup' => false,
            'setup_required' => [],
        ]);
    }

    public function test_security_headers_are_present(): void
    {
        $this->get('/')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_header_is_added_only_for_secure_production_requests(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        $this->withServerVariables(['HTTPS' => 'off'])
            ->get('http://localhost/')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_session_cookie_uses_secure_http_only_and_same_site_attributes(): void
    {
        config([
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);

        $response = $this->get('/');
        $cookieHeaders = strtolower(implode(';', $response->headers->all('set-cookie')));

        $this->assertStringContainsString('secure', $cookieHeaders);
        $this->assertStringContainsString('httponly', $cookieHeaders);
        $this->assertStringContainsString('samesite=lax', $cookieHeaders);
    }

    public function test_foundation_schema_includes_required_password_and_audit_scope_fields(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'wajib_ganti_sandi'));
        $this->assertFalse(Schema::hasColumn('users', 'two_factor_secret'));
        $this->assertFalse(Schema::hasColumn('users', 'two_factor_confirmed_at'));
        $this->assertTrue(Schema::hasColumn('audit_log', 'kategori'));
        $this->assertTrue(Schema::hasColumn('audit_log', 'pustu_id'));
    }

    public function test_log_tables_reject_updates_and_deletes(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Trigger append-only hanya diterapkan pada MySQL.');
        }

        $user = User::factory()->create();
        $request = request()->create('/uji-log', 'GET');
        $logger = app(ActivityLogger::class);
        $logger->login($user, 'LOGIN_BERHASIL', $request);
        $logger->audit($user, 'AKSI_UJI', 'Uji', 1, [], $request);
        $logger->dataAccess($user, 'BUKA_DETAIL_SISWI', 'Siswi', 1, $request);

        $entryIds = [
            'log_login' => DB::table('log_login')->where('user_id', $user->id)->value('id'),
            'audit_log' => DB::table('audit_log')->where('user_id', $user->id)->value('id'),
            'log_akses_data' => DB::table('log_akses_data')->where('user_id', $user->id)->value('id'),
        ];

        foreach ($entryIds as $table => $entryId) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    $query = DB::table($table)->where('id', $entryId);
                    if ($operation === 'update') {
                        $query->update(['created_at' => now()]);
                    } else {
                        $query->delete();
                    }
                } catch (QueryException) {
                    continue;
                }

                $this->fail("Tabel {$table} seharusnya menolak {$operation}.");
            }

            $this->assertSame(1, DB::table($table)->where('id', $entryId)->count());
        }
    }

    public function test_audit_log_records_scope_and_redacts_sensitive_values(): void
    {
        $user = User::factory()->create(['role' => 'ADMIN_PUSTU']);
        $puskesmasId = DB::table('puskesmas_utama')->insertGetId([
            'nama' => 'Puskesmas Pengujian',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pustuId = DB::table('pustu')->insertGetId([
            'puskesmas_utama_id' => $puskesmasId,
            'nama' => 'Pustu Pengujian',
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request = request()->create('/api/sekolah', 'POST');

        app(ActivityLogger::class)->audit(
            $user,
            'SEKOLAH_DIBUAT',
            'Sekolah',
            12,
            [
                'nama' => ['before' => 'Nama rahasia', 'after' => 'Nama baru'],
                'nomor_identitas' => ['before' => '1234567890123456', 'after' => '6543210987654321'],
                'status' => ['before' => 'NONAKTIF', 'after' => 'AKTIF'],
            ],
            $request,
            'OPERASIONAL',
            $pustuId,
        );

        $this->assertDatabaseHas('audit_log', [
            'user_id' => $user->id,
            'aksi' => 'SEKOLAH_DIBUAT',
            'kategori' => 'OPERASIONAL',
            'pustu_id' => $pustuId,
        ]);
        $changes = json_decode(DB::table('audit_log')->value('perubahan'), true);
        ksort($changes);
        ksort($changes['status']);

        $this->assertSame(
            [
                'nama' => ['berubah' => true],
                'nomor_identitas' => ['berubah' => true],
                'status' => ['after' => 'AKTIF', 'before' => 'NONAKTIF'],
            ],
            $changes,
        );
    }

    public function test_data_access_log_redacts_sensitive_filters_and_keeps_safe_filters(): void
    {
        $user = User::factory()->create();
        $request = request()->create('/api/siswi', 'GET');

        app(ActivityLogger::class)->dataAccess(
            $user,
            'BUKA_DETAIL_SISWI',
            'Siswi',
            17,
            $request,
            ['nisn' => '1234567890', 'filter' => ['tahun_ajaran' => '2026/2027']],
            1,
        );

        $this->assertSame(
            [
                'nisn' => ['disamarkan' => true],
                'filter' => ['tahun_ajaran' => '2026/2027'],
            ],
            json_decode(DB::table('log_akses_data')->value('filter'), true),
        );
    }

    public function test_administrator_seeder_creates_an_idempotent_account_from_environment_configuration(): void
    {
        config([
            'ttd.puskesmas_nama' => 'Puskesmas Pengujian',
            'ttd.administrator.nama' => 'Administrator Pengujian',
            'ttd.administrator.username' => 'admin-pengujian',
            'ttd.administrator.password' => 'kata-sandi-1',
        ]);

        $this->seed(AdministratorSeeder::class);
        $this->seed(AdministratorSeeder::class);

        $user = User::query()->where('username', 'admin-pengujian')->firstOrFail();

        $this->assertSame('ADMINISTRATOR', $user->role);
        $this->assertTrue(Hash::check('kata-sandi-1', $user->password));
        $this->assertSame(1, DB::table('administrator')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('puskesmas_utama')->count());
    }

    public function test_login_authenticates_with_blind_index_and_records_success(): void
    {
        $identity = 'siswi-001';
        $user = User::factory()->create([
            'login_hash' => app(BlindIndex::class)->hash('USERNAME:'.$identity),
            'password' => 'kata-sandi-uji',
        ]);

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', [
                'identitas' => $identity,
                'password' => 'kata-sandi-uji',
            ])
            ->assertOk()
            ->assertJsonPath('user.role', 'SISWI')
            ->assertJsonMissingPath('user.login_hash');

        $this->assertDatabaseHas('log_login', [
            'user_id' => $user->id,
            'peristiwa' => 'LOGIN_BERHASIL',
        ]);
    }

    public function test_login_sanitizes_markup_from_identity_before_authentication(): void
    {
        $identity = 'siswi-sanitasi';
        User::factory()->create([
            'login_hash' => app(BlindIndex::class)->hash('USERNAME:'.$identity),
            'password' => 'kata-sandi-uji',
        ]);

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', [
                'identitas' => '<b>siswi-sanitasi</b>',
                'password' => 'kata-sandi-uji',
            ])
            ->assertOk()
            ->assertJsonPath('user.role', 'SISWI');
    }

    public function test_petugas_login_uses_password_without_an_extra_code(): void
    {
        $identity = 'administrator-uji';

        User::factory()->create([
            'role' => 'ADMINISTRATOR',
            'username' => $identity,
            'login_hash' => app(BlindIndex::class)->hash('USERNAME:'.$identity),
            'password' => 'kata-sandi-uji',
        ]);

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', [
                'identitas' => $identity,
                'password' => 'kata-sandi-uji',
            ])
            ->assertOk()
            ->assertJsonPath('user.role', 'ADMINISTRATOR');
    }

    public function test_login_returns_only_setup_access_for_new_pustu_admin(): void
    {
        $identity = '198701012009011001';
        User::factory()->create([
            'role' => 'ADMIN_PUSTU',
            'login_hash' => app(BlindIndex::class)->hash('NIP:'.$identity),
            'password' => 'sandi-1-ujian',
            'wajib_ganti_sandi' => true,
        ]);

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', [
                'identitas' => $identity,
                'password' => 'sandi-1-ujian',
            ])
            ->assertOk()
            ->assertJsonPath('requires_account_setup', true)
            ->assertJsonPath('setup_required', ['GANTI_SANDI']);
    }

    public function test_evidence_photo_is_reencoded_cropped_and_encrypted_on_private_disk(): void
    {
        Storage::fake('local');

        $path = app(PrivatePhotoStorage::class)->storeEvidence(
            UploadedFile::fake()->image('bukti.png', 1600, 900),
        );

        $this->assertStringEndsWith('.jpg.enc', $path);
        $this->assertTrue(Storage::disk('local')->exists($path));

        $jpeg = app(PrivatePhotoStorage::class)->retrieveEvidence($path);
        $imageSize = getimagesizefromstring($jpeg);

        $this->assertSame(1024, $imageSize[0]);
        $this->assertSame(1024, $imageSize[1]);
        $this->assertStringStartsWith("\xFF\xD8", $jpeg);
        $this->assertStringNotContainsString("\xFF\xD8", Storage::disk('local')->get($path));
    }
}
