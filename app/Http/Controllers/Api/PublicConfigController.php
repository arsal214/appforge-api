<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicConfigController extends Controller
{
    public function show(Request $request, string $app): JsonResponse
    {

        $row = DB::table('app_versions')
            ->join('apps', 'apps.id', '=', 'app_versions.app_id')
            ->where('app_versions.app_id', $app)
            ->whereNull('apps.deleted_at')

            ->orderByDesc('app_versions.version')
            ->first(['app_versions.id', 'app_versions.config']);

        if (! $row) {

            return $this->notFound();
        }

        $config = json_decode((string) $row->config, true);

        if (! is_array($config)) {

            return $this->notFound();
        }

        return response()
            ->json(['data' => $config])
            ->withHeaders([

                'Cache-Control' => 'public, max-age=60, stale-while-revalidate=600',

                'ETag' => '"'.$row->id.'"',
            ]);
    }

    private function notFound(): JsonResponse
    {

        return response()->json(['message' => 'Not found.'], 404);
    }
}
