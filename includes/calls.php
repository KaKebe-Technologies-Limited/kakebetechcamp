<?php
/**
 * Phone calls to registered participants (about the camp and their payment). The team lead and operations assign
 * people to callers; each caller works through their list and records what happened on every call.
 */

/** outcome => [label, icon, colour] */
function call_outcomes(): array
{
    return [
        'answered'  => ['Answered', 'fa-phone', 'ok'],
        'no_answer' => ['Not picked up', 'fa-phone-slash', 'warn'],
        'callback'  => ['Call back later', 'fa-clock-rotate-left', 'info'],
        'off'       => ['Phone off / unreachable', 'fa-power-off', 'warn'],
        'wrong'     => ['Wrong number', 'fa-ban', 'bad'],
    ];
}

/** What the person said about paying. */
function call_responses(): array
{
    return [
        'will_pay'      => 'Will pay — promised a date',
        'paying_today'  => 'Paying today',
        'paid'          => 'Says they have already paid',
        'asks_parts'    => 'Asks to pay in parts',
        'needs_support' => 'Needs sponsorship / support',
        'undecided'     => 'Not sure yet',
        'not_coming'    => 'Not coming to camp',
        'other'         => 'Other — see comment',
    ];
}

/** Team lead and operations assign calls and see everyone's; callers see only the people given to them. */
function call_can_manage(array $admin): bool
{
    return in_array($admin['role'], ['admin', 'operations'], true);
}

/** People who can be given calls (anyone with "Phone calls" access). */
function call_callers(): array
{
    $out = [];
    foreach (db()->query("SELECT id, name, email, role, features FROM admins WHERE role <> 'pending' ORDER BY name")->fetchAll() as $a) {
        if (in_array('calls', admin_feature_list($a), true)) {
            $out[(int) $a['id']] = $a;
        }
    }
    return $out;
}

/** Record one call: the history row, and the latest state on the person's call card. */
function call_record(int $registrationId, int $adminId, string $outcome, ?string $response, ?string $promise, ?string $comment): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO call_logs (registration_id, admin_id, outcome, response, promise_date, comment, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$registrationId, $adminId, $outcome, $response, $promise, $comment, now()]);
    $pdo->prepare('INSERT INTO call_list (registration_id, last_outcome, last_response, promise_date, last_comment, attempts, last_called_at, last_called_by)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?)
        ON DUPLICATE KEY UPDATE last_outcome = VALUES(last_outcome), last_response = COALESCE(VALUES(last_response), last_response),
            promise_date = COALESCE(VALUES(promise_date), promise_date), last_comment = COALESCE(VALUES(last_comment), last_comment),
            attempts = attempts + 1, last_called_at = VALUES(last_called_at), last_called_by = VALUES(last_called_by)')
        ->execute([$registrationId, $outcome, $response, $promise, $comment, now(), $adminId]);
}
