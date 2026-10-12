<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\LogService;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class UserProfileController extends Controller
{
    public function __construct(
        protected LogService $logService
    ) {}

    /**
     * Upload and update authenticated user's avatar.
     * POST /api/profile/upload-avatar
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $file = $request->file('avatar') ?? $request->file('image') ?? $request->file('file');

        if (! $file) {
            throw ValidationException::withMessages([
                'avatar' => ['Please select an image file to upload as your profile picture.'],
            ]);
        }

        $request->validate([
            'avatar' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif|max:5120',
            'image' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif|max:5120',
            'file' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif|max:5120',
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var UserProfile $profile */
        $profile = $user->profile()->firstOrCreate([]);

        // Clean up previous avatar if it exists
        if ($profile->avatar_path && Storage::disk('public')->exists($profile->avatar_path)) {
            Storage::disk('public')->delete($profile->avatar_path);
        }

        $path = $file->store('avatars', 'public');
        $profile->update([
            'avatar_path' => $path,
        ]);

        $resolvedUrl = MediaUrl::resolve(null, $path);

        $this->logService->record(
            event: 'user.profile.avatar_updated',
            outcome: 'success',
            actor: $user,
            subject: $user,
            metadata: ['path' => $path, 'url' => $resolvedUrl],
            message: "User {$user->username} updated profile picture."
        );

        $freshUser = $user->fresh(['profile', 'roles']);

        return response()->json([
            'success' => true,
            'message' => 'Profile picture updated successfully.',
            'data' => [
                'avatar_path' => $path,
                'avatar_url' => $resolvedUrl,
                'user' => [
                    'id' => $freshUser->id,
                    'name' => $freshUser->name,
                    'username' => $freshUser->username,
                    'avatar_url' => $resolvedUrl,
                    'cover_url' => $freshUser->profile?->cover_image_url,
                    'profile' => [
                        'bio' => $freshUser->profile?->bio,
                        'locality' => $freshUser->profile?->locality ?? 'Jashore',
                        'avatar_url' => $resolvedUrl,
                        'cover_url' => $freshUser->profile?->cover_image_url,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Upload and update authenticated user's cover banner image.
     * POST /api/profile/upload-cover
     */
    public function uploadCover(Request $request): JsonResponse
    {
        $file = $request->file('cover') ?? $request->file('cover_image') ?? $request->file('image') ?? $request->file('file');

        if (! $file) {
            throw ValidationException::withMessages([
                'cover' => ['Please select an image file to upload as your cover banner.'],
            ]);
        }

        $request->validate([
            'cover' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif|max:10240',
            'cover_image' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif|max:10240',
            'image' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif|max:10240',
            'file' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif|max:10240',
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var UserProfile $profile */
        $profile = $user->profile()->firstOrCreate([]);

        // Clean up previous cover image if it exists
        if ($profile->cover_image_path && Storage::disk('public')->exists($profile->cover_image_path)) {
            Storage::disk('public')->delete($profile->cover_image_path);
        }

        $path = $file->store('covers', 'public');
        $profile->update([
            'cover_image_path' => $path,
        ]);

        $resolvedUrl = MediaUrl::resolve(null, $path);

        $this->logService->record(
            event: 'user.profile.cover_updated',
            outcome: 'success',
            actor: $user,
            subject: $user,
            metadata: ['path' => $path, 'url' => $resolvedUrl],
            message: "User {$user->username} updated profile cover banner."
        );

        $freshUser = $user->fresh(['profile', 'roles']);

        return response()->json([
            'success' => true,
            'message' => 'Cover banner updated successfully.',
            'data' => [
                'cover_image_path' => $path,
                'cover_url' => $resolvedUrl,
                'user' => [
                    'id' => $freshUser->id,
                    'name' => $freshUser->name,
                    'username' => $freshUser->username,
                    'avatar_url' => $freshUser->profile?->avatar_url,
                    'cover_url' => $resolvedUrl,
                    'profile' => [
                        'bio' => $freshUser->profile?->bio,
                        'locality' => $freshUser->profile?->locality ?? 'Jashore',
                        'avatar_url' => $freshUser->profile?->avatar_url,
                        'cover_url' => $resolvedUrl,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Remove the authenticated user's avatar.
     * DELETE /api/profile/remove-avatar
     */
    public function removeAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->profile;

        if ($profile && $profile->avatar_path) {
            if (Storage::disk('public')->exists($profile->avatar_path)) {
                Storage::disk('public')->delete($profile->avatar_path);
            }
            $profile->update(['avatar_path' => null]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile picture removed.',
            'data' => [
                'avatar_url' => null,
            ],
        ]);
    }

    /**
     * Remove the authenticated user's cover banner.
     * DELETE /api/profile/remove-cover
     */
    public function removeCover(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->profile;

        if ($profile && $profile->cover_image_path) {
            if (Storage::disk('public')->exists($profile->cover_image_path)) {
                Storage::disk('public')->delete($profile->cover_image_path);
            }
            $profile->update(['cover_image_path' => null]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cover banner removed.',
            'data' => [
                'cover_url' => null,
            ],
        ]);
    }

    /**
     * Update user profile information (name, bio, locality, website, and optional avatar/cover).
     * POST /api/profile/update-profile
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:100',
            'bio' => 'nullable|string|max:255',
            'locality' => 'nullable|string|max:100',
            'website_url' => 'nullable|url|max:255',
            'avatar' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif|max:5120',
            'cover' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif|max:10240',
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var UserProfile $profile */
        $profile = $user->profile()->firstOrCreate([]);

        if (! empty($validated['name']) && $validated['name'] !== $user->name) {
            $user->update(['name' => $validated['name']]);
        }

        $profileUpdates = [];
        if (array_key_exists('bio', $validated)) {
            $profileUpdates['bio'] = $validated['bio'];
        }
        if (array_key_exists('locality', $validated)) {
            $profileUpdates['locality'] = $validated['locality'];
        }
        if (array_key_exists('website_url', $validated)) {
            $profileUpdates['website_url'] = $validated['website_url'];
        }

        // Avatar upload if present
        if ($request->hasFile('avatar')) {
            if ($profile->avatar_path && Storage::disk('public')->exists($profile->avatar_path)) {
                Storage::disk('public')->delete($profile->avatar_path);
            }
            $profileUpdates['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }

        // Cover upload if present
        if ($request->hasFile('cover')) {
            if ($profile->cover_image_path && Storage::disk('public')->exists($profile->cover_image_path)) {
                Storage::disk('public')->delete($profile->cover_image_path);
            }
            $profileUpdates['cover_image_path'] = $request->file('cover')->store('covers', 'public');
        }

        if (! empty($profileUpdates)) {
            $profile->update($profileUpdates);
        }

        $freshUser = $user->fresh(['profile', 'roles']);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => [
                'user' => [
                    'id' => $freshUser->id,
                    'name' => $freshUser->name,
                    'username' => $freshUser->username,
                    'avatar_url' => $freshUser->profile?->avatar_url,
                    'cover_url' => $freshUser->profile?->cover_image_url,
                    'profile' => [
                        'bio' => $freshUser->profile?->bio,
                        'locality' => $freshUser->profile?->locality ?? 'Jashore',
                        'website_url' => $freshUser->profile?->website_url,
                        'avatar_url' => $freshUser->profile?->avatar_url,
                        'cover_url' => $freshUser->profile?->cover_image_url,
                    ],
                ],
            ],
        ]);
    }
}
