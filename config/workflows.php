<?php

use App\Workflows\CourseCompletionWorkflow;
use App\Workflows\EmploymentFollowUpWorkflow;

return [
    /*
     * Base URL placed in emails sent to Adult Learners. Learners follow these links from
     * outside the staff network, so this must be publicly reachable and served by this app.
     */
    'portal_url' => rtrim((string) (env('PORTAL_URL') ?: env('APP_URL', 'http://localhost')), '/'),

    /* Base URL for links in emails sent to staff (for example, the director reminder). */
    'staff_url' => rtrim((string) env('APP_URL', 'http://localhost'), '/'),

    /* Days a Course Completion link stays valid after it is created or resent. */
    'invitation_days' => (int) env('COURSE_COMPLETION_LINK_DAYS', 180),

    /*
     * Temporary failures (for example the mail server being down) are retried automatically
     * by the scheduler this many times in total. Recipient lists, survey links and the
     * correction waiting period are edited on the admin Workflow Settings page.
     */
    'max_attempts' => 5,

    /*
     * Registered workflows. Each runs once per record when its trigger fires.
     * Add a class implementing App\Workflows\Workflow here to create a new workflow.
     */
    'workflows' => [
        CourseCompletionWorkflow::class,
        EmploymentFollowUpWorkflow::class,
    ],
];
