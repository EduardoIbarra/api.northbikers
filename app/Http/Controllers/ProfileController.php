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
                DB::raw('COALESCE(c."order", 999999) as c_order'),
            ]);

        // Orden: si existe ec.position, úsalo
        if (Schema::hasColumn('event_checkpoints', 'position')) {
            $ecQuery->addSelect(DB::raw('COALESCE(ec.position, 999999) as ec_position'))
                ->orderBy('ec_position')
                ->orderBy('c_order')
                ->orderBy('c.id');
        } else {
            $ecQuery->orderBy('c_order')->orderBy('c.id');
        }

        $routeCheckpoints = collect($ecQuery->get());

        // DTO: todos los checkpoints + estado visitado
        $routeCheckpointDtos = $routeCheckpoints->map(function ($cp) use ($latestByCheckpoint) {
            $chk = $latestByCheckpoint->get($cp->id);
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

        // --- Estadísticas locales (compatibles con tu frontend actual) ---
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

        // --- Trofeos/Awards por ruta y perfil ---
        $awards = collect();
        $awardsSummary = collect();

        // Detecta tabla/columns (permitimos trophy_code o trophy_id)
        $awardsTable = null;
        if (Schema::hasTable('profile_trophy_awards')) {
            $awardsTable = 'profile_trophy_awards';
        } elseif (Schema::hasTable('trophy_awards')) {
            $awardsTable = 'trophy_awards';
        }

        if ($awardsTable) {
            $hasCode = Schema::hasColumn($awardsTable, 'trophy_code');
            $joinOn  = $hasCode ? ['trophies.code', '=', $awardsTable . '.trophy_code']
                : ['trophies.id',   '=', $awardsTable . '.trophy_id'];

            // Lista de awards (múltiples del mismo tipo permitidos)
            $awards = DB::table($awardsTable . ' as pta')
                ->join('trophies as t', $joinOn[0], $joinOn[1], $joinOn[2])
                ->where('pta.profile_id', $profileId)
                ->where('pta.route_id', $routeId)
                ->orderBy('pta.created_at', 'desc')
                ->select([
                    'pta.id as award_id',
                    'pta.route_id',
                    'pta.profile_id',
                    $hasCode ? 'pta.trophy_code as trophy_key' : 'pta.trophy_id as trophy_key',
                    'pta.points_awarded',
                    'pta.created_at',
                    't.code as trophy_code',
                    't.id   as trophy_id',
                    't.name',
                    't.description',
                    't.points as base_points',
                    't.tier',
                    't.icon',
                ])
                ->get();

            // Resumen por tipo de trofeo
            $awardsSummary = DB::table($awardsTable . ' as pta')
                ->join('trophies as t', $joinOn[0], $joinOn[1], $joinOn[2])
                ->where('pta.profile_id', $profileId)
                ->where('pta.route_id', $routeId)
                ->groupBy('t.code', 't.id', 't.name', 't.tier')
                ->select([
                    't.code as trophy_code',
                    't.id   as trophy_id',
                    't.name',
                    't.tier',
                    DB::raw('COUNT(*)::int as count'),
                    DB::raw('COALESCE(SUM(pta.points_awarded),0)::int as total_points'),
                ])
                ->orderBy('t.tier')
                ->orderBy('t.name')
                ->get();
        }

        // --- Rank/Level a partir de profiles.xp y función level_for_xp(xp) ---
        $xp = 0;
        if (isset($eventProfile->profile) && property_exists($eventProfile->profile, 'xp')) {
            $xp = (int) $eventProfile->profile->xp;
        } else {
            // fallback suave: usar total_points sólo para no romper UI
            $xp = (int) $stats['total_points'];
        }

        $rank = [
            'xp'            => $xp,
            'level'         => 1,
            'xp_into_level' => 0,
            'xp_to_next'    => 500,
            'progress_pct'  => 0.0,
            'title'         => 'Novato',
        ];

        try {
            // requiere la función SQL creada: public.level_for_xp(int)
            $row = DB::selectOne('select * from public.level_for_xp(?)', [$xp]);
            if ($row) {
                $rank = [
                    'xp'            => $xp,
                    'level'         => (int) $row->level,
                    'xp_into_level' => (int) $row->xp_into_level,
                    'xp_to_next'    => (int) $row->xp_to_next,
                    'progress_pct'  => (float) $row->progress_pct,
                    'title'         => (string) $row->title,
                ];
            }
        } catch (\Throwable $e) {
            // sin función: calcula un progreso simple para no romper
            $rank['xp_into_level'] = $xp % 500;
            $rank['progress_pct']  = round(($rank['xp_into_level'] / 500) * 100, 2);
        }

        // Respuesta
        $response                        = new \stdClass();
        $response->eventProfile          = $eventProfile;
        $response->checkins              = $checkins;
        $response->route_checkpoints     = $routeCheckpointDtos;
        $response->stats                 = $stats;
        $response->awards                = $awards;          // listado de trofeos otorgados (duplicados permitidos)
        $response->awards_summary        = $awardsSummary;   // conteo por tipo
        $response->rank                  = $rank;            // info de nivel/XP para UI

        return $this->sendResponse($response, 'EVENT_PROFILE_RETRIEVED');
    }
}
