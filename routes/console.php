<?php

use App\Jobs\AnonymiseOffboardedStaff;
use App\Jobs\ArchiveApplications;
use App\Jobs\ExpireQuotes;
use App\Jobs\NotifyRetentionExpiry;
use App\Jobs\SecureDeleteExpiredBusinessClients;
use App\Jobs\SendFleetRenewalReminders;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ExpireQuotes)->hourly();
Schedule::job(new ArchiveApplications)->daily();
Schedule::job(new NotifyRetentionExpiry)->dailyAt('07:00');
Schedule::job(new SecureDeleteExpiredBusinessClients)->dailyAt('02:00');
Schedule::job(new AnonymiseOffboardedStaff)->dailyAt('03:00');
// Daily guard — the job itself no-ops on days other than the 1st.
Schedule::job(new SendFleetRenewalReminders)->dailyAt('06:00');
