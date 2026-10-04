<?php

use App\Jobs\ArchiveApplications;
use App\Jobs\ExpireQuotes;
use App\Jobs\NotifyRetentionExpiry;
use App\Jobs\SecureDeleteExpiredBusinessClients;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ExpireQuotes)->hourly();
Schedule::job(new ArchiveApplications)->daily();
Schedule::job(new NotifyRetentionExpiry)->dailyAt('07:00');
Schedule::job(new SecureDeleteExpiredBusinessClients)->dailyAt('02:00');
