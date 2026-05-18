<?php

declare(strict_types=1);

use BootDesk\ChatSDK\Core\Support\AdapterRegistry;
use BootDesk\ChatSDK\GitHub\GitHubAdapter;

AdapterRegistry::register('github', GitHubAdapter::class);
