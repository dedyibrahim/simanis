<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('events:send-reminders')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('events:send-daily-agenda morning')
            ->dailyAt('07:00')
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping();

        $schedule->command('events:send-daily-agenda noon')
            ->dailyAt('12:00')
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping();

        $schedule->command('events:send-daily-agenda evening')
            ->dailyAt('17:00')
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping();

        $schedule->command('backup:database-monthly')->monthlyOn(1, '02:00');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
