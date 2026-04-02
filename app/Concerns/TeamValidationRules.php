<?php

namespace App\Concerns;

trait TeamValidationRules
{
    /**
     * Get the validation rules used to validate team names.
     *
     * @return array<int, string>
     */
    protected function teamNameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }
}
