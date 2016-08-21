<?php

namespace Despark\Cms\Http\Requests\Admin;

use Despark\Cms\Http\Requests\AdminFormRequest;
use Despark\Cms\Models\SeoPage;

class SeoPagesRequest extends AdminFormRequest
{
    public function __construct()
    {
        $this->model = new SeoPage();
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * @return array
     */
    public function messages()
    {
        return $this->changeAttrName();
    }
}
