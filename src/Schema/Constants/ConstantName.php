<?php

namespace Warrant\Schema\Constants;

/**
 * The name rule text uses for a `#[Constant]`. The schema's reflection and the
 * language server's reading of a schema's source both decide it here, so the
 * engine and the editor always agree on a constant's name.
 */
final class ConstantName
{
    /**
     * $explicitName when one is given. Otherwise $phpName in lowercase snake_case:
     * a name written in capitals is lowercased, so `PAYROLL_MODULE` is
     * `payroll_module`, and any other name is snake-cased, so `payrollModule` is
     * `payroll_module`.
     *
     * @param string $phpName The class constant's name.
     * @param string|null $explicitName The name the attribute gives, if any.
     */
    public static function of(string $phpName, ?string $explicitName = null): string
    {
        if ($explicitName !== null && $explicitName !== '') {
            return $explicitName;
        }

        if (strtoupper($phpName) === $phpName) {
            return strtolower($phpName);
        }

        return strtolower((string) preg_replace('/(.)(?=[A-Z])/u', '$1_', $phpName));
    }
}
