<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Checkins;
use App\Models\EventProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends BaseController
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    public function getUserStats($event_profile_id)
    {
        $eventProfile = EventProfile::with(['profile', 'event'])->find($event_profile_id);

        if (!$eventProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Event profile not found.',
            ], 404);
        }

        $routeId   = $eventProfile->route_id;
        $profileId = $eventProfile->profile_id;

        // Check-ins del usuario (incluye checkpoint para terreno/orden/puntos)
        $checkins = Checkins::with(['checkpoint'])
            ->where('route_id', $routeId)
            ->where('profile_id', $profileId)
            ->orderBy('created_at', 'asc')
            ->get();

        // TODOS los checkpoints de la ruta (aunque el usuario no haya hecho check-in)
        $routeCheckpoints = Checkpoint::where('route_id', $routeId)
            ->orderBy('order') // si tu columna se llama distinto, ajusta aquí
            ->get();

        // Mapa de check-ins por checkpoint_id (toma el último si hubiera repetidos)
        $checkinsByCheckpointId = $checkins->keyBy('checkpoint_id');

        // DTO de checkpoints con estado de visita
        $routeCheckpointDtos = $routeCheckpoints->map(function ($cp) use ($checkinsByCheckpointId) {
            $chk = $checkinsByCheckpointId->get($cp->id);

            return [
                'id'            => $cp->id,
                'name'          => $cp->name,
                'points'        => (int) $cp->points,
                'terrain'       => $cp->terrain,
                'order'         => $cp->order,
                'picture'       => $cp->picture,
                'address'       => $cp->address,
                'is_challenge'  => (bool) $cp->is_challenge,
                'visited'       => $chk !== null,
                'visited_at'    => $chk ? $chk->created_at : null,
                'earned_points' => $chk ? (int) $chk->points : 0,
                // si quieres el objeto completo del check-in:
                // 'checkin'    => $chk,
            ];
        });

        // --- Estadísticas (mantenemos tu lógica y añadimos progreso) ---
        $stats = [
            'total_points'          => 0,
            'checkins_by_terrain'   => [],
            'percentage_by_terrain' => [],
            'points_by_terrain'     => [],
            // nuevos
            'visited_count'         => $checkinsByCheckpointId->count(),
            'total_checkpoints'     => $routeCheckpoints->count(),
            'completion_pct'        => 0,
        ];

        foreach ($checkins as $checkin) {
            $terrain = optional($checkin->checkpoint)->terrain ?? 'unknown';
            $points  = (int) $checkin->points;

            // Total points
            $stats['total_points'] += $points;

            // Check-ins by terrain
            $stats['checkins_by_terrain'][$terrain] = ($stats['checkins_by_terrain'][$terrain] ?? 0) + 1;

            // Points by terrain
            $stats['points_by_terrain'][$terrain] = ($stats['points_by_terrain'][$terrain] ?? 0) + $points;
        }

        // Porcentajes por terreno (sobre check-ins del usuario)
        $totalCheckins = $checkins->count();
        foreach ($stats['checkins_by_terrain'] as $terrain => $count) {
            $stats['percentage_by_terrain'][$terrain] = $totalCheckins > 0
                ? round(($count / $totalCheckins) * 100, 2)
                : 0;
        }

        // Progreso de la ruta (visitados / total de checkpoints)
        $stats['completion_pct'] = $stats['total_checkpoints'] > 0
            ? round(($stats['visited_count'] / $stats['total_checkpoints']) * 100, 2)
            : 0;

        // Respuesta
        $response           = new \stdClass();
        $response->eventProfile      = $eventProfile;
        $response->checkins          = $checkins;
        $response->route_checkpoints = $routeCheckpointDtos;
        $response->stats             = $stats;

        return $this->sendResponse($response, 'EVENT_PROFILE_RETRIEVED');
    }
}
