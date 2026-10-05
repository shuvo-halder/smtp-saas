<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AbuseIncidentResource;
use App\Models\AbuseIncident;
use App\Services\Abuse\AbuseIncidentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminAbuseIncidentApiController extends Controller
{
    public function __construct(
        protected AbuseIncidentService $service
    ) {}

    /**
     * GET /api/admin/smtp/incidents
     * Bounded, paginated list of persistent abuse incidents with filtering.
     */
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->input('per_page', 15), 1), 50);

        $query = AbuseIncident::query()->with([
            'tenant:id,name,email',
            'domain:id,domain_name',
            'mailbox:id,email',
            'resolver:id,name,email',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('incident_type')) {
            $query->where('incident_type', $request->input('incident_type'));
        }

        if ($request->filled('detection_source')) {
            $query->where('detection_source', $request->input('detection_source'));
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }

        if ($request->filled('mailbox_id')) {
            $query->where('mailbox_id', $request->input('mailbox_id'));
        }

        if ($request->filled('domain_id')) {
            $query->where('domain_id', $request->input('domain_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('summary', 'like', "%{$search}%")
                  ->orWhere('tenant_email', 'like', "%{$search}%")
                  ->orWhere('mailbox_email', 'like', "%{$search}%")
                  ->orWhere('domain_name', 'like', "%{$search}%")
                  ->orWhere('incident_type', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            try {
                $from = Carbon::parse($request->input('date_from'))->startOfDay();
                $query->where('created_at', '>=', $from);
            } catch (Throwable) {
                // Ignore invalid date format
            }
        }

        if ($request->filled('date_to')) {
            try {
                $to = Carbon::parse($request->input('date_to'))->endOfDay();
                $query->where('created_at', '<=', $to);
            } catch (Throwable) {
                // Ignore invalid date format
            }
        }

        $paginator = $query->latest('id')->paginate($perPage);

        return AbuseIncidentResource::collection($paginator);
    }

    /**
     * GET /api/admin/smtp/incidents/{incident}
     * Retrieve single incident record with full context and snapshots.
     */
    public function show(AbuseIncident $incident): AbuseIncidentResource
    {
        $incident->loadMissing([
            'tenant:id,name,email',
            'domain:id,domain_name',
            'mailbox:id,email',
            'resolver:id,name,email',
        ]);

        return new AbuseIncidentResource($incident);
    }

    /**
     * POST /api/admin/smtp/incidents/{incident}/resolve
     * Resolve or dismiss an abuse incident with administrative attribution and audit logging.
     */
    public function resolve(Request $request, AbuseIncident $incident): JsonResponse
    {
        $validated = $request->validate([
            'notes'  => 'nullable|string|max:1000',
            'status' => 'nullable|string|in:resolved,dismissed',
        ]);

        $status = $validated['status'] ?? 'resolved';
        $notes = $validated['notes'] ?? null;

        if ($status === 'dismissed') {
            $updated = $this->service->dismiss($incident, $request->user(), $notes);
        } else {
            $updated = $this->service->resolve($incident, $request->user(), $notes);
        }

        $updated->loadMissing([
            'tenant:id,name,email',
            'domain:id,domain_name',
            'mailbox:id,email',
            'resolver:id,name,email',
        ]);

        $resource = new AbuseIncidentResource($updated);

        return response()->json([
            'message'  => $status === 'dismissed' ? 'Abuse incident dismissed successfully.' : 'Abuse incident resolved successfully.',
            'data'     => $resource,
            'incident' => $resource,
        ]);
    }
}
