<?php

namespace App\Realtime;

use App\Enums\UserAbility;
use App\Models\FloodReport;
use App\Models\User;

/**
 * The channel topics a subscriber may join, derived on the server.
 *
 * The three topic kinds mirror the three branches of FloodReport::visibleTo()
 * exactly, so the channel can never deliver a report the authorized API would
 * refuse (D-65). Clients receive this list; they never name a topic.
 */
final class ChannelScope
{
    public const ALL_TOPIC = 'reports:all';

    private const BARANGAY_PREFIX = 'reports:barangay:';

    private const USER_PREFIX = 'reports:user:';

    /**
     * @return list<string>
     */
    public static function forUser(User $user): array
    {
        $topics = [];

        if ($user->hasAbility(UserAbility::ViewAllBarangays)) {
            $topics[] = self::ALL_TOPIC;
        } elseif ($user->hasAbility(UserAbility::ViewOwnBarangay) && $user->barangay_id !== null) {
            $topics[] = self::barangay((int) $user->barangay_id);
        }

        $topics[] = self::user((int) $user->getKey());

        return array_values(array_unique($topics));
    }

    /**
     * @return list<string>
     */
    public static function forReport(FloodReport $report): array
    {
        return [
            self::ALL_TOPIC,
            self::barangay((int) $report->barangay_id),
            self::user((int) $report->user_id),
        ];
    }

    public static function barangay(int $barangayId): string
    {
        return self::BARANGAY_PREFIX.$barangayId;
    }

    public static function user(int $userId): string
    {
        return self::USER_PREFIX.$userId;
    }
}
