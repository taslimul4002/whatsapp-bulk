<?php

namespace App\Support;

class Permissions
{
    public const Dashboard = 'dashboard.view';

    public const Workspaces = 'workspaces.view';

    public const All = [self::Dashboard, self::Workspaces];
}
