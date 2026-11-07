{{-- Table of workflow runs. Expects $runs (described by Workflows::describe) and optional $showWorkflow. --}}
<table class="forms-table">
    <thead><tr><th>Adult Learner</th>@if($showWorkflow ?? false)<th>Workflow</th>@endif<th>Program</th><th>Status</th>
        <th>Current stage</th><th>Waiting on</th><th>Started</th><th>Last change</th><th></th></tr></thead>
    <tbody>
    @foreach($runs as $run)
        <tr>
            <td>{{ $run->student?->displayName() ?? '(no learner)' }} @if($run->student_id)<span class="hint">#{{ $run->student_id }}</span>@endif</td>
            @if($showWorkflow ?? false)<td>{{ $run->title }}</td>@endif
            <td>{{ $run->program?->programdesc }}</td>
            <td @class(['workflow-failed' => $run->status === 'paused'])>{{ $run->statusLabel() }}</td>
            <td>{{ in_array($run->status, ['active', 'paused'], true) ? $run->stageTitle : '—' }}
                @if(isset($run->step_error) && $run->step_status === 'failed')<br><span class="workflow-failed">Failed</span>@endif</td>
            <td>{{ in_array($run->status, ['active', 'paused'], true) ? $run->waitingOn : '' }}</td>
            <td>{{ $run->created_at->format('Y-m-d') }}</td>
            <td>{{ $run->updated_at->format('Y-m-d H:i') }}</td>
            <td><a href="{{ route('workflows.runs.show', $run) }}">Open</a></td>
        </tr>
    @endforeach
    </tbody>
</table>
