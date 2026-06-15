@php
    $fallbackLogoPath = $logoFallback ?? 'assets/logo.png';
    $reportLogoPath = !empty($officeProfile['logo_path']) ? public_path($officeProfile['logo_path']) : public_path($fallbackLogoPath);
    $contactParts = [];

    if (!empty($officeProfile['office_email'])) {
        $contactParts[] = 'Email: '.$officeProfile['office_email'];
    }

    if (!empty($officeProfile['office_phone'])) {
        $contactParts[] = 'Telp: '.$officeProfile['office_phone'];
    }
@endphp
<table class="office-header">
    <tr>
        <td class="office-logo-cell">
            @if (is_file($reportLogoPath))
                <img class="office-logo" src="{{ $reportLogoPath }}" alt="Logo Kantor" />
            @endif
        </td>
        <td class="office-body">
            <div class="office-kicker">{{ $officeProfile['header_label'] }}</div>
            <div class="office-name">{{ $officeProfile['office_name'] }}</div>
            @if (!empty($title))
                <div class="office-report-title">{{ $title }}</div>
            @endif
            <div class="office-address">{!! nl2br(e($officeProfile['office_address'])) !!}</div>
            @if (!empty($contactParts))
                <div class="office-contact">{{ implode(' | ', $contactParts) }}</div>
            @endif
        </td>
    </tr>
</table>
