<?php

declare(strict_types=1);

namespace Africs\GmbPay\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'gmb-pay:install
        {--force : Overwrite published files if they already exist}
        {--no-migrate : Skip running migrations}';

    protected $description = 'Publish gmb-pay config, migrations, and views, then run migrations.';

    public function handle(): int
    {
        $this->info('Installing africs/gmb-pay...');

        $this->call('vendor:publish', [
            '--tag' => 'gmb-pay-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'gmb-pay-migrations',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'gmb-pay-views',
            '--force' => (bool) $this->option('force'),
        ]);

        if (! $this->option('no-migrate')) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->info('gmb-pay installed.');
        $this->line('Next steps:');
        $this->line('  1. Set keys in .env: MODEMPAY_*, WAVE_*, WAYCHIT_*');
        $this->line('  2. Choose default driver via GMB_PAY_DEFAULT (modempay|wave|waychit)');
        $this->line('  3. Configure webhook URLs in each provider dashboard:');
        $this->line('       https://your-app.test/' . config('gmb-pay.webhook.route_prefix') . '/{driver}');
        $this->line('  4. (Optional) GMB_PAY_DEMO=true to run with stubbed drivers locally.');
        $this->line('  5. Schedule the cycle command in routes/console.php:');
        $this->line("       Schedule::command('gmb-pay:cycle')->everyFiveMinutes();");

        return self::SUCCESS;
    }
}
