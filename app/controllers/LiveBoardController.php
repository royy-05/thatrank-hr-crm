<?php
// app/controllers/LiveBoardController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\Heartbeat;

class LiveBoardController {
    public static function index(): void {
        $viewer = AuthMiddleware::requireManagerOrAdmin();
        AuthMiddleware::requirePermission('view_live_board');
        $board = Heartbeat::getLiveBoard($viewer);

        jsonResponse([
            'success' => true,
            'board'   => $board,
        ]);
    }
}

