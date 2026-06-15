<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportSetting extends Model
{
    use HasFactory;

    public const DEFAULT_NAME = 'default';

    protected $fillable = [
        'name',
        'logo_path',
        'header_label',
        'office_name',
        'office_address',
        'office_email',
        'office_phone',
        'office_city',
        'signatory_title',
        'signatory_name',
        'invoice_bank_account_1',
        'invoice_bank_account_2',
        'invoice_bank_account_3',
    ];

    public static function defaults(): array
    {
        return [
            'name' => self::DEFAULT_NAME,
            'logo_path' => null,
            'header_label' => 'KANTOR NOTARIS / PPAT',
            'office_name' => 'DEWANTARI HANDAYANI, S.H., M.P.A.',
            'office_address' => 'JL.Pondok Pinang Raya No.3, Kel.Pondok Pinang Jakarta Selatan',
            'office_email' => 'dewantari@notaris-jakarta.com',
            'office_phone' => '(021) 765 1859, 751 4828',
            'office_city' => 'Jakarta',
            'signatory_title' => 'Notaris / PPAT DKI Jakarta',
            'signatory_name' => 'Dewantari Handayani,S.H., M.P.A',
            'invoice_bank_account_1' => "BANK MANDIRI\nAcc:101-00-8188887-5\nA/n Dewantari Handayani",
            'invoice_bank_account_2' => "BANK BCA\nAcc:498-007-1974\nA/n Dewantari Handayani\nKCP Gd Hijau",
            'invoice_bank_account_3' => "BANK BRI\nAcc:0362-01-000422.30.5\nA/n Dewantari Handayani\nKCP Pondok Indah",
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['name' => self::DEFAULT_NAME],
            static::defaults(),
        );
    }

    public function toReportProfile(): array
    {
        $defaults = static::defaults();
        $attributes = $this->attributesToArray();

        return [
            'logo_path' => !empty($attributes['logo_path']) ? (string) $attributes['logo_path'] : '',
            'header_label' => (string) ($attributes['header_label'] ?? $defaults['header_label']),
            'office_name' => (string) ($attributes['office_name'] ?? $defaults['office_name']),
            'office_address' => (string) ($attributes['office_address'] ?? $defaults['office_address']),
            'office_email' => (string) ($attributes['office_email'] ?? $defaults['office_email']),
            'office_phone' => (string) ($attributes['office_phone'] ?? $defaults['office_phone']),
            'office_city' => (string) ($attributes['office_city'] ?? $defaults['office_city']),
            'signatory_title' => (string) ($attributes['signatory_title'] ?? $defaults['signatory_title']),
            'signatory_name' => (string) ($attributes['signatory_name'] ?? $defaults['signatory_name']),
            'invoice_bank_account_1' => (string) ($attributes['invoice_bank_account_1'] ?? $defaults['invoice_bank_account_1']),
            'invoice_bank_account_2' => (string) ($attributes['invoice_bank_account_2'] ?? $defaults['invoice_bank_account_2']),
            'invoice_bank_account_3' => (string) ($attributes['invoice_bank_account_3'] ?? $defaults['invoice_bank_account_3']),
        ];
    }
}
