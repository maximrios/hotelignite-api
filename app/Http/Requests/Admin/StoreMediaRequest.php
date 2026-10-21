<?php

namespace App\Http\Requests\Admin;

use App\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta de multimedia: una imagen (URL https, normalmente de Cloudinary) o un
 * video de YouTube (cualquier forma de su URL). No se aceptan otros videos.
 */
class StoreMediaRequest extends FormRequest
{
    /** Dueño del evento o la experiencia antes que validación (staff: todos; client: los suyos). */
    public function authorize(): bool
    {
        $owner = $this->route('event') ?? $this->route('experience');

        return $owner !== null && $this->user()?->can('update', $owner) === true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([Media::TYPE_IMAGE, Media::TYPE_VIDEO])],
            'url' => ['required', 'string', 'url:https', 'max:2048'],
            'thumbnail_url' => ['nullable', 'string', 'url:https', 'max:2048'],
            'alt' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->input('type') === Media::TYPE_VIDEO && Media::youTubeId((string) $this->input('url')) === null) {
                $validator->errors()->add('url', 'Sólo se admiten videos de YouTube.');
            }
        }];
    }
}
