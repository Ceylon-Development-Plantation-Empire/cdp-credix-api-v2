<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans\Concerns;

use App\Models\Setting;
use App\Support\BusinessDate;

/**
 * The two fields an application carries besides its terms:
 * - application_date: the day the customer applied (a customer who comes back to finish an application can be
 *   dated the day he came, or the day he started); never in the future, and not further back than
 *   the setting loan.application_backdate_days;
 * - allow_multiple_open + multiple_open_reason: the special case of a second open loan (permission "Loan Multiple Open
 *   Allow", checked by the service) with a written reason.
 */
trait ApplicationFields
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function applicationFieldRules(): array
    {
        $days = (int) Setting::value('loan.application_backdate_days', 30);
        $today = BusinessDate::todayImmutable();

        return [
            'application_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString(), 'after_or_equal:'.$today->subDays($days)->toDateString()],
            'allow_multiple_open' => ['sometimes', 'boolean'],
            'multiple_open_reason' => ['required_if:allow_multiple_open,true,1', 'nullable', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $days = (int) Setting::value('loan.application_backdate_days', 30);

        return [
            'application_date.before_or_equal' => 'The application date cannot be in the future.',
            'application_date.after_or_equal' => "The application date cannot be more than {$days} days back.",
            'multiple_open_reason.required_if' => 'Give the reason why this customer may have more than one open loan.',
        ];
    }
}
