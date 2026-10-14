<?php

use App\Http\Controllers\LibraryController;
use App\Http\Middleware\LibraryAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', [LibraryController::class, 'health']);
    Route::post('/auth/login', [LibraryController::class, 'login']);
    Route::middleware(LibraryAuth::class)->group(function (): void {
        Route::post('/users', [LibraryController::class, 'createUser']);
        Route::get('/categories', [LibraryController::class, 'categories']);
        Route::get('/books', [LibraryController::class, 'books']);
        Route::get('/books/{id}/cover', [LibraryController::class, 'cover']);
        Route::get('/books/{id}', [LibraryController::class, 'book']);
        Route::post('/books', [LibraryController::class, 'createBook']);
        Route::put('/books/{id}', [LibraryController::class, 'updateBook']);
        Route::delete('/books/{id}', [LibraryController::class, 'deleteBook']);
        Route::put('/books/{id}/cover', [LibraryController::class, 'uploadCover']);
        Route::delete('/books/{id}/cover', [LibraryController::class, 'deleteCover']);
        Route::get('/members', [LibraryController::class, 'members']);
        Route::get('/members/{id}', [LibraryController::class, 'member']);
        Route::post('/members', [LibraryController::class, 'createMember']);
        Route::get('/loans/overdue', [LibraryController::class, 'overdue']);
        Route::post('/loans', [LibraryController::class, 'createLoan']);
        Route::get('/loans', [LibraryController::class, 'loans']);
        Route::get('/loans/{id}', [LibraryController::class, 'loan']);
        Route::post('/loans/{loan_id}/items/{item_id}/return', [LibraryController::class, 'returnItem']);
        Route::get('/reports/most-borrowed', [LibraryController::class, 'report']);
    });
});
