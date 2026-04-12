<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rapport de Caisse</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f9fafb; padding: 20px; color: #111827;">

    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; border: 1px solid #e5e7eb;">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="margin: 0; color: #f97316; font-size: 24px;">{{ strtoupper($restaurant->name) }}</h1>
            <p style="margin: 5px 0 0; color: #6b7280; font-size: 14px;">Résumé de la Session #{{ $session->id }}</p>
        </div>

        <div style="margin-bottom: 25px;">
            <p><strong>Caissier :</strong> {{ $session->user->first_name }}</p>
            <p><strong>Date d'ouverture :</strong> {{ $session->opened_at ? $session->opened_at->format('d/m/Y H:i') : '' }}</p>
        </div>

        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">Recettes (Ventes)</td>
                <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right; font-weight: bold;">{{ number_format((float)$totalRevenue, 0, ',', ' ') }} FCFA</td>
            </tr>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; color: #ef4444;">Dépenses de caisse</td>
                <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right; font-weight: bold; color: #ef4444;">- {{ number_format((float)$totalExpenses, 0, ',', ' ') }} FCFA</td>
            </tr>
            <tr style="background-color: #f3f4f6;">
                <td style="padding: 15px 10px; font-weight: bold;">Montant Attendu</td>
                <td style="padding: 15px 10px; text-align: right; font-weight: bold;">{{ number_format((float)$session->expected_amount, 0, ',', ' ') }} FCFA</td>
            </tr>
        </table>

        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">Montant Remis Banquier</td>
                <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">{{ $session->amount_to_bank ? number_format((float)$session->amount_to_bank, 0, ',', ' ') : '0' }} FCFA</td>
            </tr>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">Fond de roulement</td>
                <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">{{ $session->remaining_amount ? number_format((float)$session->remaining_amount, 0, ',', ' ') : '0' }} FCFA</td>
            </tr>
            <tr style="background-color: {{ ($session->difference ?? 0) >= 0 ? '#ecfdf5' : '#fef2f2' }};">
                <td style="padding: 15px 10px; font-weight: bold; color: {{ ($session->difference ?? 0) >= 0 ? '#059669' : '#dc2626' }};">Écart de caisse</td>
                <td style="padding: 15px 10px; text-align: right; font-weight: bold; color: {{ ($session->difference ?? 0) >= 0 ? '#059669' : '#dc2626' }};">{{ number_format((float)($session->difference ?? 0), 0, ',', ' ') }} FCFA</td>
            </tr>
        </table>

        <div style="font-size: 11px; color: #9ca3af; text-align: center; margin-top: 40px; border-top: 1px solid #f3f4f6; padding-top: 20px;">
            Ce rapport a été généré automatiquement par SmartFlow POS.
        </div>
    </div>
</body>
</html>
