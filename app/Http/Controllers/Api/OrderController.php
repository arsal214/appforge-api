<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Mail\OrderArrived;
use App\Models\App as ClientApp;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    private const PER_PAGE = 50;

    public function store(StoreOrderRequest $request, string $app): JsonResponse
    {
        // Unauthenticated, so the app cannot be resolved through the
        // organisation scope the way every panel route does it.
        $exists = DB::table('apps')
            ->where('id', $app)
            ->whereNull('deleted_at')
            ->exists();

        abort_unless($exists, 404, 'That app is not available.');

        $order = $this->record($app, $request, test: false);

        $this->tellTheShop($order);

        return response()->json(['data' => ['number' => $order->number]], 201);
    }

    public function storeTest(StoreOrderRequest $request, string $app): JsonResponse
    {
        $model = $this->findOwned($request, $app);

        $order = $this->record($model->getKey(), $request, test: true);

        $this->tellTheShop($order);

        return (new OrderResource($order))->response()->setStatusCode(201);
    }

    public function clearTests(Request $request, string $app): JsonResponse
    {
        $this->findOwned($request, $app)->orders()->where('is_test', true)->delete();

        return response()->json(['data' => null]);
    }

    private function tellTheShop(Order $order): void
    {
        try {
            $app = DB::table('apps')
                ->where('id', $order->app_id)
                ->first(['id', 'name', 'organization_id']);

            if (! $app) {
                return;
            }

            $owners = User::where('organization_id', $app->organization_id)
                ->where('role', 'owner')
                ->get();

            foreach ($owners as $owner) {
                Mail::to($owner->email)->queue(
                    new OrderArrived($app->id, $app->name, $order),
                );
            }
        } catch (\Throwable $e) {
            Log::error('Could not queue the order notification', [
                'order' => $order->getKey(),
                'app' => $order->app_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function record(string $appId, StoreOrderRequest $request, bool $test): Order
    {
        return DB::transaction(function () use ($appId, $request, $test) {
            $taken = (int) Order::where('app_id', $appId)->lockForUpdate()->max('number');

            return Order::create([
                'app_id' => $appId,
                'number' => $taken + 1,
                'status' => 'new',
                'kind' => $request->validated('kind')
                    ?? ($request->validated('lines') === [] ? 'booking' : 'order'),
                'form_id' => $request->validated('formId'),
                'is_test' => $test,
                'lines' => $request->validated('lines'),
                'details' => $request->validated('details'),
                'total' => $request->validated('total'),
                'platform' => $request->validated('platform'),
            ]);
        });
    }

    public function index(Request $request, string $app): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(Order::statuses())],
            'kind' => ['nullable', Rule::in(Order::kinds())],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);

        $owned = $this->findOwned($request, $app);

        // By number, not created_at: the column holds whole seconds, so two
        // orders placed in the same second would come back in an arbitrary
        $query = $owned->orders()->orderByDesc('number');

        foreach (['status', 'kind'] as $field) {
            if (($validated[$field] ?? null) !== null) {
                $query->where($field, $validated[$field]);
            }
        }

        if (($validated['before'] ?? null) !== null) {
            $query->where('number', '<', $validated['before']);
        }

        $rows = $query->limit(self::PER_PAGE + 1)->get();

        return response()->json([
            'data' => [
                'orders' => OrderResource::collection($rows->take(self::PER_PAGE)),
                'waiting' => $this->waiting($owned),
                'hasMore' => $rows->count() > self::PER_PAGE,
            ],
        ]);
    }

    private function waiting(ClientApp $app): array
    {
        $counts = $app->orders()
            ->where('status', 'new')
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        $waiting = [];
        foreach (Order::kinds() as $kind) {
            $waiting[$kind] = (int) ($counts[$kind] ?? 0);
        }

        return $waiting;
    }

    public function update(Request $request, string $app, string $order): OrderResource
    {
        $record = $this->findOwned($request, $app)->orders()->findOrFail($order);

        $validated = $request->validate([
            'status' => ['required', Rule::in(Order::statusesFor($record->kind))],
        ]);

        $record->update(['status' => $validated['status']]);

        return new OrderResource($record);
    }

    private function findOwned(Request $request, string $id): ClientApp
    {
        return $request->user()->organization->apps()->findOrFail($id);
    }
}
