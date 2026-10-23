<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class StatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->works(fn () => DB::select('select 1'));
        $cache = $this->works(function () {
            Cache::put('status:ping', 'pong', 10);

            return Cache::get('status:ping') === 'pong';
        });

        $beat = Cache::get('worker:heartbeat');

        return response()->json([
            'version' => config('app.version'),
            'database' => $database ? 'ok' : 'down',
            'cache' => $cache ? 'ok' : 'down',
            'worker_heartbeat_seconds_ago' => $beat ? now()->timestamp - $beat : null,
        ], $database && $cache ? 200 : 503);
    }

    private function works(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (Throwable) {
            return false;
        }
    }
}
