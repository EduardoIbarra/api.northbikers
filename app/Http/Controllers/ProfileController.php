<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Checkins;
use App\Models\EventProfile;
use App\Models\Checkpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $routeId   = $eventProfile->route_id;   // routes.id
        $profileId = $eventProfile->profile_id;

        // Check-ins del usuario para esta ruta (con checkpoint cargado para terreno/puntos)
        $checkins = Checkins::with(['checkpoint'])
            ->where('route_id', $routeId)
            ->where('profile_id', $profileId)
            ->orderBy('created_at', 'desc')
            ->get();

        // Para marcar visitados por checkpoint_id tomando el check-in más reciente
        $latestByCheckpoint = $checkins->unique('checkpoint_id')->keyBy('checkpoint_id');

        /**
         * TODOS los checkpoints definidos para la ruta en event_checkpoints:
         *  - event_checkpoints.event_id  => routes.id (== $routeId)
         *  - event_checkpoints.checkpoint_id -> checkpoints.id
         *
         * Si tienes columna de orden en event_checkpoints (p.ej. position), úsala para ordenar.
         * Si NO existe, ordenamos por checkpoints.order (si existe) y como fallback por checkpoints.id.
         */
        $ecQuery = DB::table('event_checkpoints as ec')
            ->join('checkpoints as c', 'c.id', '=', 'ec.checkpoint_id')
            ->where('ec.event_id', $routeId)
            ->select([
                'c.id',
                'c.name',
                'c.points',
                'c.terrain',
                'c.picture',
                'c.address',
                'c.is_challenge',
                // si tienes columnas extras en checkpoints, agrégalas aquí
                DB::raw('COALESCE(c."order", 999999) as c_order') // Postgres; si usas MySQL cambia a backticks
            ]);

        // Orden amigable:
        // 1) Si existe ec.position úsala primero
        $hasPosition = Schema::hasColumn('event_checkpoints', 'position');
        if ($hasPosition) {
            $ecQuery->addSelect(DB::raw('COALESCE(ec.position, 999999) as ec_position'))
                ->orderBy('ec_position')
                ->orderBy('c_order')
                ->orderBy('c.id');
        } else {
            $ecQuery->orderBy('c_order')->orderBy('c.id');
        }

        $routeCheckpoints = collect($ecQuery->get());

        // DTO: todos los checkpoints de la ruta + estado de visita del usuario
        $routeCheckpointDtos = $routeCheckpoints->map(function ($cp) use ($latestByCheckpoint) {
            $chk = $latestByCheckpoint->get($cp->id); // último check-in (si existe)
            return [
                'id'            => (int) $cp->id,
                'name'          => $cp->name,
                'points'        => (int) $cp->points,
                'terrain'       => $cp->terrain,
                'picture'       => $cp->picture,
                'address'       => $cp->address,
                'is_challenge'  => (bool) $cp->is_challenge,
                'visited'       => $chk !== null,
                'visited_at'    => $chk ? $chk->created_at : null,
                'earned_points' => $chk ? (int) $chk->points : 0,
            ];
        });

        // --- Estadísticas (tu lógica) + progreso usando TOTAL REAL de event_checkpoints ---
        $stats = [
            'total_points'          => 0,
            'checkins_by_terrain'   => [],
            'percentage_by_terrain' => [],
            'points_by_terrain'     => [],
            'visited_count'         => $latestByCheckpoint->count(),
            'total_checkpoints'     => $routeCheckpoints->count(),
            'completion_pct'        => 0,
        ];

        foreach ($checkins as $checkin) {
            $terrain = optional($checkin->checkpoint)->terrain ?? 'unknown';
            $points  = (int) $checkin->points;

            $stats['total_points'] += $points;
            $stats['checkins_by_terrain'][$terrain] = ($stats['checkins_by_terrain'][$terrain] ?? 0) + 1;
            $stats['points_by_terrain'][$terrain]   = ($stats['points_by_terrain'][$terrain] ?? 0) + $points;
        }

        $totalCheckins = $checkins->count();
        foreach ($stats['checkins_by_terrain'] as $terrain => $count) {
            $stats['percentage_by_terrain'][$terrain] = $totalCheckins > 0
                ? round(($count / $totalCheckins) * 100, 2)
                : 0;
        }

        $stats['completion_pct'] = $stats['total_checkpoints'] > 0
            ? round(($stats['visited_count'] / $stats['total_checkpoints']) * 100, 2)
            : 0;

        // Respuesta
        $response                   = new \stdClass();
        $response->eventProfile     = $eventProfile;
        $response->checkins         = $checkins;
        $response->route_checkpoints = $routeCheckpointDtos;
        $response->stats            = $stats;

        return $this->sendResponse($response, 'EVENT_PROFILE_RETRIEVED');
    }
}
