<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['ADMINISTRATOR', 'ADMIN_PUSTU'], true), 403);

        $filters = $request->validate([
            'mulai' => ['nullable', 'date_format:Y-m-d'],
            'selesai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:mulai'],
            'aksi' => ['nullable', 'string', 'max:60'],
            'pengguna_id' => ['nullable', 'integer', 'exists:users,id'],
            'pustu_id' => ['nullable', 'integer', 'exists:pustu,id'],
            'kategori' => ['nullable', 'in:OPERASIONAL,KEAMANAN'],
            'objek_tipe' => ['nullable', 'string', 'max:50'],
            'halaman' => ['nullable', 'in:audit,login,akses'],
        ]);
        $audit = DB::table('audit_log')->orderByDesc('created_at')->orderByDesc('id');
        $audit->when(isset($filters['mulai']), fn ($query) => $query->whereDate('created_at', '>=', $filters['mulai']));
        $audit->when(isset($filters['selesai']), fn ($query) => $query->whereDate('created_at', '<=', $filters['selesai']));
        $audit->when(isset($filters['aksi']), fn ($query) => $query->where('aksi', $filters['aksi']));
        $audit->when(isset($filters['kategori']), fn ($query) => $query->where('kategori', $filters['kategori']));
        $audit->when(isset($filters['objek_tipe']), fn ($query) => $query->where('objek_tipe', $filters['objek_tipe']));

        if ($user->isAdministrator()) {
            $audit->when(isset($filters['pengguna_id']), fn ($query) => $query->where('user_id', $filters['pengguna_id']));
            $audit->when(isset($filters['pustu_id']), fn ($query) => $query->where('pustu_id', $filters['pustu_id']));
            $login = DB::table('log_login')->orderByDesc('created_at')->orderByDesc('id');
            $access = DB::table('log_akses_data')->orderByDesc('created_at')->orderByDesc('id');
            foreach (['login', 'access'] as $log) {
                $query = $log === 'login' ? $login : $access;
                $query->when(isset($filters['mulai']), fn ($builder) => $builder->whereDate('created_at', '>=', $filters['mulai']));
                $query->when(isset($filters['selesai']), fn ($builder) => $builder->whereDate('created_at', '<=', $filters['selesai']));
                $query->when(isset($filters['pengguna_id']), fn ($builder) => $builder->where('user_id', $filters['pengguna_id']));
            }

            return response()->json([
                'audit' => $audit->paginate(25, ['*'], 'audit_page'),
                'login' => ($filters['halaman'] ?? null) === 'audit' ? null : $login->paginate(25, ['*'], 'login_page'),
                'akses_data' => ($filters['halaman'] ?? null) === 'audit' ? null : $access->paginate(25, ['*'], 'akses_page'),
            ]);
        }

        $pustuId = $user->adminPustu?->pustu_id;
        abort_unless($pustuId, 403);
        $audit->where('kategori', 'OPERASIONAL')->where('pustu_id', $pustuId);

        return response()->json(['audit' => $audit->paginate(25)]);
    }
}
