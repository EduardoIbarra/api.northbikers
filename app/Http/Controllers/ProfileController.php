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

    public function getUserStats($event_profile_id) {
        $eventProfile = EventProfile::with(['profile', 'event'])->find($event_profile_id);
        if (!$eventProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Event profile not found.',
            ], 404); // 404 Not Found status code
        }
        $checkins = Checkins::with(['checkpoint'])->where('route_id', $eventProfile->route_id)->where('profile_id', $eventProfile->profile_id)->get();
        $response = new \stdClass(); // Or you could use an associative array
        $response->eventProfile = $eventProfile;
        $response->checkins = $checkins;
        // Initialize statistics
        $stats = [
            'total_points' => 0,
            'checkins_by_terrain' => [],
            'percentage_by_terrain' => [],
            'points_by_terrain' => []
        ];

        foreach ($checkins as $checkin) {
            $terrain = $checkin->checkpoint->terrain;
            $points = $checkin->points;

            // Total points
            $stats['total_points'] += $points;

            // Check-ins by terrain
            if (!isset($stats['checkins_by_terrain'][$terrain])) {
                $stats['checkins_by_terrain'][$terrain] = 0;
            }
            $stats['checkins_by_terrain'][$terrain]++;

            // Points by terrain
            if (!isset($stats['points_by_terrain'][$terrain])) {
                $stats['points_by_terrain'][$terrain] = 0;
            }
            $stats['points_by_terrain'][$terrain] += $points;
        }

        // Calculate percentage of check-ins by terrain
        $totalCheckins = count($checkins);
        foreach ($stats['checkins_by_terrain'] as $terrain => $count) {
            $stats['percentage_by_terrain'][$terrain] = $totalCheckins > 0 ? ($count / $totalCheckins) * 100 : 0;
        }

        // Add stats to the response object
        $response = new \stdClass();
        $response->stats = $stats;
        $response->eventProfile = $eventProfile;
        $response->checkins = $checkins;

        return $this->sendResponse($response, 'EVENT_PROFILE_RETRIEVED');
    }
}
