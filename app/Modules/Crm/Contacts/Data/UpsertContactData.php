<?php

namespace App\Modules\Crm\Contacts\Data;

use App\Modules\Crm\Contacts\Models\Contact;
use Closure;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UpsertContactData extends Data
{
    /**
     * @param  list<string>|Optional|null  $secondary_emails
     * @param  list<mixed>|Optional|null  $phones
     * @param  array<string, mixed>|Optional  $custom_fields
     */
    public function __construct(
        public string|Optional|null $company_id = new Optional,
        public string|Optional $first_name = new Optional,
        public string|Optional $last_name = new Optional,
        public string|Optional|null $primary_email = new Optional,
        public array|Optional|null $secondary_emails = new Optional,
        public array|Optional|null $phones = new Optional,
        public string|Optional|null $job_title = new Optional,
        public bool|Optional $is_primary_contact = new Optional,
        public array|Optional $custom_fields = new Optional,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $isCreate = ! (request()->route('contact') instanceof Contact);

        return [
            'company_id' => ['sometimes', 'nullable', 'uuid'],
            'first_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'last_name' => [$isCreate ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'primary_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'secondary_emails' => ['sometimes', 'nullable', 'array', 'max:25'],
            'secondary_emails.*' => ['required', 'string', 'email', 'max:255'],
            'phones' => ['sometimes', 'nullable', 'array', 'max:10'],
            'phones.*' => [
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value)) {
                        $len = mb_strlen(trim($value));
                        if ($len === 0 || $len > 64) {
                            $fail('Each phone number must be between 1 and 64 characters.');
                        }

                        return;
                    }

                    if (
                        is_array($value)
                        && is_string($value['number'] ?? null)
                        && trim($value['number']) !== ''
                        && mb_strlen(trim($value['number'])) <= 64
                    ) {
                        return;
                    }

                    $fail('Each phone entry must be a phone string or object with a number.');
                },
            ],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_primary_contact' => ['sometimes', 'boolean'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
