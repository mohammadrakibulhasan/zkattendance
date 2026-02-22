<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Attendance;
use App\Models\EmployeeDetail;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MehediJaman\LaravelZkteco\LaravelZkteco;

class FetchZKAttendance extends Command
{
    protected $signature = 'zk:fetch';
    protected $description = 'Fetch attendance logs from ZKTeco K40 device';

    public function handle()
    {
        $ip = '192.168.88.201';  // your device IP
        $port = 4370;            // default port for ZKTeco

        $this->info("Connecting to device at $ip:$port ...");

        try {
            $zk = new LaravelZkteco($ip, $port);

            if ($zk->connect()) {
                $this->info("Connected. Fetching attendance logs...");

                $attendance = $zk->getAttendance();

                Log::info("Attendance logs: " . json_encode($attendance));

                // Get today's date in Y-m-d format
                $today = now()->format('Y-m-d');

                // Filter attendance for today only
                $todaysAttendance = array_filter($attendance, function ($log) use ($today) {
                    return date('Y-m-d', strtotime($log['timestamp'])) === $today;
                });

                // Reset array keys to maintain JSON array format
                $todaysAttendance = array_values($todaysAttendance);

                Log::info('Today\'s attendance: ' . json_encode($todaysAttendance));


                // Make an HTTP request to another server
                try {
                    $httpResponse = Http::post('https://hrms.hellonotionhive.com/api/zkteco/push', [
                        'attendance' => $todaysAttendance,
                    ]);
                    // $httpResponse = Http::post('http://localhost:8000/api/zkteco/push', [
                    //     'attendance' => $todaysAttendance,
                    // ]);

                    // Check if the HTTP request was successful
                    if ($httpResponse->successful()) {
                        $responseBody = $httpResponse->getBody();

                        $this->info('Raw response body: ' . $responseBody);

                        // Check if response body is not empty
                        if (empty($responseBody)) {
                            $this->error('Empty response body received from server');
                            return 1;
                        }

                        $response = json_decode($responseBody, true);

                        // Check if JSON decoding was successful
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $this->error('Invalid JSON response from server. Error: ' . json_last_error_msg());
                            $this->error('Response body: ' . $responseBody);
                            return 1;
                        }

                        $this->info('Response: ' . json_encode($response));

                        if (isset($response['status']) && $response['status'] == 'success') {
                            $this->info('Attendance logs sent to another server successfully.');
                        } elseif (isset($response['message']) && $response['message'] === 'OK') {
                            $this->info('Attendance logs sent to another server successfully.');
                        } else {
                            $this->error('Failed to send attendance logs to another server.');
                            if (isset($response['message'])) {
                                $this->error('Server message: ' . $response['message']);
                            }
                        }
                    } else {
                        $this->error('HTTP request failed with status: ' . $httpResponse->status());
                        $this->error('Response body: ' . $httpResponse->getBody());
                        return 1;
                    }
                } catch (\Exception $e) {
                    $this->error('HTTP request exception: ' . $e->getMessage());
                    return 1;
                }
                // $users = $zk->getUser();
                // Log::info(json_encode($users));
                // $this->info("User fetched: " . count($users));
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
