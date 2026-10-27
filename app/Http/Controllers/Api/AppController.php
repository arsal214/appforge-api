<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppRequest;
use App\Http\Requests\UpdateAppRequest;
use App\Http\Resources\AppResource;
use App\Models\App as ClientApp;
use App\Support\Templates;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class AppController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AppResource::collection(
            $this->apps($request)->latest()->get()
        );
    }

    /**
     * Apps the customer has deleted.
     *
     * `onlyTrashed` drops the soft-delete scope and nothing else, so the
     * organisation scope still applies and another tenant's bin stays invisible.
     */
    public function trashed(Request $request): AnonymousResourceCollection
    {
        return AppResource::collection(
            $this->apps($request)->onlyTrashed()->latest('deleted_at')->get()
        );
    }

    public function store(StoreAppRequest $request): JsonResponse
    {

        $template = Templates::find($request->validated('templateId'));

        $app = $this->apps($request)->create([
            'name' => trim($request->validated('name')),
            'slug' => Str::slug($request->validated('name')),
            'source_url' => $request->validated('sourceUrl'),
            'template_id' => $template['id'],
            'industry' => $template['industry'],
            'category' => $request->validated('category'),
            'status' => 'draft',
            'accent_color' => $template['accentColor'],
        ]);

        return (new AppResource($app))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $app): AppResource
    {
        return new AppResource($this->findOwned($request, $app));
    }

    public function update(UpdateAppRequest $request, string $app): AppResource
    {
        $model = $this->findOwned($request, $app);
        $changes = [];

        if ($request->has('name')) {
            $changes['name'] = trim($request->validated('name'));
            $changes['slug'] = Str::slug($request->validated('name'));
        }

        if ($request->has('sourceUrl')) {
            $changes['source_url'] = $request->validated('sourceUrl');
        }
        if ($request->has('accentColor')) {
            $changes['accent_color'] = $request->validated('accentColor');
        }

        $model->update($changes);

        return new AppResource($model);
    }

    public function destroy(Request $request, string $app): JsonResponse
    {
        $model = $this->findOwned($request, $app);

        if ($model->status === 'live') {
            abort(422, "A published app can't be deleted here. Contact us and we'll unpublish it properly.");
        }

        $model->delete();

        return response()->json(['data' => null], 200);
    }

    public function restore(Request $request, string $app): AppResource
    {
        $model = $this->apps($request)->onlyTrashed()->findOrFail($app);
        $model->restore();

        return new AppResource($model);
    }

    private function apps(Request $request): HasMany
    {
        return $request->user()->organization->apps();
    }

    private function findOwned(Request $request, string $id): ClientApp
    {
        return $this->apps($request)->findOrFail($id);
    }
}
