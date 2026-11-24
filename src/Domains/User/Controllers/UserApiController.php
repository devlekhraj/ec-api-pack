<?php

declare(strict_types=1);

namespace EcApi\Domains\User\Controllers;

use EcApi\Domains\Post\Actions\PostListAction;
use EcApi\Domains\Post\Resources\PostResource;
use EcApi\Models\ArticleVersion;
use EcApi\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;

final class UserApiController extends Controller
{
     public function __construct(
        private readonly PostListAction $postListAction,
    ) {}
    public function index(Request $request): JsonResponse
    {
        try {
            $search = trim((string) $request->query('search', ''));
            $perPage = (int) $request->query('per_page', 20);
            $page = (int) $request->query('page', 1);

            $userList = User::query()
                ->withCount(['posts'])
                ->orderByDesc('created_at')
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($builder) use ($search): void {
                        $builder->where('name', 'like', '%' . $search . '%')
                            ->orWhere('username', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
                })
                ->paginate($perPage > 0 ? $perPage : 20, ['*'], 'page', $page > 0 ? $page : 1);

            $items = $userList->getCollection()->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => $user->role_name,
                    'role_name' => $user->role_name,
                    'roles_array' => $user->roles_array,
                    'posts_count' => $user->posts_count,
                    'created_at' => $user->created_at,
                ];
            })->values();

            return response()->json([
                'data' => $items,
                'meta' => [
                    'total' => $userList->total(),
                    'per_page' => $userList->perPage(),
                    'current_page' => $userList->currentPage(),
                    'last_page' => $userList->lastPage(),
                    'from' => $userList->firstItem(),
                    'to' => $userList->lastItem(),
                ],
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Error fetching users: ', [
                'exception' => $th,
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);
            return response()->json(['error' => $th->getMessage()], 422);
        }
    }

    public function show($id)
    {
        try {
            $user = User::select('id', 'name', 'email', 'username', 'slug', 'role_name')
                ->withCount(['posts'])
                ->findOrFail($id);

            $data = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'posts_count' => $user->posts_count,
                'role' => $user->role_name,
                'role_name' => $user->role_name,
                'roles_array' => $user->roles_array,
            ];

            return response(['data' => $data], 200);
        } catch (ModelNotFoundException $e) {
            return response(['error' => 'User not found'], 404);
        } catch (\Throwable $th) {
            return response(['error' => $th->getMessage()], 422);
        }
    }

    public function authors()
    {
        try {
            $authors = User::query()
                ->select(['id', 'name', 'slug', 'username', 'email', 'role_name'])
                ->whereIn('role_name', [User::ROLE_AUTHOR, User::ROLE_ADMIN])
                ->withCount(['posts'])
                ->orderBy('name')
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'username' => $user->username,
                        'role' => $user->role_name,
                        'role_name' => $user->role_name,
                        'roles_array' => $user->roles_array,
                        'posts_count' => $user->posts_count,
                    ];
                });

            return response(['data' => $authors], 200);
        } catch (\Throwable $th) {
            return response(['error' => $th->getMessage()], 422);
        }
    }

    public function assignableUsers()
    {
        try {
            $users = User::query()
                ->select(['id', 'name', 'email', 'username', 'role_name'])
                ->whereIn('role_name', [User::ROLE_ADMIN, User::ROLE_EDITOR])
                ->orderBy('name')
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'username' => $user->username,
                        'role_name' => $user->role_name,
                    ];
                });

            return response(['data' => $users], 200);
        } catch (\Throwable $th) {
            return response(['error' => $th->getMessage()], 422);
        }
    }

    public function lookups(): JsonResponse
    {
        try {
            $users = User::query()
                ->select(['id', 'name', 'profile_pic'])
                ->withCount([
                    'articleVersions as published_articles_count' => function ($query): void {
                        $query->where('article_versions.status', ArticleVersion::STATUS_PUBLISHED);
                    },
                ])
                // ->whereIn('role_name', [User::ROLE_AUTHOR, User::ROLE_ADMIN])
                ->orderBy('name')
                ->get()
                ->map(function (User $user): array {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'avatar' => $this->resolveAvatarUrl($user->profile_pic),
                        'published_articles_count' => (int) ($user->published_articles_count ?? 0),
                    ];
                })
                ->values();

            return response()->json(['data' => $users], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 422);
        }
    }

    public function posts(Request $request, string $id): JsonResponse
    {

        $user = User::query()->findOrFail($id);
        $perPageParam = (int) $request->query('per_page', 15);
        $search = $request->query('search');
        $categoryId = $request->query('category_id');

        $authorId = $user->id;
        $result = $this->postListAction->execute(is_string($search) ? $search : null, $perPageParam, $categoryId, $authorId);

        return response()->json([
            'data' => PostResource::collection($result['items'] ?? []),
            'meta' => $result['meta'] ?? null,
        ]);
    }

    private function resolveAvatarUrl(?string $avatar): ?string
    {
        $avatar = trim((string) $avatar);

        if ($avatar === '') {
            return null;
        }

        if (Str::startsWith($avatar, ['http://', 'https://', '/'])) {
            return $avatar;
        }

        return Storage::url($avatar);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username,' . $id,
            'email' => 'required|email|max:255|unique:users,email,' . $id,
            'role_name' => ['required', 'string', Rule::in(User::validRoles())],
        ]);

        DB::beginTransaction();
        try {
            $user = User::findOrFail($id);
            $user->name = $request->name;
            $user->username = $request->username;
            $user->email = $request->email;
            $user->role_name = $request->role_name;
            $user->save();

            DB::commit();

            return response([
                'message' => 'User updated successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => $user->role_name,
                    'role_name' => $user->role_name,
                    'roles_array' => $user->roles_array,
                ],
            ], 200);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response(['error' => 'User not found'], 404);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response(['error' => $th->getMessage()], 422);
        }
    }

    private function generateUniqueEmail($username)
    {
        $baseEmail = $username . '@everestchronicle.com';

        do {
            $randomNumber = rand(10, 99);
            $email = $username . $randomNumber . '@everestchronicle.com';
        } while (User::where('email', $email)->exists());

        return $email;
    }

    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'name' => 'required|string',
                'description' => 'nullable|string',
                'username' => 'nullable|string|unique:users,username,' . $request->id,
                'email' => 'nullable|email|unique:users,email,' . $request->id,
                'phone' => 'nullable|string|max:15',
                'role_name' => ['required', 'string', Rule::in(User::validRoles())],
            ]);

            $isNewUser = true;
            $passwordString = Str::random(12);

            if ($request->id) {
                $user = User::findOrFail($request->id);
                $message = 'User updated successfully!';
                $isNewUser = false;
            } else {
                $user = new User();
                $user->password = bcrypt($passwordString);
                $message = 'User created successfully!';
            }

            $user->name = $validated['name'];
            $user->description = $validated['description'] ?? null;
            $user->username = $validated['username'] ?? null;
            $user->email = $validated['email'] ?? $this->generateUniqueEmail((string) $request->username);
            $user->phone = $validated['phone'] ?? null;
            $user->role_name = $validated['role_name'] ?? $user->role_name ?? User::ROLE_DEFAULT;
            $user->save();

            if ($isNewUser) {
                $user['password_string'] = $passwordString;
            }

            DB::commit();

            return response()->json([
                'message' => $message,
                'data' => $user,
            ], 200);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['error' => 'User not found.'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'An error occurred while processing the request.', $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);
            $user->is_deleted = 1;
            $user->deleted_at = now();
            $user->save();

            return response()->json(['message' => 'User deleted successfully', 'data' => $user], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => $th->getMessage(),
                'message' => 'Failed to Delete',
            ], 422);
        }
    }

    public function updatePassword(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $messages = [
            'password.required' => 'A new password is required.',
            'password.string' => 'The new password must be a valid string.',
            'password.min' => 'The new password must be at least 8 characters.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];

        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
            'include_password' => 'nullable|boolean',
        ], $messages);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $plainPassword = $request->password;

        $user->password = Hash::make($plainPassword);
        $user->save();

        $emailSent = false;

        if (!empty($user->email)) {
            $includePassword = $request->boolean('include_password');
            try {
                \Illuminate\Support\Facades\Mail::send(
                    'ec-api::emails.password-changed',
                    [
                        'userName' => $user->name ?? $user->username ?? 'User',
                        'newPassword' => $includePassword ? $plainPassword : null,
                    ],
                    function ($mail) use ($user) {
                        $mail->to($user->email)
                             ->subject('Your password has been changed');
                    }
                );
                $emailSent = true;
            } catch (\Throwable $e) {
                Log::warning('Failed to send password change email', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $message = $emailSent
            ? 'Password updated and notification sent successfully.'
            : 'Password updated successfully.';

        return response()->json(['message' => $message], 200);
    }

    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'name' => 'required|string',
        ]);

        $image = $request->file('image');

        if ($image) {
            $random = date('Y-m-d') . '-user-' . substr(md5(uniqid((string) time(), true)), 0, 4);
            $filename = $random . '.webp';

            $directory = 'public/user';

            if (! Storage::exists($directory)) {
                Storage::makeDirectory($directory, 0777, true);
            }

            $imagePath = storage_path("app/{$directory}/{$filename}");
            $manager = new ImageManager(GdDriver::class);
            $sourcePath = $image->getRealPath() ?: $image->getPathname();
            $encoded = $manager->decodePath($sourcePath)->encodeUsingFormat(Format::WEBP, quality: 100);
            $encoded->save($imagePath);

            $url = Storage::url("user/{$filename}");

            return response()->json([
                'url' => $url,
                'file_name' => $filename,
            ], 200);
        }

        return response()->json(['error' => 'No image uploaded'], 400);
    }
}
