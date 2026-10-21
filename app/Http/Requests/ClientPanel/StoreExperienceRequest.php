<?php

namespace App\Http\Requests\ClientPanel;

use App\Http\Requests\Admin\StoreExperienceRequest as AdminStoreExperienceRequest;

/**
 * Versión client de `Admin\StoreExperienceRequest`. El portal sale del token,
 * así que `client_id` no se acepta; tampoco la fuente ni el id externo (son de
 * las importaciones del staff). `is_featured` sí: el catálogo es por client y
 * destacar sólo reordena su propio portal.
 */
class StoreExperienceRequest extends AdminStoreExperienceRequest
{
    public function rules(): array
    {
        return ExperienceClientFields::strip(parent::rules());
    }
}
