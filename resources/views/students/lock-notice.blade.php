@php $currentLock = \App\Billing\EditLocks::current($student->id); @endphp
@if($currentLock)
    <p class="hint">Student-wide editing lock: {{ $currentLock->name }} ({{ $currentLock->username }}).
        Only the owning editing page may save changes.</p>
@else
    <p class="hint">Open student details to obtain an editing lock before changing these records.</p>
@endif
