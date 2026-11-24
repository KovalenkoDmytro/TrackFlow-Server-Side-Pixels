<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;

final class SupportReportRequest extends FormRequest
{
    /** The shop comes from the session token; a request can only report its own issues. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', 'in:'.Platform::GoogleAds->value.','.Platform::Meta->value],
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_]+$/'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
