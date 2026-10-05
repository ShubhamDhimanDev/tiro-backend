<?php

namespace App\Http\Controllers\Admin\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Bookings\TechnicianRequest;
use App\Models\Technician;
use App\Models\TechnicianShift;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roster CRUD for {@see Technician}. Login provisioning
 * (`Technician.user_id`) is a separate, explicit step — see
 * {@see TechnicianLoginController} — never implicit in create/update here,
 * per docs/architecture/07-admin-auth-permissions.md §5. No `destroy` route:
 * a technician leaving is `status = inactive`, not deleted — `technician_id`
 * cascade-deletes their shift history at the DB level
 * ({@see TechnicianShift}), which would silently destroy roster
 * history a delete-capable UI could trigger by accident.
 */
class TechnicianController extends Controller
{
    /**
     * Display every technician, with their linked login (if any).
     */
    public function index(): Response
    {
        $technicians = Technician::query()
            ->with('user:id,name,email')
            ->orderBy('name')
            ->get();

        return Inertia::render('bookings/technicians/index', [
            'technicians' => $technicians,
        ]);
    }

    /**
     * Store a newly created technician (roster row only — no login).
     */
    public function store(TechnicianRequest $request): RedirectResponse
    {
        Technician::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Technician created.')]);

        return back();
    }

    /**
     * Update the given technician's roster fields.
     */
    public function update(TechnicianRequest $request, Technician $technician): RedirectResponse
    {
        $technician->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Technician updated.')]);

        return back();
    }
}
