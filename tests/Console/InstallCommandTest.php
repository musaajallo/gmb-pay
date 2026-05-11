<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('prints scheduling guidance for gmb-pay:cycle in the install output', function () {
    Artisan::call('gmb-pay:install', ['--no-migrate' => true]);

    $output = Artisan::output();

    expect($output)->toContain('gmb-pay:cycle')
        ->and($output)->toContain('everyFiveMinutes')
        ->and($output)->toContain('routes/console.php');
});
