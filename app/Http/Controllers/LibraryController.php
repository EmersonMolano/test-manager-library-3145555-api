<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Category;
use App\Models\LibraryLoan;
use App\Models\LibraryUser;
use App\Models\LoanItem;
use App\Models\Member;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LibraryController extends Controller
{
    public function health(): JsonResponse { return response()->json(['status' => 'ok']); }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['username' => ['required', 'string'], 'password' => ['required', 'string']]);
        $username = strtolower(trim($data['username']));
        $user = LibraryUser::where('username', $username)->first();
        if (! $user || ! Hash::check($data['password'], $user->password_hash)) {
            return $this->error('INVALID_CREDENTIALS', 'Las credenciales no son válidas.', 401);
        }
        $now = time();
        $token = JWT::encode(['sub' => $user->id, 'role' => $user->role, 'iat' => $now, 'exp' => $now + 28800], (string) config('app.jwt_secret'), 'HS256');
        return response()->json(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 28800, 'role' => $user->role]);
    }

    public function createUser(Request $request): JsonResponse
    {
        $this->role($request, ['admin']);
        $data = $request->validate(['username' => ['required', 'string', 'max:120'], 'password' => ['required', 'string', 'min:8'], 'role' => ['required', 'string']]);
        if ($data['role'] !== 'librarian') return $this->error('VALIDATION_ERROR', 'Solo se puede crear usuarios con rol librarian.', 422);
        $username = strtolower(trim($data['username']));
        if (LibraryUser::where('username', $username)->exists()) return $this->error('USERNAME_TAKEN', 'El nombre de usuario ya existe.', 409);
        $user = LibraryUser::create(['id' => (string) Str::uuid(), 'username' => $username, 'password_hash' => Hash::make($data['password']), 'role' => 'librarian']);
        return response()->json(['id' => $user->id, 'username' => $user->username, 'role' => $user->role], 201);
    }

    public function categories(Request $request): JsonResponse
    {
        $this->user($request);
        return response()->json(Category::orderBy('name')->get(['id', 'name']));
    }

    public function books(Request $request): JsonResponse
    {
        $this->user($request);
        $size = min(max((int) $request->query('size', 20), 1), 50);
        $query = Book::with('category')->whereNull('deleted_at')->orderBy('title');
        if ($request->filled('q')) $query->where(fn ($q) => $q->where('title', 'ilike', '%'.$request->query('q').'%')->orWhere('author', 'ilike', '%'.$request->query('q').'%'));
        if ($request->filled('category_id')) $query->where('category_id', $request->query('category_id'));
        $page = $query->paginate($size)->appends($request->query());
        return response()->json(['items' => $page->getCollection()->map(fn (Book $book) => $this->bookResponse($book))->values(), 'page' => $page->currentPage(), 'size' => $page->perPage(), 'total' => $page->total()]);
    }

    public function book(Request $request, string $id): JsonResponse
    {
        $this->user($request);
        $book = Book::with('category')->whereNull('deleted_at')->find($id);
        return $book ? response()->json($this->bookResponse($book)) : $this->error('NOT_FOUND', 'El libro no existe.', 404);
    }

    public function createBook(Request $request): JsonResponse
    {
        $this->role($request, ['admin']);
        $data = $request->validate(['title' => ['required', 'string', 'max:250'], 'author' => ['required', 'string', 'max:200'], 'isbn' => ['required', 'digits:13'], 'category_id' => ['required', 'uuid'], 'total_copies' => ['required', 'integer', 'min:1']]);
        if (! Category::find($data['category_id'])) return $this->error('VALIDATION_ERROR', 'La categoría no existe.', 422);
        if (Book::where('isbn', $data['isbn'])->whereNull('deleted_at')->exists()) return $this->error('ISBN_TAKEN', 'El ISBN ya existe.', 409);
        $book = Book::create(array_merge($data, ['id' => (string) Str::uuid(), 'title' => trim($data['title']), 'available_copies' => $data['total_copies']]));
        return response()->json($this->bookResponse($book->load('category')), 201);
    }

    public function updateBook(Request $request, string $id): JsonResponse
    {
        $this->role($request, ['admin']);
        $book = Book::whereNull('deleted_at')->find($id);
        if (! $book) return $this->error('NOT_FOUND', 'El libro no existe.', 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:250'], 'author' => ['required', 'string', 'max:200'], 'isbn' => ['required', 'digits:13'], 'category_id' => ['required', 'uuid'], 'total_copies' => ['required', 'integer', 'min:1']]);
        if (! Category::find($data['category_id'])) return $this->error('VALIDATION_ERROR', 'La categoría no existe.', 422);
        if (Book::where('isbn', $data['isbn'])->where('id', '!=', $id)->whereNull('deleted_at')->exists()) return $this->error('ISBN_TAKEN', 'El ISBN ya existe.', 409);
        $available = $book->available_copies + ($data['total_copies'] - $book->total_copies);
        if ($available < 0) return $this->error('VALIDATION_ERROR', 'El total no puede ser menor que los ejemplares prestados.', 422);
        $book->update(array_merge($data, ['title' => trim($data['title']), 'available_copies' => $available]));
        return response()->json($this->bookResponse($book->fresh('category')));
    }

    public function deleteBook(Request $request, string $id): JsonResponse
    {
        $this->role($request, ['admin']);
        $book = Book::whereNull('deleted_at')->find($id);
        if (! $book) return $this->error('NOT_FOUND', 'El libro no existe.', 404);
        if (LoanItem::where('book_id', $id)->whereNull('returned_at')->exists()) return $this->error('BOOK_HAS_OPEN_LOANS', 'El libro tiene préstamos abiertos.', 409);
        $book->update(['deleted_at' => Carbon::now('UTC')]);
        return response()->json(null, 204);
    }

    public function uploadCover(Request $request, string $id): JsonResponse
    {
        $this->role($request, ['admin']);
        $book = Book::whereNull('deleted_at')->find($id);
        if (! $book) return $this->error('NOT_FOUND', 'El libro no existe.', 404);
        $request->validate(['file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:2048']]);
        $key = $request->file('file')->storeAs('covers', $id.'.'.$request->file('file')->extension(), 'local');
        if ($book->cover_key) Storage::disk('local')->delete($book->cover_key);
        $book->update(['cover_key' => $key]);
        return response()->json($this->bookResponse($book->fresh('category')));
    }

    public function deleteCover(Request $request, string $id): JsonResponse
    {
        $this->role($request, ['admin']);
        $book = Book::whereNull('deleted_at')->find($id);
        if (! $book) return $this->error('NOT_FOUND', 'El libro no existe.', 404);
        $key = $book->cover_key;
        $book->update(['cover_key' => null]);
        if ($key) Storage::disk('local')->delete($key);
        return response()->json(null, 204);
    }

    public function cover(Request $request, string $id)
    {
        $this->user($request);
        $book = Book::whereNull('deleted_at')->find($id);
        if (! $book || ! $book->cover_key || ! Storage::disk('local')->exists($book->cover_key)) return $this->error('NOT_FOUND', 'La portada no existe.', 404);
        return response()->file(Storage::disk('local')->path($book->cover_key));
    }

    public function members(Request $request): JsonResponse
    {
        $this->role($request, ['admin', 'librarian']);
        $size = min(max((int) $request->query('size', 20), 1), 50);
        $query = Member::query()->orderBy('full_name');
        if ($request->filled('q')) $query->where(fn ($q) => $q->where('full_name', 'ilike', '%'.$request->query('q').'%')->orWhere('document_number', 'ilike', '%'.$request->query('q').'%'));
        $page = $query->paginate($size);
        return response()->json(['items' => $page->getCollection()->map(fn (Member $member) => $this->memberResponse($member))->values(), 'page' => $page->currentPage(), 'size' => $page->perPage(), 'total' => $page->total()]);
    }

    public function member(Request $request, string $id): JsonResponse
    {
        $this->role($request, ['admin', 'librarian']);
        $member = Member::find($id);
        if (! $member) return $this->error('NOT_FOUND', 'El miembro no existe.', 404);
        return response()->json(array_merge($this->memberResponse($member), ['active_loans' => LoanItem::whereHas('loan', fn ($q) => $q->where('member_id', $id))->whereNull('returned_at')->count()]));
    }

    public function createMember(Request $request): JsonResponse
    {
        $this->role($request, ['admin', 'librarian']);
        $data = $request->validate(['full_name' => ['required', 'string', 'max:200'], 'document_number' => ['required', 'string', 'max:30']]);
        if (Member::where('document_number', $data['document_number'])->exists()) return $this->error('DOCUMENT_TAKEN', 'El documento ya existe.', 409);
        $member = Member::create(['id' => (string) Str::uuid(), 'full_name' => trim($data['full_name']), 'document_number' => trim($data['document_number'])]);
        return response()->json($this->memberResponse($member), 201);
    }

    public function createLoan(Request $request): JsonResponse
    {
        $this->role($request, ['admin', 'librarian']);
        $data = $request->validate(['member_id' => ['required', 'uuid'], 'items' => ['required', 'array', 'min:1', 'max:3'], 'items.*.book_id' => ['required', 'uuid']]);
        if (! Member::find($data['member_id'])) return $this->error('NOT_FOUND', 'El miembro no existe.', 404);
        $bookIds = collect($data['items'])->pluck('book_id');
        if ($bookIds->unique()->count() !== $bookIds->count()) return $this->error('DUPLICATE_BOOK_IN_LOAN', 'No se puede repetir un libro en el préstamo.', 422);
        $now = Carbon::now('UTC');
        $active = LoanItem::whereHas('loan', fn ($q) => $q->where('member_id', $data['member_id']))->whereNull('returned_at')->get();
        if ($active->count() >= 3 || $active->count() + $bookIds->count() > 3) return $this->error('MEMBER_LIMIT_EXCEEDED', 'El miembro ya alcanzó el límite de préstamos.', 422, [['field' => 'member_id', 'issue' => 'active_loans='.$active->count()]]);
        if ($active->where('due_at', '<', $now)->isNotEmpty()) return $this->error('MEMBER_HAS_OVERDUE', 'El miembro tiene libros vencidos.', 409);

        try {
            $loan = DB::transaction(function () use ($bookIds, $data, $now, $request): LibraryLoan {
                $user = $this->user($request);
                $books = Book::with('category')->whereIn('id', $bookIds)->whereNull('deleted_at')->lockForUpdate()->get()->keyBy('id');
                if ($books->count() !== $bookIds->count()) throw new \DomainException('not_found');
                foreach ($books as $book) if ($book->available_copies < 1) throw new \DomainException('unavailable:'.$book->title);
                $loan = LibraryLoan::create(['id' => (string) Str::uuid(), 'member_id' => $data['member_id'], 'loaned_by_user_id' => $user->id, 'loaned_at' => $now]);
                foreach ($bookIds as $bookId) {
                    $book = $books[$bookId];
                    $book->decrement('available_copies');
                    LoanItem::create(['id' => (string) Str::uuid(), 'loan_id' => $loan->id, 'book_id' => $book->id, 'book_title' => $book->title, 'isbn' => $book->isbn, 'category_name' => $book->category->name, 'due_at' => $now->copy()->addDays(14)]);
                }
                return $loan;
            });
        } catch (\DomainException $exception) {
            if (str_starts_with($exception->getMessage(), 'unavailable:')) return $this->error('BOOK_NOT_AVAILABLE', 'No quedan ejemplares disponibles.', 409);
            return $this->error('NOT_FOUND', 'El libro no existe.', 404);
        } catch (QueryException) {
            return $this->error('BOOK_NOT_AVAILABLE', 'No quedan ejemplares disponibles.', 409);
        }
        return response()->json($this->loanResponse($loan->load('items')), 201);
    }

    public function loans(Request $request): JsonResponse
    {
        $this->role($request, ['admin', 'librarian']);
        $size = min(max((int) $request->query('size', 20), 1), 50);
        $loans = LibraryLoan::with('items')->when($request->filled('member_id'), fn ($q) => $q->where('member_id', $request->query('member_id')))->when($request->filled('from'), fn ($q) => $q->where('loaned_at', '>=', Carbon::parse($request->query('from'))->startOfDay()))->when($request->filled('to'), fn ($q) => $q->where('loaned_at', '<=', Carbon::parse($request->query('to'))->endOfDay()))->orderByDesc('loaned_at')->get()->filter(fn (LibraryLoan $loan) => ! $request->filled('status') || $this->loanStatus($loan) === $request->query('status'));
        $page = $loans->forPage(max((int) $request->query('page', 1), 1), $size)->values();
        return response()->json(['items' => $page->map(fn (LibraryLoan $loan) => $this->loanResponse($loan)), 'page' => (int) $request->query('page', 1), 'size' => $size, 'total' => $loans->count()]);
    }

    public function loan(Request $request, string $id): JsonResponse
    {
        $this->role($request, ['admin', 'librarian']);
        $loan = LibraryLoan::with('items')->find($id);
        return $loan ? response()->json($this->loanResponse($loan)) : $this->error('NOT_FOUND', 'El préstamo no existe.', 404);
    }

    public function returnItem(Request $request, string $loanId, string $itemId): JsonResponse
    {
        $this->role($request, ['admin', 'librarian']);
        try {
            $item = DB::transaction(function () use ($loanId, $itemId): LoanItem {
                $item = LoanItem::where('id', $itemId)->where('loan_id', $loanId)->lockForUpdate()->first();
                if (! $item) throw new \DomainException('not_found');
                if ($item->returned_at) throw new \DomainException('returned');
                $book = Book::lockForUpdate()->find($item->book_id);
                $item->update(['returned_at' => Carbon::now('UTC')]);
                if ($book) $book->update(['available_copies' => min($book->total_copies, $book->available_copies + 1)]);
                return $item->fresh();
            });
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage() === 'returned' ? 'ITEM_ALREADY_RETURNED' : 'NOT_FOUND', $exception->getMessage() === 'returned' ? 'El ejemplar ya fue devuelto.' : 'La línea no existe.', $exception->getMessage() === 'returned' ? 409 : 404);
        }
        return response()->json($this->itemResponse($item));
    }

    public function overdue(Request $request): JsonResponse
    {
        $this->role($request, ['admin', 'librarian']);
        $now = Carbon::now('UTC');
        $items = LoanItem::whereNull('returned_at')->where('due_at', '<', $now)->with(['loan.member'])->get()->sortByDesc(fn (LoanItem $item) => $item->due_at->diffInDays($now))->values()->map(fn (LoanItem $item) => ['loan_id' => $item->loan_id, 'item_id' => $item->id, 'book_title' => $item->book_title, 'member_name' => $item->loan->member->full_name, 'due_at' => $item->due_at->utc()->toISOString(), 'days_late' => $item->due_at->diffInDays($now)]);
        return response()->json(['items' => $items]);
    }

    public function report(Request $request): JsonResponse
    {
        $this->role($request, ['admin']);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);
        if ($data['to'] < $data['from']) return $this->error('INVALID_DATE_RANGE', 'La fecha final no puede ser anterior a la inicial.', 422);
        $rows = LoanItem::whereHas('loan', fn ($q) => $q->whereBetween('loaned_at', [Carbon::parse($data['from'])->startOfDay(), Carbon::parse($data['to'])->endOfDay()]))->get()->groupBy(fn (LoanItem $item) => $item->book_id.'|'.$item->book_title.'|'.$item->category_name)->map(function ($group) {
            $first = $group->first();
            return ['book_id' => $first->book_id, 'book_title' => $first->book_title, 'category_name' => $first->category_name, 'times_borrowed' => $group->count()];
        })->sortByDesc('times_borrowed')->sortBy('book_title')->values();
        return response()->json(['from' => $data['from'], 'to' => $data['to'], 'rows' => $rows]);
    }

    private function user(Request $request): LibraryUser
    {
        $user = $request->attributes->get('library_user');
        abort_unless($user instanceof LibraryUser, 401);
        return $user;
    }

    private function role(Request $request, array $roles): LibraryUser
    {
        $user = $this->user($request);
        abort_unless(in_array($user->role, $roles, true), 403);
        return $user;
    }

    private function error(string $code, string $message, int $status, array $details = []): JsonResponse { return response()->json(compact('code', 'message', 'details'), $status); }
    private function bookResponse(Book $book): array { return ['id' => $book->id, 'title' => $book->title, 'author' => $book->author, 'isbn' => $book->isbn, 'category' => ['id' => $book->category->id, 'name' => $book->category->name], 'total_copies' => $book->total_copies, 'available_copies' => $book->available_copies, 'cover_key' => $book->cover_key]; }
    private function memberResponse(Member $member): array { return ['id' => $member->id, 'full_name' => $member->full_name, 'document_number' => $member->document_number]; }
    private function itemResponse(LoanItem $item): array { return ['id' => $item->id, 'book_id' => $item->book_id, 'book_title' => $item->book_title, 'isbn' => $item->isbn, 'category_name' => $item->category_name, 'due_at' => $item->due_at->utc()->toISOString(), 'returned_at' => $item->returned_at?->utc()->toISOString()]; }
    private function loanResponse(LibraryLoan $loan): array { return ['id' => $loan->id, 'member_id' => $loan->member_id, 'loaned_at' => $loan->loaned_at->utc()->toISOString(), 'status' => $this->loanStatus($loan), 'items' => $loan->items->map(fn (LoanItem $item) => $this->itemResponse($item))->values()]; }
    private function loanStatus(LibraryLoan $loan): string { if ($loan->items->every(fn (LoanItem $item) => $item->returned_at !== null)) return 'closed'; if ($loan->items->contains(fn (LoanItem $item) => $item->returned_at === null && $item->due_at->isPast())) return 'overdue'; return 'open'; }
}
