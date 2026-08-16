<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    /**
     * Hien thi danh sach toan bo nhan vien trong he thong.
     */
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')->paginate(15);

        return view('users.index', compact('users'));
    }

    /**
     * Doi quyen cua 1 nhan vien (user <-> admin).
     */
    public function updateRole(Request $request, $id)
    {
        $request->validate([
            'role' => 'required|in:admin,user',
        ]);

        $user = User::findOrFail($id);

        // Khong cho tu doi quyen cua chinh minh de tranh tu khoa minh khoi quyen admin
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Không thể tự đổi quyền của chính mình.');
        }

        $user->role = $request->role;
        $user->save();

        return back()->with('success', 'Đã cập nhật quyền cho ' . $user->name);
    }

    /**
     * Xoa 1 tai khoan nhan vien.
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return back()->with('error', 'Không thể tự xóa tài khoản của chính mình.');
        }

        $user->delete();

        return back()->with('success', 'Đã xóa tài khoản ' . $user->name);
    }
}