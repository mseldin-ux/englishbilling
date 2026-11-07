<div class="student-columns">
@foreach(array_chunk($groups, 2, true) as $column)
<div>
@foreach($column as $heading => $fields)
    @include('students.fieldset')
@endforeach
</div>
@endforeach
</div>
<details>
    <summary>Additional student records information (Access)</summary>
    @include('students.fieldset', ['heading' => 'Additional student records information',
        'fields' => \App\Billing\StudentFields::ACCESS_FIELDS])
</details>
