<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MobilePairingFailedException;
use App\Exceptions\MobilePairingUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeMobilePairingRequest;
use App\Services\MobilePairingService;
use Illuminate\Http\JsonResponse;

class MobilePairingExchangeController extends Controller
{
    public function __construct(private readonly MobilePairingService $pairingService) {}

    public function __invoke(ExchangeMobilePairingRequest $request): JsonResponse
    {
        try {
            $setupCode = $this->pairingService->exchange($request->validated('token'));
        } catch (MobilePairingUnavailableException) {
            return response()->json([
                'message' => 'This pairing handoff is no longer available.',
            ], 410);
        } catch (MobilePairingFailedException) {
            return response()->json([
                'message' => 'The agent could not complete pairing. Generate a new code and try again.',
            ], 503);
        }

        return response()->json(['setupCode' => $setupCode]);
    }
}
