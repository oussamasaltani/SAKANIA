<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\ContractService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ContractController extends Controller
{
    public function __construct(private readonly ContractService $contracts)
    {
    }

    #[OA\Get(
        path: '/contracts/{id}/download',
        operationId: 'contractsDownload',
        tags: ['Contracts'],
        summary: 'Download a rental contract PDF',
        description: 'Accessible to the contract owner and tenant only. A missing file is rebuilt from the stored reservation and payment data before being served.',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The contract PDF.',
                content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function download(Request $request, $id)
    {
        $contract = Contract::with(['reservation', 'reservation.property', 'owner', 'tenant'])->find($id);

        if (!$contract) {
            return response()->json(['message' => 'Contract not found.'], 404);
        }

        $userId = $request->user()->id;
        $isOwner = $contract->owner_id === $userId;
        $isTenant = $contract->tenant_id === $userId;

        if (!$isOwner && !$isTenant) {
            return response()->json(['message' => 'Unauthorized to access this contract.'], 403);
        }

        $path = $this->contracts->absolutePath($contract);

        // A missing file is rebuilt from the stored reservation and payment data
        // instead of leaving the contract permanently unavailable.
        if (!$path) {
            try {
                $contract = $this->contracts->generatePdf($contract);
                $path = $this->contracts->absolutePath($contract);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (!$path) {
            return response()->json(['message' => 'Contract file not available.'], 404);
        }

        return response()->download($path, $this->contracts->downloadName($contract), [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
