<html>
<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 0;
            padding: 0;
        }

        /* Header */
        .header {
            width: 100%;
            position: relative;
            margin-bottom: 10px;
            height: 60px;
        }

        .header img.logo {
            position: absolute;
            left: 0;
            top: 0;
            width: 100px;
            height: auto;
        }

        .header .header-text {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            line-height: 1.2;
        }
        .subheader-text {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            line-height: 1.2;
        }

        .subheader {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-bottom: 30px;
        }

        table th {
            background: #e6e6e6;
            font-weight: bold;
            border: 1px solid #000;
            padding: 6px;
        }

        table td {
            border: 1px solid #000;
            padding: 6px;
        }

        .page-break {
            page-break-after: always;
        }

        /* Footer */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10px;
            border-top: 1px solid #000;
            padding: 5px 0;
        }

        .footer img {
            vertical-align: middle;
            width: 50px;
            height: auto;
            margin-right: 5px;
        }
    </style>
</head>

<body>

<!-- Header -->
<table style="width: 100%; border-collapse: collapse; border: none; height: 60px;">
    <tr>
        <td style="width: 10%; border: none;"></td>
        <td style="text-align: center; font-size: 20px; font-weight: bold; line-height: 1.2; border: none;">
            KENYA REVENUE AUTHORITY
        </td>
        <td style="width: 20%; text-align: right; font-size: 20px; font-weight: bold; line-height: 1.2; border: none;">
            | INTERNAL
        </td>
    </tr>
</table>

<div class="subheader-text">Facilities & Logistics Division | Transport Section</div>

<!-- Subheader -->
<div class="subheader">
    Motor Vehicle Repairs portal- Ticket Summary from <?= $start_date ?> to <?= $end_date ?>
</div>

<!-- Tickets Table -->
<?= $ticketsTableHtml ?>

<!-- Footer -->
<div class="footer">
    &copy; <?= date('Y') ?> KRA Vehicles Repair Portal. All rights reserved.
</div>

</body>
</html>
