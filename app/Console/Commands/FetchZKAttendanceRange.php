<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MehediJaman\LaravelZkteco\LaravelZkteco;
use Carbon\Carbon;

class FetchZKAttendanceRange extends Command
{
    protected $signature = 'zk:fetch-range {start_date} {end_date}';
    protected $description = 'Fetch attendance logs from ZKTeco K40 device for a specific date range';

    public function handle()
    {
        $ip = '192.168.88.201';  // your device IP
        $port = 4370;            // default port for ZKTeco

        $startDate = $this->argument('start_date');
        $endDate = $this->argument('end_date') ?? $startDate;

        // Validate dates
        try {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->endOfDay();
        } catch (\Exception $e) {
            $this->error("Invalid date format. Please use YYYY-MM-DD.");
            return 1;
        }

        $this->info("Fetching attendance from $startDate to $endDate...");
        $this->info("Connecting to device at $ip:$port ...");

        try {
            $zk = new LaravelZkteco($ip, $port);

            if ($zk->connect()) {
                $this->info("Connected. Fetching attendance logs...");

                $attendance = $zk->getAttendance();

                // Filter attendance for the date range
                $filteredAttendance = array_filter($attendance, function ($log) use ($start, $end) {
                    $logDate = Carbon::parse($log['timestamp']);
                    return $logDate->between($start, $end);
                });

                // Reset array keys
                $filteredAttendance = array_values($filteredAttendance);

                $count = count($filteredAttendance);
                $this->info("Found $count records for the specified range.");
                Log::info("Filtered attendance logs ($startDate to $endDate): " . json_encode($filteredAttendance));

                if ($count > 0) {
                    // Chunk the data to avoid timeouts
                    $chunks = array_chunk($filteredAttendance, 50);
                    $totalChunks = count($chunks);

                    $this->info("Splitting data into $totalChunks chunks...");

                    foreach ($chunks as $index => $chunk) {
                        // Check if chunk contains emp_id 1275
                        // $containsEmpId = false;
                        // foreach ($chunk as $log) {
                        //     if (isset($log['id']) && $log['id'] == 1275) {
                        //         $containsEmpId = true;
                        //         break;
                        //     }
                        // }

                        // if (!$containsEmpId) {
                        //     $this->info("Skipping chunk " . ($index + 1) . " as it does not contain emp_id 1275.");
                        //     continue;
                        // }
                        $currentChunk = $index + 1;
                        $this->info("Pushing chunk $currentChunk of $totalChunks...");

                        // Make an HTTP request to another server
                        try {
                            $httpResponse = Http::post('https://hrms.hellonotionhive.com/api/zkteco/push', [
                                'attendance' => $chunk,
                            ]);

                            if ($httpResponse->successful()) {
                                $responseBody = $httpResponse->getBody();
                                $response = json_decode($responseBody, true);

                                if ((isset($response['status']) && $response['status'] == 'success') ||
                                    (isset($response['message']) && $response['message'] === 'OK')
                                ) {
                                    $this->info("Chunk $currentChunk sent successfully.");
                                } else {
                                    $this->error("Failed to send chunk $currentChunk.");
                                    if (isset($response['message'])) {
                                        $this->error('Server message: ' . $response['message']);
                                    }
                                }
                            } else {
                                $this->error("HTTP request failed for chunk $currentChunk with status: " . $httpResponse->status());
                                $this->error('Response body: ' . $httpResponse->getBody());
                            }
                        } catch (\Exception $e) {
                            $this->error("HTTP request exception for chunk $currentChunk: " . $e->getMessage());
                        }
                    }
                } else {
                    $this->info("No records to push.");
                }

                $zk->disconnect();
            } else {
                throw new \Exception("Failed to connect to the device");
            }
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
        return 0;
    }
}
