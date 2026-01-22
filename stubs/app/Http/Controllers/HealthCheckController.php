<?php

namespace App\Http\Controllers;

use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthCheckController extends Controller
{
    public function __construct(
        private HealthCheckService $healthCheckService
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $detailed = $request->boolean('detailed');

        return response()->json(
            $this->healthCheckService->performHealthCheck($detailed)
        );
    }
}
