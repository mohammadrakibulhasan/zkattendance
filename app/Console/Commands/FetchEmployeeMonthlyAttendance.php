<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\LaravelZkteco;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use MehediJaman\LaravelZkteco\LaravelZkteco as MehediJamanLaravelZkteco;

class FetchEmployeeMonthlyAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:fetch-employee-monthly 
                            {employee_id : Employee ID to fetch attendance for}
                            {month : Month (1-12)}
                            {year : Year (YYYY)}
                            {--ip=192.168.88.201 : ZK device IP address}
                            {--port=4370 : ZK device port}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch monthly attendance data for a single employee and save to JSON log file';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $employeeId = $this->argument('employee_id');
        $month = $this->argument('month');
        $year = $this->argument('year');
        $ip = $this->option('ip');
        $port = $this->option('port');

        // Validate month and year
        if (!is_numeric($month) || $month < 1 || $month > 12) {
            $this->error("Invalid month. Please provide a value between 1 and 12.");
            return 1;
        }

        if (!is_numeric($year) || $year < 2000 || $year > 2100) {
            $this->error("Invalid year. Please provide a valid year (YYYY).");
            return 1;
        }

        try {
            // Create date range for the specified month
            $start = Carbon::create($year, $month, 1)->startOfDay();
            $end = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();
            
            $monthName = $start->format('F');
            $dateRange = "{$monthName} {$year}";
        } catch (\Exception $e) {
            $this->error("Invalid date format.");
            return 1;
        }

        $this->info("Fetching attendance for Employee ID: $employeeId");
        $this->info("Date range: $dateRange");
        $this->info("Connecting to device at $ip:$port ...");

        try {
            $zk = new MehediJamanLaravelZkteco($ip, $port);

            if ($zk->connect()) {
                $this->info("Connected. Fetching attendance logs...");

                $attendance = $zk->getAttendance();

                // Filter attendance for the employee and date range
                $filteredAttendance = array_filter($attendance, function ($log) use ($employeeId, $start, $end) {
                    $logDate = Carbon::parse($log['timestamp']);
                    return $log['id'] == $employeeId && $logDate->between($start, $end);
                });

                // Reset array keys
                $filteredAttendance = array_values($filteredAttendance);

                $count = count($filteredAttendance);
                $this->info("Found $count records for employee $employeeId in $dateRange.");

                if ($count > 0) {
                    // Prepare data for JSON output
                    $employeeData = [
                        'employee_id' => $employeeId,
                        'month' => $month,
                        'year' => $year,
                        'date_range' => $dateRange,
                        'total_records' => $count,
                        'fetch_timestamp' => Carbon::now()->toISOString(),
                        'attendance_logs' => $filteredAttendance
                    ];

                    // Create logs directory if it doesn't exist
                    $logDir = storage_path('logs/attendance');
                    if (!File::exists($logDir)) {
                        File::makeDirectory($logDir, 0755, true);
                    }

                    // Generate filename with employee ID and date
                    $filename = "employee_{$employeeId}_attendance_{$year}_{$month}.json";
                    $filepath = $logDir . '/' . $filename;

                    // Save to JSON file
                    File::put($filepath, json_encode($employeeData, JSON_PRETTY_PRINT));

                    $this->info("Attendance data saved to: $filepath");

                    
                    
                    // Log to Laravel log
                    Log::info("Employee monthly attendance saved", [
                        'employee_id' => $employeeId,
                        'month' => $month,
                        'year' => $year,
                        'records_count' => $count,
                        'file_path' => $filepath
                    ]);

                    // Display summary
                    $this->newLine();
                    $this->info("Summary:");
                    $this->line("  Employee ID: $employeeId");
                    $this->line("  Period: $dateRange");
                    $this->line("  Total Records: $count");
                    $this->line("  File: $filename");

                } else {
                    $this->warn("No attendance records found for employee $employeeId in $dateRange.");
                }

                $zk->disconnect();
                $this->info("Disconnected from device.");
                return 0;

            } else {
                $this->error("Failed to connect to ZK device at $ip:$port");
                return 1;
            }

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            Log::error("Error fetching employee monthly attendance", [
                'employee_id' => $employeeId,
                'month' => $month,
                'year' => $year,
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }
}
