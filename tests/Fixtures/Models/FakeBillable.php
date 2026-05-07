<?php

declare(strict_types=1);

namespace Africs\GmbPay\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class FakeBillable extends Model
{
    protected $table = 'fake_billables';

    protected $guarded = [];
}
