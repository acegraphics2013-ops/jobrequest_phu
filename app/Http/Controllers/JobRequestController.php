<?php

namespace App\Http\Controllers;

use App\Models\JobRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class JobRequestController extends Controller
{
    /**
     * Display a listing of job requests.
     */
    public function index(Request $request): JsonResponse
    {
        $query = JobRequest::with('requestorUser');

        if ($request->has('status')) {
            $query->where('approval_status', $request->query('status'));
        }

        if ($request->has('department')) {
            $query->where('department', $request->query('department'));
        }

        $jobRequests = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $jobRequests
        ]);
    }

    /**
     * Store a newly created job request in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_title' => 'required|string|max:255',
            'department' => 'required|string|max:150',
            'purpose_objective' => 'required|string',
            'contact' => 'nullable|string|max:50',
        ]);

        $jobNo = 'REQ-' . date('Ymd') . '-' . Str::upper(Str::random(4));

        $jobRequest = JobRequest::create(array_merge($validated, [
            'job_no' => $jobNo,
            'requestor' => $request->user()?->id ?? 1,
            'requestor_name' => $request->user()?->name ?? 'Sample Requestor',
            'date_request' => now()->toDateString(),
            'approval_status' => 'Pending Review',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Job request created successfully.',
            'data' => $jobRequest
        ], 201);
    }

    /**
     * Display the specified job request.
     */
    public function show(int $id): JsonResponse
    {
        $jobRequest = JobRequest::with('requestorUser')->find($id);

        if (!$jobRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Job request not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $jobRequest
        ]);
    }

    /**
     * Update the specified job request in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $jobRequest = JobRequest::find($id);

        if (!$jobRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Job request not found.'
            ], 404);
        }

        $validated = $request->validate([
            'project_title' => 'sometimes|string|max:255',
            'purpose_objective' => 'sometimes|string',
            'approval_status' => 'sometimes|string',
        ]);

        $jobRequest->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Job request updated successfully.',
            'data' => $jobRequest
        ]);
    }

    /**
     * Remove the specified job request from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $jobRequest = JobRequest::find($id);

        if (!$jobRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Job request not found.'
            ], 404);
        }

        $jobRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Job request deleted successfully.'
        ]);
    }
}
