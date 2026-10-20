<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlasanTidakMinum;
use Illuminate\Http\JsonResponse;

class AlasanTidakMinumController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => AlasanTidakMinum::query()
                ->where('aktif', true)
                ->orderBy('nama')
                ->get(['id', 'kode', 'nama', 'kategori']),
        ]);
    }
}
