@php $yes = fn ($value) => $value ? 'Yes' : 'No'; @endphp
<tr><th>Adult Learner</th><td>{{ $completion->student?->displayName() }} (User ID {{ $completion->student_id }})</td></tr>
<tr><th>Program</th><td>{{ $completion->invitation?->program?->programdesc }}</td></tr>
<tr><th>Submitted</th><td>{{ $completion->submitted_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i T') }}</td></tr>
<tr><th>{{ $labels['replaces_previous'] }}</th><td>{{ $yes($completion->replaces_previous) }}</td></tr>
<tr><th>{{ $labels['cohort'] }}</th><td>{{ $completion->cohort }}</td></tr>
<tr><th>{{ $labels['instructor'] }}</th><td>{{ $completion->instructor }}</td></tr>
<tr><th>{{ $labels['certificate_name'] }}</th><td>{{ $completion->certificate_name }}</td></tr>
<tr><th>{{ $labels['mailing_address'] }}</th><td style="white-space: pre-line">{{ $completion->mailing_address }}</td></tr>
<tr><th>Is this address within the United States?</th><td>{{ $yes($completion->address_in_us) }}@unless($completion->address_in_us) — international mailing payment required (Finance)@endunless</td></tr>
<tr><th>Did you complete the Course Completion Survey?</th><td>{{ $yes($completion->survey_completed) }}</td></tr>
<tr><th>{{ $labels['expected_graduation_date'] }}</th><td>{{ $completion->expected_graduation_date }}</td></tr>
<tr><th>{{ $labels['employment_status'] }}</th><td>{{ $completion->employmentLabel() }}</td></tr>
<tr><th>{{ $labels['additional_info'] }}</th><td style="white-space: pre-line">{{ $completion->additional_info }}</td></tr>
