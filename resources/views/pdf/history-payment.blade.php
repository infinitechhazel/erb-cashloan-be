<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Schedule - Loan #{{ $loan->loan_number }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
        }
        .header h1 {
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        .loan-info {
            margin-bottom: 20px;
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
        }
        .loan-info-row {
            margin-bottom: 8px;
        }
        .loan-info-row:last-child {
            margin-bottom: 0;
        }
        .label {
            font-weight: bold;
            display: inline-block;
            width: 150px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th {
            background-color: #333;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .status-badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
            display: inline-block;
        }
        .status-paid {
            background-color: #d4edda;
            color: #155724;
        }
        .status-pending {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .status-overdue {
            background-color: #f8d7da;
            color: #721c24;
        }
        .status-missed {
            background-color: #e2e3e5;
            color: #383d41;
        }
        .summary {
            margin-top: 30px;
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
        }
        .summary-grid {
            display: table;
            width: 100%;
        }
        .summary-item {
            display: table-cell;
            text-align: center;
            padding: 10px;
        }
        .summary-item .number {
            font-size: 20px;
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
        }
        .summary-item .label {
            font-size: 11px;
            color: #666;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Payment Schedule</h1>
        <p>Loan #{{ $loan->loan_number }}</p>
    </div>

    <div class="loan-info">
        <div class="loan-info-row">
            <span class="label">Borrower:</span>
            <span>{{ $loan->borrower->name ?? 'N/A' }}</span>
        </div>
        @if($loan->lender)
        <div class="loan-info-row">
            <span class="label">Lender:</span>
            <span>{{ $loan->lender->name ?? 'N/A' }}</span>
        </div>
        @endif
        <div class="loan-info-row">
            <span class="label">Loan Type:</span>
            <span>{{ ucfirst($loan->type) }}</span>
        </div>
        <div class="loan-info-row">
            <span class="label">Loan Amount:</span>
            <span>₱{{ number_format($loan->amount, 2) }}</span>
        </div>
        <div class="loan-info-row">
            <span class="label">Interest Rate:</span>
            <span>{{ $loan->interest_rate }}%</span>
        </div>
        <div class="loan-info-row">
            <span class="label">Term:</span>
            <span>{{ $loan->term_months }} months</span>
        </div>
        <div class="loan-info-row">
            <span class="label">Monthly Payment:</span>
            <span>₱{{ number_format($loan->monthly_payment ?? ($loan->amount / $loan->term_months), 2) }}</span>
        </div>
        @if($loan->disbursement_date)
        <div class="loan-info-row">
            <span class="label">Disbursement Date:</span>
            <span>{{ \Carbon\Carbon::parse($loan->disbursement_date)->format('M d, Y') }}</span>
        </div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 10%;">#</th>
                <th style="width: 20%;">Due Date</th>
                <th style="width: 20%;">Amount</th>
                <th style="width: 20%;">Paid Date</th>
                <th style="width: 15%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payments as $payment)
            <tr>
                <td>{{ is_object($payment) ? $payment->payment_number : $payment['payment_number'] }}</td>
                <td>{{ \Carbon\Carbon::parse(is_object($payment) ? $payment->due_date : $payment['due_date'])->format('M d, Y') }}</td>
                <td>₱{{ number_format(is_object($payment) ? $payment->amount : $payment['amount'], 2) }}</td>
                <td>
                    @php
                        $paidDate = is_object($payment) ? $payment->paid_date : ($payment['paid_date'] ?? null);
                    @endphp
                    @if($paidDate)
                        {{ \Carbon\Carbon::parse($paidDate)->format('M d, Y') }}
                    @else
                        -
                    @endif
                </td>
                <td>
                    @php
                        $status = is_object($payment) ? $payment->status : $payment['status'];
                    @endphp
                    <span class="status-badge status-{{ $status }}">
                        {{ ucfirst($status) }}
                    </span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary">
        <div class="summary-grid">
            <div class="summary-item">
                <span class="number">{{ $summary['paid'] }}</span>
                <span class="label">Paid</span>
            </div>
            <div class="summary-item">
                <span class="number">{{ $summary['pending'] }}</span>
                <span class="label">Pending</span>
            </div>
            <div class="summary-item">
                <span class="number">{{ $summary['overdue'] }}</span>
                <span class="label">Overdue</span>
            </div>
            <div class="summary-item">
                <span class="number">{{ $summary['missed'] }}</span>
                <span class="label">Missed</span>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>Generated on {{ now()->format('F d, Y h:i A') }}</p>
        <p>This is a computer-generated document and does not require a signature.</p>
    </div>
</body>
</html>