<?php

namespace App\Http\Requests;

use App\Models\App;
use App\Models\Upload;
use App\Support\BundleId;
use App\Support\MediaUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAppRequest extends FormRequest
{
    public const MIN_ICON_EDGE = 1024;

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'sourceUrl' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:2048'],

            'accentColor' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],

            'iconUrl' => ['sometimes', 'nullable', 'string', 'max:2048'],

            'splashColor' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],

            // Unique across every business: a store takes an id once,
            // whoever asks for it.
            'bundleId' => [
                'sometimes',
                'string',
                'max:'.BundleId::MAX_LENGTH,
                Rule::unique('apps', 'bundle_id')->ignore($this->route('app')),
            ],

            'businessProfileId' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('business_profiles', 'id')->where(
                    'organization_id',
                    $this->user()?->organization_id,
                ),
            ],
        ];
    }

    /**
     * The icon has to be an image this business uploaded here, square, and
     * big enough for the App Store, which takes 1024 pixels and nothing less.
     * A link to someone else's server would be an icon that can change, or
     * vanish, after the app has shipped.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $id = $this->input('bundleId');

                if (! is_string($id) || $validator->errors()->has('bundleId')) {
                    return;
                }

                if (! BundleId::wellFormed($id)) {
                    $validator->errors()->add(
                        'bundleId',
                        'Use lowercase letters and numbers in parts joined by dots, like com.yourbusiness.app. Each part starts with a letter.',
                    );

                    return;
                }

                if (BundleId::reserved($id)) {
                    $validator->errors()->add('bundleId', 'That ID belongs to someone else. Choose one of your own, like com.yourbusiness.app.');

                    return;
                }

                // Both stores bind a listing to its id for good. Changing it
                // after that would be a second app, with none of the installs
                // or reviews of the first.
                $app = App::find($this->route('app'));

                if ($app !== null && $app->published_version !== null && $app->bundle_id !== $id) {
                    $validator->errors()->add('bundleId', 'This app is already on the stores, and its ID cannot change after that.');
                }
            },
            function (Validator $validator): void {
                $url = $this->input('iconUrl');

                if ($validator->errors()->isNotEmpty() || ! is_string($url) || $url === '') {
                    return;
                }

                $upload = $this->iconUpload();

                if ($upload === null) {
                    $validator->errors()->add('iconUrl', 'Upload the icon here rather than linking to it.');

                    return;
                }

                $size = @getimagesize(Storage::disk('public')->path($upload->path));

                if ($size === false || $size[0] !== $size[1]) {
                    $validator->errors()->add('iconUrl', 'An app icon has to be square. Crop it and upload it again.');
                } elseif ($size[0] < self::MIN_ICON_EDGE) {
                    $validator->errors()->add(
                        'iconUrl',
                        'Use a square image at least '.self::MIN_ICON_EDGE.' pixels wide — the App Store asks for that size. This one is '.$size[0].'.',
                    );
                }
            },
        ];
    }

    /** The upload behind the address sent, if it is one of this business's own. */
    public function iconUpload(): ?Upload
    {
        $url = $this->input('iconUrl');

        if (! is_string($url) || $url === '') {
            return null;
        }

        $reference = MediaUrl::forStorage(['icon' => $url])['icon'];

        if (! str_starts_with($reference, '/storage/')) {
            return null;
        }

        return Upload::where('organization_id', $this->user()?->organization_id)
            ->where('path', substr($reference, strlen('/storage/')))
            ->first();
    }

    public function messages(): array
    {
        return [
            'name.min' => 'Give the app a name.',
            'sourceUrl.url' => 'Enter a valid website address.',
            'accentColor.regex' => 'Pick a colour like #0f766e.',
            'splashColor.regex' => 'Pick a colour like #0f766e.',
            'bundleId.unique' => 'Another app already uses that ID. Choose a different one.',
            'businessProfileId.exists' => 'Those saved business details are not available.',
        ];
    }
}
