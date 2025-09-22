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

        $routeId   = $eventProfile->route_id;
        $profileId = $eventProfile->profile_id;

        // --- your existing check-ins, route_checkpoints & stats code (unchanged) ---
        // $checkins, $routeCheckpointDtos, $stats ...

        // --- Trophies for this route (if you already added earlier, keep it) ---
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
                'tt.code as type_code',
                'tt.name as type_name',
                'tt.description as type_description',
                'tt.icon as type_icon',
                'tt.rarity as type_rarity',
                'tt.xp_reward as type_xp_reward',
            ])->get()->map(function ($r) {
                $r->metadata = is_string($r->metadata) ? json_decode($r->metadata, true) : $r->metadata;
                return $r;
            });

        // --- GLOBAL XP from profiles.xp ---
        $profileXp = (int) DB::table('profiles')->where('id', $profileId)->value('xp');

        $current = DB::table('levels')->where('xp_required', '<=', $profileXp)
            ->orderByDesc('xp_required')->first();
        $next    = DB::table('levels')->where('xp_required', '>', $profileXp)
            ->orderBy('xp_required')->first();

        $currReq  = (int) ($current->xp_required ?? 0);
        $nextReq  = (int) ($next->xp_required   ?? ($currReq + 500));
        $xpInto   = max(0, $profileXp - $currReq);
        $xpToNext = max(1, $nextReq - $currReq);
        $xpGlobal = [
            'total_xp'     => $profileXp,
            'level'        => (int) ($current->level ?? 1),
            'current_xp'   => $xpInto,
            'next_xp'      => $xpToNext,
            'progress_pct' => round(100 * $xpInto / $xpToNext, 2),
            'label'        => $current->title ?? 'Novato',
        ];

        // --- Optional: per-route XP (read-only; NOT stored) ---
        $routeXp = (int) DB::table('check_ins')
            ->where('profile_id', $profileId)
            ->where('route_id', $routeId)
            ->whereRaw('coalesce(is_valid, true)')
            ->sum(DB::raw('coalesce(points,0)::int'))
            + (int) DB::table('trophies as t')
                ->join('trophy_types as tt', 'tt.id', '=', 't.trophy_type_id')
                ->where('t.profile_id', $profileId)
                ->where('t.route_id', $routeId)
                ->sum('tt.xp_reward');

        $response                        = new \stdClass();
        $response->eventProfile          = $eventProfile;
        $response->checkins              = $checkins;
        $response->route_checkpoints     = $routeCheckpointDtos;
        $response->stats                 = $stats;
        $response->trophies              = $trophies;
        $response->xp = [
            'global' => $xpGlobal,
            'route'  => ['total_xp' => $routeXp],
        ];

        return $this->sendResponse($response, 'EVENT_PROFILE_RETRIEVED');
    }
}
