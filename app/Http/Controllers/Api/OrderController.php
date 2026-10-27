<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\App as ClientApp;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request, string $app): JsonResponse
    {
        // Unauthenticated, so the app cannot be resolved through the
        // organisation scope the way every panel route does it.
        $exists = DB::table('apps')
            ->where('id', $app)
            ->whereNull('deleted_at')
            ->exists();

        abort_unless($exists, 404, 'That app is not available.');

        $order = DB::transaction(function () use ($request, $app) {
            $taken = (int) Order::where('app_id', $app)->lockForUpdate()->max('number');

            return Order::create([
                'app_id' => $app,
                'number' => $taken + 1,
                'status' => 'new',
                'lines' => $request->validated('lines'),
                'details' => $request->validated('details'),
                'total' => $request->validated('total'),
                'platform' => $request->validated('platform'),
            ]);
        });

        return response()->json(['data' => ['number' => $order->number]], 201);
    }

    public function index(Request $request, string $app): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(Order::STATUSES)],
        ]);

        // By number, not created_at: the column holds whole seconds, so two
        // orders placed in the same second would come back in an arbitrary
        // order. The number is the sequence anyway.
        $orders = $this->findOwned($request, $app)->orders()->orderByDesc('number');

        if (($validated['status'] ?? null) !== null) {
            $orders->where('status', $validated['status']);
        }

        return OrderResource::collection($orders->limit(200)->get());
    }

    public function update(Request $request, string $app, string $order): OrderResource
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
        ]);

        $record = $this->findOwned($request, $app)->orders()->findOrFail($order);
        $record->update(['status' => $validated['status']]);

        return new OrderResource($record);
    }

    private function findOwned(Request $request, string $id): ClientApp
    {
        return $request->user()->organization->apps()->findOrFail($id);
    }
}
