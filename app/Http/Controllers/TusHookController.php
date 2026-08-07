<?php

namespace App\Http\Controllers;

use App\Http\Requests\TusHookRequest;
use App\Support\Media\TusHookHandler;
use Illuminate\Http\JsonResponse;

class TusHookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(TusHookRequest $request, TusHookHandler $handler): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->validated();

        return response()->json($handler->handle($payload));
    }
}
