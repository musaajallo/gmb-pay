<?php

declare(strict_types=1);

namespace Africs\GmbPay\Tests\Fixtures\Models;

use Africs\GmbPay\Concerns\Billable;
use Illuminate\Database\Eloquent\Model;

class FakeBillable extends Model
{
    use Billable;

    protected $table = 'fake_billables';

    protected $guarded = [];
}
