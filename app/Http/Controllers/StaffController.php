<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Super-admin management of PLATFORM staff (users with no tenant — they run the
 * SmartPRS platform itself, not a client company).
 */
class StaffController extends Controller
{
    private const ROLES = [
        'super_admin' => 'Super Admin (full access)',
        'platform_support' => 'Platform Support',
    ];

    public function index(Request $request)
    {
        $this->guard($request);

        foreach (array_keys(self::ROLES) as $r) {
            Role::findOrCreate($r, 'web');
        }

        $staff = User::whereNull('tenant_id')->orderBy('name')->get();
        $editing = $request->query('edit') ? User::whereNull('tenant_id')->find($request->query('edit')) : null;

        return view('admin.staff', ['staff' => $staff, 'roles' => self::ROLES, 'editing' => $editing]);
    }

    public function store(Request $request)
    {
        $this->guard($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
        ]);

        $user = User::create([
            'name' => $data['name'], 'email' => $data['email'],
            'password' => Hash::make($data['password']), 'tenant_id' => null, 'status' => 'active',
        ]);
        $user->syncRoles([$data['role']]);

        return redirect()->route('admin.staff')->with('success', "Staff member {$data['name']} added.");
    }

    public function update(Request $request, User $user)
    {
        $this->guard($request);
        abort_unless($user->tenant_id === null, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
        ]);

        $user->name = $data['name'];
        $user->email = $data['email'];
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();
        $user->syncRoles([$data['role']]);

        return redirect()->route('admin.staff')->with('success', 'Staff member updated.');
    }

    public function destroy(Request $request, User $user)
    {
        $this->guard($request);

        if ($user->id === $request->user()->id) {
            return back()->with('success', 'You cannot remove your own account.');
        }
        $user->delete();

        return back()->with('success', 'Staff member removed.');
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Super Admin only.');
    }
}
