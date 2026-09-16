<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_active;
    }

    public function rules(): array
    {
        return [
            'queries' => ['nullable', 'string', 'max:20000'],
            'queries_file' => ['nullable', 'file', 'extensions:txt', 'mimetypes:text/plain', 'max:64'],
            'limit' => ['required', 'integer', 'min:1', 'max:'.config('gscraper.max_results_per_query')],
            'months' => ['required', 'integer', 'min:1', 'max:60'],
            'no_socials' => ['nullable', 'boolean'],
            'allow_unverified' => ['nullable', 'boolean'],
            'phone_is_channel' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'queries_file.extensions' => __('app.validation.queries_file'),
            'queries_file.mimetypes' => __('app.validation.queries_file'),
            'queries_file.max' => __('app.validation.queries_file_max'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $max = (int) config('gscraper.max_queries_per_run');
                if (! $this->searches()) {
                    $validator->errors()->add('queries', 'Enter at least one search, e.g. "dentists in Austin TX".');
                } elseif (count($this->searches(unbounded: true)) > $max) {
                    $validator->errors()->add('queries', "A run can have at most {$max} searches.");
                }
            },
        ];
    }

    /**
     * Searches from the textarea plus the optional .txt upload: one per line,
     * # comments ignored, duplicates removed.
     *
     * @return string[]
     */
    public function searches(bool $unbounded = false): array
    {
        $text = (string) $this->input('queries', '');
        $file = $this->file('queries_file');
        if ($file && $file->isValid()) {
            $content = (string) file_get_contents($file->getRealPath());
            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
            if (mb_check_encoding($content, 'UTF-8')) {
                $text .= "\n".$content;
            }
        }

        $seen = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $line) ?? '');
            $line = ltrim($line, "- \t");
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $line = mb_substr($line, 0, 200);
            $seen[mb_strtolower($line)] ??= $line;
        }
        $searches = array_values($seen);

        return $unbounded ? $searches : array_slice($searches, 0, (int) config('gscraper.max_queries_per_run'));
    }

    /** @return array{limit: int, months: int, noSocials: bool, allowUnverified: bool, phoneIsChannel: bool} */
    public function options(): array
    {
        return [
            'limit' => $this->integer('limit'),
            'months' => $this->integer('months'),
            'noSocials' => $this->boolean('no_socials'),
            'allowUnverified' => $this->boolean('allow_unverified'),
            'phoneIsChannel' => $this->boolean('phone_is_channel'),
        ];
    }
}
