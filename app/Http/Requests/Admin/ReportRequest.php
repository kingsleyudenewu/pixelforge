<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
{
    /** Pixelforge's GET /v1/usage accepts at most this many days. */
    public const int MAX_DAYS = 92;

    private const int DEFAULT_DAYS = 30;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->startDate()->diffInDays($this->endDate()) + 1 > self::MAX_DAYS) {
                    $validator->errors()->add('end', 'Choose a range of at most '.self::MAX_DAYS.' days.');
                }
            },
        ];
    }

    /**
     * Report dates are UTC days, matching how Pixelforge bills.
     */
    public function endDate(): CarbonImmutable
    {
        $end = $this->input('end');

        return is_string($end) ? CarbonImmutable::parse($end, 'UTC') : CarbonImmutable::now('UTC')->startOfDay();
    }

    public function startDate(): CarbonImmutable
    {
        $start = $this->input('start');

        return is_string($start) ? CarbonImmutable::parse($start, 'UTC') : $this->endDate()->subDays(self::DEFAULT_DAYS - 1);
    }
}
