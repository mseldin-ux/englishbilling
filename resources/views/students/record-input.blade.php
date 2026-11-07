@if($type === 'type')
    <input name="{{ $name }}" form="{{ $formId ?? $newForm }}" value="{{ $value }}" list="transaction-types" aria-label="{{ $label }}" maxlength="255" @required($creating)>
@else
    <input name="{{ $name }}" form="{{ $formId ?? $newForm }}" value="{{ $value }}" aria-label="{{ $label }}"
        type="{{ $type === 'date' ? 'date' : 'text' }}"
        @if($type === 'money') inputmode="decimal" @endif
        @if($type === 'id') inputmode="numeric" @endif
        @if($creating && in_array($name,['transactiondate','transactionamount','nextdate','amount','invoicereference','loa_start'])) required @endif>
@endif
