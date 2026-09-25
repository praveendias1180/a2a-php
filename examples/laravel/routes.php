<?php

// routes/api.php (or any routes file)

declare(strict_types=1);

use App\A2A\HelloAgentCard;
use App\A2A\HelloExecutor;
use Illuminate\Support\Facades\Route;

// --8<-- [start:route]
Route::a2a('/a2a', agentCard: HelloAgentCard::class, executor: HelloExecutor::class);
// --8<-- [end:route]
