<?php

namespace App\Services\Video;

use App\Mail\UniqueVideoViewAlertMail;
use App\Models\Video;
use App\Models\VideoView;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class VideoViewService
{
    public function recordView(Video $video, Request $request, int $watchSeconds = 0, ?string $viewerDeviceId = null): array
    {
        $viewerIp = $request->ip();
        $viewerAgent = $request->userAgent();
        $deviceId = $this->normalizeDeviceId($viewerDeviceId);

        $fingerprintSource = implode('|', [
            (string) $video->id,
            (string) ($viewerIp ?? 'unknown-ip'),
            (string) ($deviceId ?: ($viewerAgent ?? 'unknown-agent')),
        ]);

        $viewerFingerprint = hash('sha256', $fingerprintSource);

        $view = VideoView::firstOrCreate(
            [
                'video_id' => $video->id,
                'viewer_fingerprint' => $viewerFingerprint,
            ],
            [
                'viewer_ip' => $viewerIp,
                'viewer_agent' => $viewerAgent,
                'viewer_device_id' => $deviceId,
                'watch_seconds' => max(0, $watchSeconds),
            ]
        );

        $isUniqueViewer = $view->wasRecentlyCreated;

        if ($isUniqueViewer) {
            $this->notifyAdminsForUniqueView($video, $view);
        }

        return [
            'view' => $view,
            'is_unique_viewer' => $isUniqueViewer,
        ];
    }

    public function insights(Video $video, int $days = 30): array
    {
        $days = max(1, min($days, 365));
        $fromDate = Carbon::now()->subDays($days - 1)->startOfDay();

        $totalViews = VideoView::query()
            ->where('video_id', $video->id)
            ->count();

        $uniqueViews = VideoView::query()
            ->where('video_id', $video->id)
            ->whereNotNull('viewer_fingerprint')
            ->distinct('viewer_fingerprint')
            ->count('viewer_fingerprint');

        $rows = VideoView::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as views, COUNT(DISTINCT viewer_fingerprint) as unique_views')
            ->where('video_id', $video->id)
            ->where('created_at', '>=', $fromDate)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get();

        return [
            'video_uuid' => $video->uuid,
            'total_views' => $totalViews,
            'unique_views' => $uniqueViews,
            'period_days' => $days,
            'series' => $rows->map(fn (VideoView $row) => [
                'date' => $row->day,
                'views' => (int) $row->views,
                'unique_views' => (int) $row->unique_views,
            ])->values(),
        ];
    }

    private function notifyAdminsForUniqueView(Video $video, VideoView $view): void
    {
        $adminEmails = array_filter(
            (array) config('loom.notifications.admin_emails', []),
            fn ($value) => is_string($value) && trim($value) !== ''
        );

        foreach ($adminEmails as $adminEmail) {
            Mail::to($adminEmail)->send(new UniqueVideoViewAlertMail($video, $view));
        }
    }

    private function normalizeDeviceId(?string $viewerDeviceId): ?string
    {
        if (! is_string($viewerDeviceId)) {
            return null;
        }

        $trimmed = trim($viewerDeviceId);

        return $trimmed === '' ? null : $trimmed;
    }
}
