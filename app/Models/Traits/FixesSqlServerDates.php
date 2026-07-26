<?php

namespace App\Models\Traits;

trait FixesSqlServerDates
{
    /**
     * Convert a DateTime string from SQL Server to a Carbon instance safely.
     * Handles SQL Server non-standard date string formats like 'Jul 25 2026 12:00:00:AM'.
     *
     * @param  mixed  $value
     * @return \Illuminate\Support\Carbon|null
     */
    protected function asDateTime($value)
    {
        if (is_string($value)) {
            // Fix SQL Server colon before AM/PM: 'Jul 25 2026 12:00:00:AM' -> 'Jul 25 2026 12:00:00 AM'
            $value = str_replace([':AM', ':PM', ':am', ':pm'], [' AM', ' PM', ' am', ' pm'], $value);
        }

        return parent::asDateTime($value);
    }
}
