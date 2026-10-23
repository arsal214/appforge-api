<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePushCampaignRequest;
use App\Http\Resources\PushCampaignResource;
use App\Models\App as ClientApp;
use App\Support\Push\LogPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PushCampaignController extends Controller
{
    public function index(Request $request, string $app): AnonymousResourceCollection
    {
        $model = $this->findOwned($request, $app);

        return PushCampaignResource::collection(
            $model->pushCampaigns()->latest()->limit(100)->get()
        );
    }

    public function audience(Request $request, string $app): JsonResponse
    {
        $model = $this->findOwned($request, $app);

        return response()->json([
            'data' => [
                'total' => $model->devices()->count(),
                'ios' => $model->devices()->where('platform', 'ios')->count(),
                'android' => $model->devices()->where('platform', 'android')->count(),

                'canDeliver' => false,
                'blockedReason' => 'Push delivery needs your own Apple and Google developer accounts. Nothing is sent yet.',
            ],
        ]);
    }

    public function store(StorePushCampaignRequest $request, string $app): JsonResponse
    {
        $model = $this->findOwned($request, $app);

        $scheduledFor = $request->validated('scheduledFor');

        $campaign = $model->pushCampaigns()->create([
            'title' => trim($request->validated('title')),
            'body' => trim($request->validated('body')),
            'action' => $request->validated('action'),
            'status' => $scheduledFor ? 'scheduled' : 'draft',
            'scheduled_for' => $scheduledFor,
            'created_by' => $request->user()->getKey(),
        ]);

        return (new PushCampaignResource($campaign))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $app, string $campaign): JsonResponse
    {
        $model = $this->findOwned($request, $app);
        $record = $model->pushCampaigns()->findOrFail($campaign);

        if (! $record->isEditable()) {
            abort(422, 'A notification that has already been sent can\'t be deleted.');
        }

        $record->delete();

        return response()->json(['data' => null]);
    }

    public function send(Request $request, string $app, string $campaign): JsonResponse
    {
        $model = $this->findOwned($request, $app);
        $record = $model->pushCampaigns()->findOrFail($campaign);

        if (! $record->isEditable()) {
            abort(422, 'This notification has already been sent.');
        }

        $record->update(['status' => 'sending']);

        $devices = $model->devices()->cursor();
        $result = (new LogPushSender)->send($record, $devices);

        $record->update([
            'status' => $result->delivered > 0 ? 'sent' : 'failed',
            'sent_at' => now(),
            'recipient_count' => $result->delivered,
            'failure_count' => $result->failed,
            'failure_reason' => $result->reason,
        ]);

        return response()->json([
            'data' => new PushCampaignResource($record->fresh()),
        ]);
    }

    private function findOwned(Request $request, string $id): ClientApp
    {
        return $request->user()->organization->apps()->findOrFail($id);
    }
}
