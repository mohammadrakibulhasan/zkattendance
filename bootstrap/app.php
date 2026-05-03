<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $start = '09:59';
        $end = '14:30';
        $times = [];
        $current = $start;
        while (strtotime($current) < strtotime($end)) {
            $times[] = $current;
            $current = date('H:i', strtotime('+10 minutes', strtotime($current)));
        }
        // $times = ['10:30', '11:00', '11:30', '12:00', '17:00'];
        foreach ($times as $time) {
            $schedule->job(new \App\Jobs\FetchZKAttendanceJob)
                ->dailyAt($time)
                ->timezone('Asia/Dhaka')
                ->withoutOverlapping()
                ->onOneServer();
        }
    })
    ->create();
