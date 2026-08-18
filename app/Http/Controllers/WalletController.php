<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\ParentModel;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * إدارة محفظة ولي الأمر - بإذن الله تعالى
 *
 * - ولي الأمر: يستعرض رصيده وسجل حركاته فقط.
 * - الأدمن: يستعرض محافظ جميع أولياء الأمور، ويشحن أو يعدّل الرصيد يدوياً.
 */
class WalletController extends Controller
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * التأكد أن المستخدم الحالي ولي أمر، وإرجاع سجله (مع إنشاء محفظته إن لم توجد
     * لأي سبب - مثل حسابات أُنشئت قبل إضافة هذه الميزة).
     */
    private function currentParentOrFail(): ParentModel|\Illuminate\Http\JsonResponse
    {
        $user = auth()->user();

        if ($user->role !== 'parent') {
            return response()->json(['error' => __('Unauthorized. Only parents can access this.')], 403);
        }

        $parent = $user->parent;

        if (!$parent) {
            return response()->json(['error' => __('Parent profile not found. Please complete your profile first.')], 404);
        }

        if (!$parent->wallet) {
            $parent->wallet()->create(['balance' => 0]);
            $parent->refresh();
        }

        return $parent;
    }

    /**
     * 1️⃣ ولي الأمر يستعرض رصيده وآخر حركاته
     * GET /api/parent/wallet
     */
    public function myWallet()
    {
        $parent = $this->currentParentOrFail();
        if ($parent instanceof \Illuminate\Http\JsonResponse) {
            return $parent;
        }

        $wallet = $parent->wallet;

        return response()->json([
            'data' => [
                'balance' => $wallet->balance,
                'recent_transactions' => $wallet->transactions()->take(10)->get(),
            ],
        ]);
    }

    /**
     * 2️⃣ ولي الأمر يستعرض سجل حركاته بالكامل (Pagination)
     * GET /api/parent/wallet/transactions
     */
    public function transactions()
    {
        $parent = $this->currentParentOrFail();
        if ($parent instanceof \Illuminate\Http\JsonResponse) {
            return $parent;
        }

        $transactions = $parent->wallet->transactions()->paginate(20);

        return response()->json(['data' => $transactions]);
    }

    /**
     * 3️⃣ الأدمن يستعرض محافظ جميع أولياء الأمور
     * GET /api/admin/wallets
     */
    public function adminIndex(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized. Only admins can access this.')], 403);
        }

        $query = \App\Models\Wallet::with('parent.user');

        // بحث اختياري باسم ولي الأمر أو بريده
        if ($search = $request->query('search')) {
            $query->whereHas('parent.user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $wallets = $query->latest()->paginate(20);

        return response()->json(['data' => $wallets]);
    }

    /**
     * 4️⃣ الأدمن يستعرض محفظة أب محدد مع كامل سجل حركاتها
     * GET /api/admin/wallets/{parentId}
     */
    public function adminShow($parentId)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized. Only admins can access this.')], 403);
        }

        $parent = ParentModel::with('user')->findOrFail($parentId);

        if (!$parent->wallet) {
            $parent->wallet()->create(['balance' => 0]);
            $parent->refresh();
        }

        return response()->json([
            'data' => [
                'parent' => $parent,
                'balance' => $parent->wallet->balance,
                'transactions' => $parent->wallet->transactions()->paginate(20),
            ],
        ]);
    }

    /**
     * 5️⃣ الأدمن يشحن رصيداً في محفظة ولي أمر (يدوياً الآن)
     * POST /api/admin/wallets/{parentId}/deposit
     */
    public function deposit(Request $request, $parentId)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized. Only admins can access this.')], 403);
        }

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
        ]);

        $parent = ParentModel::findOrFail($parentId);

        if (!$parent->wallet) {
            $parent->wallet()->create(['balance' => 0]);
            $parent->refresh();
        }

        $transaction = $parent->wallet->deposit(
            amount: (float) $data['amount'],
            description: $data['description'] ?? 'Manual top-up by admin',
            performedBy: auth()->user(),
            method: 'manual',
        );

        $this->notifications->walletTransaction($parent, $transaction);

        return response()->json([
            'message' => __('Wallet topped up successfully'),
            'data' => [
                'balance' => $transaction->balance_after,
                'transaction' => $transaction,
            ],
        ]);
    }

    /**
     * 6️⃣ الأدمن يخصم رصيداً يدوياً من محفظة ولي أمر (تصحيح/تعديل)
     * POST /api/admin/wallets/{parentId}/withdraw
     */
    public function withdraw(Request $request, $parentId)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['error' => __('Unauthorized. Only admins can access this.')], 403);
        }

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
        ]);

        $parent = ParentModel::findOrFail($parentId);

        if (!$parent->wallet) {
            return response()->json(['error' => __('This parent has no wallet yet.')], 404);
        }

        try {
            $transaction = $parent->wallet->withdraw(
                amount: (float) $data['amount'],
                description: $data['description'] ?? 'Manual adjustment by admin',
                performedBy: auth()->user(),
                method: 'manual',
                type: 'withdrawal',
            );
        } catch (InsufficientWalletBalanceException $e) {
            return response()->json([
                'error' => __('Insufficient wallet balance.'),
                'available' => $e->available,
                'required' => $e->required,
            ], 422);
        }

        $this->notifications->walletTransaction($parent, $transaction);

        return response()->json([
            'message' => __('Amount withdrawn successfully'),
            'data' => [
                'balance' => $transaction->balance_after,
                'transaction' => $transaction,
            ],
        ]);
    }
}
