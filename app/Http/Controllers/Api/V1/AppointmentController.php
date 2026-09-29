<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Appointment::with(['lead:id,name,company', 'user:id,name'])
            ->orderBy('scheduled_at');

        if ($request->query('upcoming')) {
            $query->where('scheduled_at', '>=', now());
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'title' => 'required|string|max:255',
            'agenda' => 'nullable|string',
            'scheduled_at' => 'required|date',
            'location' => 'nullable|string|max:200',
        ]);

        $appointment = Appointment::create($data + [
            'user_id' => $request->user()->id,
            'status' => 'scheduled',
        ]);

        // Move the lead forward to the appointment stage
        $appointment->lead->update(['status' => 'appointment']);

        return response()->json($appointment->load('lead:id,name'), 201);
    }

    public function update(Request $request, Appointment $appointment)
    {
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'agenda' => 'nullable|string',
            'scheduled_at' => 'sometimes|date',
            'location' => 'nullable|string|max:200',
            'status' => 'sometimes|in:scheduled,done,cancelled,no_show',
            'outcome' => 'nullable|string',
        ]);

        $appointment->update($data);

        return response()->json($appointment->fresh());
    }
}
