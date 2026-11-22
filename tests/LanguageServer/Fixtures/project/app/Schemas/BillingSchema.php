<?php

namespace App\Schemas;

use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Constants\Constant;
use Warrant\Schema\Types\Classes\RowlessSchema;

final class BillingSchema extends RowlessSchema
{
    #[Ability]
    public const MANAGE = 'manage';

    /** The prefix every invoice number starts with. */
    #[Constant]
    public const INVOICE_PREFIX = 'inv';

    #[Constant('ledger')]
    public const LEDGER_NAME = 'general';

    public const AUDITORS = ['audit', 'review'];

    #[Constant]
    public const MANAGERS = [self::MANAGE, ...self::AUDITORS];

    #[Constant]
    public const COMPUTED = 'in'.'voice';

    #[Constant]
    public const STATUS = InvoiceStatus::Open;
}
