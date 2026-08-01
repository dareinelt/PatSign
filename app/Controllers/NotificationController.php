<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\NotificationRepository;

final class NotificationController extends BaseController
{
    public function __construct(
        \App\Core\View $view,
        private readonly NotificationRepository $notifications
    ) {
        parent::__construct($view);
    }

    public function index(): Response
    {
        return $this->json([
            'unread' => $this->notifications->unreadCount(),
            'notifications' => $this->notifications->latest(20),
        ]);
    }

    public function markRead(Request $request): Response
    {
        $id = (int) $request->input('id', 0);
        if ($id > 0) {
            $this->notifications->markRead($id);
        } else {
            $this->notifications->markAllRead();
        }

        return $this->json(['unread' => $this->notifications->unreadCount()]);
    }
}
