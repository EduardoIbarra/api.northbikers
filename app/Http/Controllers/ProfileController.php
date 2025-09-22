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

    // User check-ins for this route
    $checkins = Checkins::with(['checkpoint'])
        ->where('route_id', $routeId)
        ->where('profile_id', $profileId)
        ->orderBy('created_at', 'desc')
        ->get();

    // Latest by checkpoint
    $latestByCheckpoint = $checkins->unique('checkpoint_id')->keyBy('checkpoint_id');

    // All checkpoints for the route from event_checkpoints
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

    // --- Stats ---
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

    // --- Trophies (unchanged from your working version) ---
    $trophies = DB::table('trophies as t')
        ->join('trophy_types as tt', 'tt.id', '=', 't.trophy_type_id')
        ->where('t.profile_id', $profileId)
        ->where('t.route_id', $routeId)
        ->orderBy('t.earned_at', 'desc')
        ->select([
            't.id',
            't.earned_at',
            't.source',
            't.metadata',
            'tt.code   as type_code',
            'tt.name   as type_name',
            'tt.description as type_description',
            'tt.icon   as type_icon',
            'tt.rarity as type_rarity',
            'tt.xp_reward as type_xp_reward',
        ])
        ->get()
        ->map(function ($row) {
            $row->metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : $row->metadata;
            return $row;
        });

    $trophiesSummary = [
        'total'      => $trophies->count(),
        'by_code'    => $trophies->groupBy('type_code')->map->count(),
        'xp_earned'  => $trophies->sum(fn($r) => (int) ($r->type_xp_reward ?? 0)),
    ];

    // --- Levels payload for frontend + rider XP ---
    $levels = collect();
    if (Schema::hasTable('levels')) {
        $levels = DB::table('levels')
            ->orderBy('xp_required', 'asc')
            ->select(['level', 'title', 'xp_required'])
            ->get();
    }
    $profileXp = (int) ($eventProfile->profile->xp ?? 0);

    // Response
    $response                        = new \stdClass();
    $response->eventProfile          = $eventProfile;
    $response->checkins              = $checkins;
    $response->route_checkpoints     = $routeCheckpointDtos;
    $response->stats                 = $stats;
    $response->trophies              = $trophies;
    $response->trophies_summary      = $trophiesSummary;
    $response->levels                = $levels;       // << add levels
    $response->profile_xp            = $profileXp;    // << add current XP

    return $this->sendResponse($response, 'EVENT_PROFILE_RETRIEVED');
}

}
