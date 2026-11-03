<?php
declare(strict_types=1);

namespace Bins\Reminders;

use Bins\Collections\Bins;
use Bins\Mail\EmailLayout;
use Bins\Mail\Message;
use Bins\Park\Streets;
use Bins\Subscribers\SignupService;

/** Builds one reminder email: which bins, when, and what comes next. */
final class ReminderBuilder
{
    public function __construct(private string $siteUrl, private string $organisation)
    {
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    /**
     * @param list<string>                $bins      bins collected on $day
     * @param array<string, list<string>> $next      later collection days => bins
     * @param array<string, string>       $usualDay  bin => usual weekday, to flag a changed day
     */
    public function message(string $to, string $street, string $timing, string $day, array $bins, array $next,
                            array $usualDay, string $manageUrl, string $unsubscribeUrl, bool $test = false): Message
    {
        $bins = Bins::sort($bins);
        $weekday = self::weekday($day);
        $date = self::longDate($day);
        $when = $timing === 'morning' ? 'today' : 'tomorrow';
        $what = Bins::labels($bins);

        $subject = ($test ? '[TEST] ' : '') . 'Bins ' . $when . ': ' . $what;
        $changed = [];
        foreach ($bins as $bin) {
            if (isset($usualDay[$bin]) && $usualDay[$bin] !== $weekday) {
                $changed[] = Bins::label($bin) . ' is collected on ' . $weekday . ' this week, not the usual ' . $usualDay[$bin];
            }
        }
        if ($changed) {
            $subject .= ' (' . $weekday . ')';
        }

        $put = $timing === 'morning'
            ? "Put your bin" . (count($bins) > 1 ? 's' : '') . " out by 7am today, {$date}:"
            : "Put your bin" . (count($bins) > 1 ? 's' : '') . " out tonight, or by 7am tomorrow, {$date}:";

        $nextLine = '';
        foreach ($next as $nextDay => $nextBins) {
            $nextLine = 'Next: ' . Bins::labels($nextBins) . ' on ' . self::longDate($nextDay) . '.';
            break;
        }
        $streetName = Streets::name($street);
        $why = "You get this because you signed up for bin reminders for {$streetName}, " . SignupService::timingPhrase($timing) . '.';
        $source = 'Collection dates come from Nottingham City Council’s calendar, including any bank holiday changes.';

        // Plain text
        $text = ($test ? "[TEST: sent only to the site's own mailbox]\n\n" : '')
            . $put . "\n"
            . implode('', array_map(static fn($b) => '  - ' . ucfirst(Bins::describe($b)) . "\n", $bins))
            . ($changed ? "\nNote: " . implode('; ', $changed) . ".\n" : '')
            . ($nextLine ? "\n{$nextLine}\n" : '')
            . "\n{$why}\nChange your street or reminder time: {$manageUrl}\nUnsubscribe: {$unsubscribeUrl}\n\n"
            . "{$source}\nBINS – Bin day reminders for The Park, run by {$this->organisation}\n{$this->siteUrl}\n";

        // HTML
        $items = '';
        foreach ($bins as $b) {
            $items .= '<tr><td style="padding:6px 0;vertical-align:middle;width:28px">'
                . '<span style="display:inline-block;width:18px;height:18px;border-radius:50%;background:' . Bins::colour($b) . '"></span></td>'
                . '<td style="padding:6px 0;font-size:19px;font-weight:700">' . EmailLayout::e(ucfirst(Bins::describe($b))) . '</td></tr>';
        }
        $content = ($test ? '<p style="margin:0 0 16px;padding:8px 12px;background:#fff4d6;border-radius:6px">Test: sent only to the site’s own mailbox.</p>' : '')
            . '<p style="margin:0 0 8px">' . EmailLayout::e($put) . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px">' . $items . '</table>'
            . ($changed ? '<p style="margin:0 0 16px;padding:8px 12px;background:#fff4d6;border-radius:6px"><strong>Changed day:</strong> '
                . EmailLayout::e(ucfirst(implode('; ', $changed))) . '.</p>' : '')
            . ($nextLine ? '<p style="margin:0 0 16px;color:#56635e">' . EmailLayout::e($nextLine) . '</p>' : '');
        $footer = EmailLayout::e($why) . '<br>'
            . EmailLayout::link($manageUrl, 'Change your street or reminder time') . ' · '
            . EmailLayout::link($unsubscribeUrl, 'Unsubscribe') . '<br><br>'
            . EmailLayout::e($source) . ' Run by ' . EmailLayout::e($this->organisation) . '.';

        $preheader = ucfirst($what) . ' ' . ($timing === 'morning' ? 'today' : 'tomorrow') . ', ' . $date;
        return new Message($to, $subject, $text, EmailLayout::wrap($content, $footer, $preheader), null, [
            'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public static function weekday(string $day): string
    {
        return date('l', strtotime($day . ' 12:00:00'));
    }

    /** "Wednesday 4 November" */
    public static function longDate(string $day): string
    {
        return date('l j F', strtotime($day . ' 12:00:00'));
    }
}
