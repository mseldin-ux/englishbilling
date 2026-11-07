<form action="{{ route('reports.invoices') }}" method="get">
    <div class="field-grid">
        <label>Enrolled On or After <input type="date" name="studentenrolleddate" value="{{ $filters['studentenrolleddate'] ?? '' }}"></label>
        <label>Invoice Sent On or After <input type="date" name="invoicesentafterdate" value="{{ $filters['invoicesentafterdate'] ?? '' }}"></label>
        <label>Invoice Sent Through (midnight, inclusive) <input type="date" name="invoicesentbeforedate" value="{{ $filters['invoicesentbeforedate'] ?? '' }}"></label>
        <label class="inline"><input type="checkbox" name="hidegraduated" value="1" @checked(!empty($filters['hidegraduated']))> Hide Graduated</label>
        <label class="inline"><input type="checkbox" name="hideinactive" value="1" @checked(!empty($filters['hideinactive']))> Hide Inactive</label>
        <label class="inline"><input type="checkbox" name="showreceived" value="1" @checked(!empty($filters['showreceived']))> Show Received Payments / Credits</label>
    </div>
    <button name="format" value="html">Create Report</button>
    <button name="format" value="csv">Download CSV</button>
</form>
