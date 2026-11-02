<?php

namespace App\Http\Requests\Concerns;

trait HasApiValidationMessages
{
    /**
     * Shared validation messages from lang/{locale}/messages.php.
     * Override customMessages() in the FormRequest for domain-specific keys.
     */
    public function messages(): array
    {
        return array_merge($this->defaultMessages(), $this->customMessages());
    }

    /**
     * Shared attribute labels from lang/{locale}/messages.php.
     * Override customAttributes() for domain-specific labels.
     */
    public function attributes(): array
    {
        $common = trans('messages.attributes');

        if (! is_array($common)) {
            $common = [];
        }

        return array_merge($common, $this->customAttributes());
    }

    /**
     * Domain-specific message overrides.
     *
     * @return array<string, string>
     */
    protected function customMessages(): array
    {
        return [];
    }

    /**
     * Domain-specific attribute label overrides.
     *
     * @return array<string, string>
     */
    protected function customAttributes(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    protected function defaultMessages(): array
    {
        return [
            'required' => __('messages.validation.required'),
            'string' => __('messages.validation.string'),
            'email' => __('messages.validation.email'),
            'integer' => __('messages.validation.integer'),
            'numeric' => __('messages.validation.numeric'),
            'boolean' => __('messages.validation.boolean'),
            'array' => __('messages.validation.array'),
            'file' => __('messages.validation.file'),
            'image' => __('messages.validation.image'),
            'mimes' => __('messages.validation.mimes'),
            'in' => __('messages.validation.in'),
            'unique' => __('messages.validation.unique'),
            'exists' => __('messages.validation.exists'),
            'confirmed' => __('messages.validation.confirmed'),
            'uuid' => __('messages.validation.uuid'),
            'url' => __('messages.validation.url'),
            'alpha_dash' => __('messages.validation.alpha_dash'),
            'between.numeric' => __('messages.validation.between.numeric'),
            'date' => __('messages.validation.date'),
            'date_format' => __('messages.validation.date_format'),
            'after_or_equal' => __('messages.validation.after_or_equal'),
            'min.numeric' => __('messages.validation.min.numeric'),
            'min.integer' => __('messages.validation.min.integer'),
            'min.string' => __('messages.validation.min.string'),
            'min.array' => __('messages.validation.min.array'),
            'min.file' => __('messages.validation.min.file'),
            'max.numeric' => __('messages.validation.max.numeric'),
            'max.integer' => __('messages.validation.max.integer'),
            'max.string' => __('messages.validation.max.string'),
            'max.array' => __('messages.validation.max.array'),
            'max.file' => __('messages.validation.max.file'),
        ];
    }
}
