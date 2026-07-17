<?php

namespace App\Enums;

/**
 * What initiated a deployment (docs/SPEC.md §5).
 */
enum DeploymentTrigger: string
{
    case Api = 'api';
    case Manual = 'manual';
    case Cron = 'cron';
    case Webhook = 'webhook';
}
