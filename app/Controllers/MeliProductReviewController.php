<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\MeliProductUpdateReviewService;
use App\Services\SafeErrorPresenter;

final class MeliProductReviewController
{
    public function index(): void
    {
        Auth::requireLogin();
        $companyId = max(0, (int) ($_GET['company_id'] ?? 0));
        $reviews = (new MeliProductUpdateReviewService())->listReviews($companyId);
        View::render('products/meli/reviews', compact('reviews'));
    }

    public function show(): void
    {
        Auth::requireLogin();
        $reviewId = max(0, (int) ($_GET['id'] ?? 0));
        $status = trim((string) ($_GET['status'] ?? ''));
        $service = new MeliProductUpdateReviewService();
        $review = $service->review($reviewId);
        $items = $service->items($reviewId, $status);
        $changes = [];
        foreach ($items as $item) {
            $changes[(int) $item['id']] = $service->changes((int) $item['id']);
        }
        View::render('products/meli/review_show', compact('review', 'items', 'changes', 'status'));
    }

    public function approve(): void
    {
        $this->transition('approveItem', 'Cambio aprobado para aplicación local.');
    }

    public function reject(): void
    {
        $this->transition('rejectItem', 'Cambio rechazado; el snapshot local se conserva.');
    }

    public function apply(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $reviewId = max(0, (int) ($_POST['review_id'] ?? 0));
        try {
            if (Auth::isTemporary()) {
                throw new \RuntimeException('Los usuarios temporales no pueden aplicar revisiones.');
            }
            $applied = (new MeliProductUpdateReviewService())->applyApproved($reviewId);
            Session::flash('success', $applied . ' publicaciones actualizadas localmente.');
        } catch (\Throwable $error) {
            Session::flash('error', SafeErrorPresenter::message(
                $error,
                'No fue posible aplicar las publicaciones aprobadas.'
            ));
        }
        $this->redirect('/products/meli/reviews/show?id=' . $reviewId);
    }

    private function transition(string $method, string $success): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $reviewId = max(0, (int) ($_POST['review_id'] ?? 0));
        $itemId = max(0, (int) ($_POST['item_id'] ?? 0));
        try {
            if (Auth::isTemporary()) {
                throw new \RuntimeException('Los usuarios temporales no pueden decidir revisiones.');
            }
            (new MeliProductUpdateReviewService())->{$method}($itemId);
            Session::flash('success', $success);
        } catch (\Throwable $error) {
            Session::flash('error', SafeErrorPresenter::message(
                $error,
                'No fue posible actualizar la revisión.'
            ));
        }
        $this->redirect('/products/meli/reviews/show?id=' . $reviewId);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
