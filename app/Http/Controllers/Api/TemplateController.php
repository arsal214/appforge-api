<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Templates;
use Illuminate\Http\JsonResponse;

class TemplateController extends Controller
{
    /**
     * Public on purpose: the marketing site's preview page offers a template
     * before anyone has an account. There is nothing tenant-specific here.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Templates::ALL]);
    }
}
