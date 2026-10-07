<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppRequest;
use App\Http\Requests\UpdateAppRequest;
use App\Http\Resources\AppResource;
use App\Models\App as ClientApp;
use App\Models\Organization;
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

    public function store(StoreAppRequest $request): JsonResponse
    {
        // Validated against the same list, so this cannot be null.
        $template = Templates::find($request->validated('templateId'));

        $app = $this->apps($request)->create([
            'name' => trim($request->validated('name')),
            'slug' => Str::slug($request->validated('name')),
            'source_url' => $request->validated('sourceUrl'),
            'template_id' => $template['id'],
            'industry' => $template['industry'],
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
        // `has` and not `filled`: null is a meaningful value for sourceUrl and
        // `filled` would drop the customer clearing the field.
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

        // A live app exists in two store consoles that this API does not
        // control. Deleting the row here would leave the customer's app on
        // sale with nothing behind it. Unpublishing is a human process.
        if ($model->status === 'live') {
            abort(422, "A published app can't be deleted here. Contact us and we'll unpublish it properly.");
        }

        $model->delete();

        return response()->json(['data' => null], 200);
    }

    /**
     * The tenancy boundary. Every query in this controller starts here, so
     * "which organisation?" is answered once by the relationship rather than
     * re-derived (and eventually forgotten) per action. The OrganizationScope
     * global scope covers the same ground a second time.
     *
     * @return HasMany<ClientApp, Organization>
     */
    private function apps(Request $request)
    {
        return $request->user()->organization->apps();
    }

    /**
     * Another organisation's app is **404, not 403** (Architecture §7.1). A 403
     * confirms the record exists, which tells a competitor that a business is
     * our customer. `findOrFail` on a tenant-scoped relation gives exactly the
     * right answer with no branch to get wrong.
     */
    private function findOwned(Request $request, string $id): ClientApp
    {
        return $this->apps($request)->findOrFail($id);
    }
}
